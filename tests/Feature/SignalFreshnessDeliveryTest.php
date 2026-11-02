<?php

use App\Mail\StrategySignalAlertMail;
use App\Models\SignalDelivery;
use App\Models\Strategy;
use App\Services\SignalAlertService;
use App\Services\TradingService;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02T14:00:00Z'));
    Http::preventStrayRequests();
    Mail::fake();
    Cache::flush();
    Strategy::query()->delete();
    Strategy::create(['name' => 'Freshness test', 'slug' => 'freshness-test', 'description' => 'Test', 'price' => 0]);
    config([
        'trading.default_pair' => 'XAUUSD',
        'services.signal_alerts.admin_emails' => 'first@example.com',
        'services.signal_alerts.actions' => 'BUY,SELL',
    ]);
});

function freshnessDeliverySignal(): array
{
    return [
        'action' => 'BUY', 'symbol' => 'XAU/USD', 'symbol_code' => 'XAUUSD',
        'price' => 2000, 'timestamp' => now()->toIso8601String(),
        'expires_at' => now()->addMinutes(3)->toIso8601String(),
        'message' => 'Fresh closed-bar setup',
        'trade_plan' => ['entry_price' => 2000, 'stop_loss' => 1995, 'take_profit_1' => 2010],
        'freshness' => [
            'verified' => true, 'source' => 'twelvedata', 'interval_seconds' => 300,
            'data_as_of' => now()->toIso8601String(), 'observed_at' => now()->toIso8601String(),
            'generated_at' => now()->toIso8601String(), 'market_open' => true,
        ],
    ];
}

function freshnessDeliveryService(array $signal): SignalAlertService
{
    $trading = Mockery::mock(TradingService::class);
    $trading->shouldReceive('getSignalForStrategy')->with('freshness-test')->andReturn($signal);

    return new SignalAlertService($trading);
}

test('Sunday Manila dispatch skips a closed gold market before fetching even when forced', function (bool $force) {
    $this->travelTo(Carbon::parse('2026-11-01T13:10:00+08:00'));
    $trading = Mockery::mock(TradingService::class);
    $trading->shouldNotReceive('getSignalForStrategy');

    $result = (new SignalAlertService($trading))->dispatch(force: $force);

    expect($result['sent'])->toBe(0)->and($result['skipped'])->toBe(1)
        ->and($result['errors'])->toBe([])->and(SignalDelivery::count())->toBe(0);
    Mail::assertNothingSent();
    Http::assertNothingSent();
})->with([false, true]);

test('fresh alerts can resume after the Sunday New York reopening', function () {
    $this->travelTo(Carbon::parse('2026-11-02T00:00:00Z'));

    $result = freshnessDeliveryService(freshnessDeliverySignal())->dispatch();

    expect($result['sent'])->toBe(1)->and($result['errors'])->toBe([])
        ->and(SignalDelivery::sole()->status)->toBe('sent');
    Mail::assertSent(StrategySignalAlertMail::class, 1);
});

test('entry alerts without verifiable current market data never send or create attempts', function (string $defect, bool $force) {
    $signal = freshnessDeliverySignal();
    match ($defect) {
        'missing_freshness' => $signal['freshness'] = [],
        'unverified' => $signal['freshness']['verified'] = false,
        'stale_quote' => $signal['freshness']['observed_at'] = now()->subMinutes(5)->toIso8601String(),
        'stale_bar' => $signal['freshness']['data_as_of'] = now()->subDay()->toIso8601String(),
        'future_quote' => $signal['freshness']['observed_at'] = now()->addSecond()->toIso8601String(),
        'untrusted_source' => $signal['freshness']['source'] = 'synthetic',
        'expired' => $signal['expires_at'] = now()->toIso8601String(),
        'missing_expiry' => $signal['expires_at'] = null,
    };

    $result = freshnessDeliveryService($signal)->dispatch(force: $force);

    expect($result['sent'])->toBe(0)->and($result['skipped'])->toBe(1)
        ->and($result['errors'])->toBe([])->and(SignalDelivery::count())->toBe(0);
    Mail::assertNothingSent();
})->with(['missing_freshness', 'unverified', 'stale_quote', 'stale_bar', 'future_quote', 'untrusted_source', 'expired', 'missing_expiry'])->with([false, true]);

test('force can resend a current snapshot but cannot revive its expired provider quote', function () {
    $service = freshnessDeliveryService(freshnessDeliverySignal());
    expect($service->dispatch()['sent'])->toBe(1)
        ->and($service->dispatch(force: true)['sent'])->toBe(1);
    $this->travel(91)->seconds();

    $result = $service->dispatch(force: true);

    expect($result['sent'])->toBe(0)->and($result['skipped'])->toBe(1)
        ->and($result['errors'])->toBe([])->and(SignalDelivery::count())->toBe(2);
    Mail::assertSent(StrategySignalAlertMail::class, 2);
});

