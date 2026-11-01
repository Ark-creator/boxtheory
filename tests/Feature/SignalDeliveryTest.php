<?php

use App\Mail\StrategySignalAlertMail;
use App\Models\SignalDelivery;
use App\Models\Strategy;
use App\Services\SignalAlertService;
use App\Services\TradingService;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->freezeTime();
    Http::preventStrayRequests();
    Mail::fake();
    Cache::flush();
    Strategy::query()->delete();
    config([
        'services.signal_alerts.admin_emails' => 'admin@example.com',
        'services.signal_alerts.actions' => 'BUY,SELL',
    ]);
});

function historyTestSignal(string $action = 'BUY'): array
{
    return [
        'action' => $action,
        'symbol' => 'XAU/USD',
        'symbol_code' => 'XAUUSD',
        'timestamp' => now()->toIso8601String(),
        'expires_at' => now()->addMinutes(3)->toIso8601String(),
        'price' => 2000,
        'message' => 'Confirmed entry setup',
        'indicators' => ['rsi' => 61.5, 'source' => 'test'],
        'trade_plan' => [
            'entry_price' => 2000, 'stop_loss' => 1995,
            'take_profit_1' => 2010, 'take_profit_2' => 2020,
        ],
    ];
}

function historyTestStrategy(): Strategy
{
    return Strategy::create([
        'name' => 'Original strategy', 'slug' => 'history-test',
        'description' => 'History test', 'price' => 0,
    ]);
}

function historyTestService(array $signal): SignalAlertService
{
    $trading = Mockery::mock(TradingService::class);
    $trading->shouldReceive('getSignalForStrategy')->andReturn($signal);

    return new SignalAlertService($trading);
}

test('history preserves the exact signal and recipient after a strategy changes or is deleted', function () {
    $strategy = historyTestStrategy();
    $signal = historyTestSignal();

    historyTestService($signal)->dispatch();

    $delivery = SignalDelivery::sole();
    expect($delivery->signal_payload)->toBe($signal)
        ->and($delivery->strategy_name)->toBe('Original strategy')
        ->and($delivery->strategy_slug)->toBe('history-test')
        ->and($delivery->pair_code)->toBe('XAUUSD')
        ->and($delivery->action)->toBe('BUY')
        ->and($delivery->recipient)->toBe('admin@example.com')
        ->and($delivery->status)->toBe('sent')
        ->and($delivery->mailer)->toBe('array')
        ->and($delivery->forced)->toBeFalse()
        ->and($delivery->attempted_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($delivery->sent_at->toDateTimeString())->toBe(now()->toDateTimeString());

    $strategy->update(['name' => 'Renamed strategy']);
    $strategy->delete();
    $delivery->refresh();
    expect($delivery->strategy_id)->toBeNull()
        ->and($delivery->strategy_name)->toBe('Original strategy')
        ->and($delivery->signal_payload)->toBe($signal);
});

test('saved history suppresses duplicates after cache clearing and keeps forced resends separately', function () {
    historyTestStrategy();
    $service = historyTestService(historyTestSignal());

    expect($service->dispatch()['sent'])->toBe(1);
    Cache::flush();
    expect($service->dispatch()['skipped'])->toBe(1)
        ->and(SignalDelivery::count())->toBe(1);
    expect($service->dispatch(force: true)['sent'])->toBe(1);

    Mail::assertSent(StrategySignalAlertMail::class, 2);
    expect(SignalDelivery::count())->toBe(2)
        ->and(SignalDelivery::orderBy('id')->first()->forced)->toBeFalse()
        ->and(SignalDelivery::orderByDesc('id')->first()->forced)->toBeTrue()
        ->and(SignalDelivery::distinct()->count('signature'))->toBe(1);
});

test('failed and successful recipients and retries each retain their own history', function () {
    historyTestStrategy();
    config(['services.signal_alerts.admin_emails' => 'failed@example.com,admin@example.com']);
    $pending = Mockery::mock(PendingMail::class);
    $pending->shouldReceive('send')->with(Mockery::type(StrategySignalAlertMail::class))->twice();
    $attempts = 0;
    Mail::shouldReceive('to')->with('failed@example.com')->twice()->andReturnUsing(function () use (&$attempts, $pending) {
        if (++$attempts === 1) {
            throw new RuntimeException('Temporary SMTP failure');
        }

        return $pending;
    });
    Mail::shouldReceive('to')->with('admin@example.com')->once()->andReturn($pending);
    $service = historyTestService(historyTestSignal());

    $first = $service->dispatch();
    expect($first['sent'])->toBe(1)->and($first['errors'])->toHaveCount(1);
    expect($service->dispatch()['errors'])->toBe([]);
    expect($service->dispatch()['skipped'])->toBe(1);

    $failed = SignalDelivery::where('status', 'failed')->sole();
    expect(SignalDelivery::count())->toBe(3)
        ->and($failed->recipient)->toBe('failed@example.com')
        ->and($failed->error_message)->toBe('Temporary SMTP failure')
        ->and($failed->sent_at)->toBeNull()
        ->and($failed->signal_payload)->toBe(historyTestSignal())
        ->and(SignalDelivery::where('status', 'sent')->count())->toBe(2);
});

test('non-entry and expired signals do not create delivery history', function (string $action, bool $expired) {
    historyTestStrategy();
    $signal = historyTestSignal($action);
    if ($expired) {
        $signal['expires_at'] = now()->subSecond()->toIso8601String();
    }

    expect(historyTestService($signal)->dispatch(force: true)['sent'])->toBe(0)
        ->and(SignalDelivery::count())->toBe(0);
    Mail::assertNothingSent();
})->with([
    ['HOLD', false], ['WAIT', false], ['CLOSE', false], ['NO_DATA', false], ['BUY', true],
]);

test('an email is not sent if its initial history record cannot be saved', function () {
    historyTestStrategy();
    SignalDelivery::creating(function () {
        throw new RuntimeException('Signal history unavailable');
    });

    $result = historyTestService(historyTestSignal())->dispatch();

    expect($result['sent'])->toBe(0)
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0])->toContain('Signal history unavailable')
        ->and(SignalDelivery::count())->toBe(0);
    Mail::assertNothingSent();
});

test('a failed history update after mail acceptance stays unconfirmed without resending on the next poll', function () {
    historyTestStrategy();
    SignalDelivery::updating(function (SignalDelivery $delivery) {
        if ($delivery->status === 'sent') {
            throw new RuntimeException('History update unavailable');
        }
    });
    $service = historyTestService(historyTestSignal());

    $result = $service->dispatch();

    expect($result['sent'])->toBe(1)
        ->and($result['errors'])->toHaveCount(1)
        ->and(SignalDelivery::sole()->status)->toBe('pending')
        ->and(SignalDelivery::sole()->sent_at)->toBeNull()
        ->and($service->dispatch()['skipped'])->toBe(1)
        ->and(SignalDelivery::count())->toBe(1);
    Mail::assertSent(StrategySignalAlertMail::class, 1);
});