test('a stable candle signal ID suppresses changed quote plans while a fresh forced resend remains allowed', function () {
    $first = freshnessDeliverySignal();
    $first['signal_id'] = str_repeat('a', 64);
    $changed = $first;
    $changed['signal_id'] = strtoupper($first['signal_id']);
    $changed['price'] = 2000.1;
    $changed['trade_plan']['entry_price'] = 2000.1;
    $changed['message'] = 'Updated quote for the same closed-candle setup';

    expect(freshnessDeliveryService($first)->dispatch()['sent'])->toBe(1)
        ->and(freshnessDeliveryService($changed)->dispatch()['skipped'])->toBe(1)
        ->and(SignalDelivery::count())->toBe(1)
        ->and(freshnessDeliveryService($changed)->dispatch(force: true)['sent'])->toBe(1)
        ->and(SignalDelivery::count())->toBe(2)
        ->and(SignalDelivery::distinct()->count('signature'))->toBe(1)
        ->and(SignalDelivery::orderByDesc('id')->first()->signal_payload)->toBe($changed);
    Mail::assertSent(StrategySignalAlertMail::class, 2);
});

test('invalid signal IDs retain legacy snapshot duplicate handling', function () {
    $first = freshnessDeliverySignal();
    $first['signal_id'] = 'not-a-valid-candle-id';
    $changed = $first;
    $changed['price'] = 2000.1;
    $changed['trade_plan']['entry_price'] = 2000.1;

    expect(freshnessDeliveryService($first)->dispatch()['sent'])->toBe(1)
        ->and(freshnessDeliveryService($first)->dispatch()['skipped'])->toBe(1)
        ->and(freshnessDeliveryService($changed)->dispatch()['sent'])->toBe(1)
        ->and(SignalDelivery::distinct()->count('signature'))->toBe(2);
    Mail::assertSent(StrategySignalAlertMail::class, 2);
});

test('each recipient is revalidated after an earlier delivery consumes quote freshness', function (bool $force) {
    config(['services.signal_alerts.admin_emails' => 'first@example.com,second@example.com']);
    $pending = Mockery::mock(PendingMail::class);
    $pending->shouldReceive('send')->once()->andReturnUsing(function () {
        $this->travel(91)->seconds();
    });
    Mail::shouldReceive('to')->with('first@example.com')->once()->andReturn($pending);
    Mail::shouldReceive('to')->with('second@example.com')->never();

    $result = freshnessDeliveryService(freshnessDeliverySignal())->dispatch(force: $force);

    expect($result['sent'])->toBe(1)->and($result['errors'])->toBe([])
        ->and(SignalDelivery::count())->toBe(1)
        ->and(SignalDelivery::sole()->recipient)->toBe('first@example.com');
})->with([false, true]);

test('a quote that becomes stale while acquiring its recipient lock is not mailed', function () {
    $service = freshnessDeliveryService(freshnessDeliverySignal());
    $lock = new class
    {
        public function get(callable $callback): mixed
        {
            Carbon::setTestNow(now()->addSeconds(91));

            return $callback();
        }
    };
    Cache::shouldReceive('lock')->once()->andReturn($lock);

    $result = $service->dispatch(force: true);

    expect($result['sent'])->toBe(0)->and($result['skipped'])->toBe(1)
        ->and($result['errors'])->toBe([])->and(SignalDelivery::count())->toBe(0);
    Mail::assertNothingSent();
});

test('a persistence delay cannot send an expired quote and keeps its aborted audit', function (bool $force) {
    SignalDelivery::created(function () {
        Carbon::setTestNow(now()->addSeconds(91));
    });

    $result = freshnessDeliveryService(freshnessDeliverySignal())->dispatch(force: $force);

    expect($result['sent'])->toBe(0)->and($result['skipped'])->toBe(1)
        ->and($result['errors'])->toBe([])
        ->and(SignalDelivery::sole()->status)->toBe('failed')
        ->and(SignalDelivery::sole()->sent_at)->toBeNull()
        ->and(SignalDelivery::sole()->error_message)->toStartWith('Not sent: ');
    Mail::assertNothingSent();
})->with([false, true]);

test('market closure during evaluation is skipped quietly while open-market data errors remain visible', function () {
    $closed = Mockery::mock(TradingService::class);
    $closed->shouldReceive('getSignalForStrategy')->once()->andReturnUsing(function () {
        $this->travelTo(Carbon::parse('2026-11-06T22:00:00Z'));

        return ['action' => 'NO_DATA', 'symbol_code' => 'XAUUSD', 'error' => 'Market closed'];
    });
    $closedResult = (new SignalAlertService($closed))->dispatch();
    expect($closedResult['skipped'])->toBe(1)->and($closedResult['errors'])->toBe([]);

    $this->travelTo(Carbon::parse('2026-11-02T14:00:00Z'));
    $openResult = freshnessDeliveryService(['action' => 'NO_DATA', 'symbol_code' => 'XAUUSD', 'error' => 'Provider unavailable'])->dispatch();
    expect($openResult['sent'])->toBe(0)->and($openResult['skipped'])->toBe(1)
        ->and($openResult['errors'])->toHaveCount(1)
        ->and($openResult['errors'][0])->toContain('Provider unavailable')
        ->and(SignalDelivery::count())->toBe(0);
    Mail::assertNothingSent();
});
