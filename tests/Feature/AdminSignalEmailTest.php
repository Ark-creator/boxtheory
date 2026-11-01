<?php

use App\Mail\StrategySignalAlertMail;
use App\Models\Strategy;
use App\Services\SignalAlertService;
use App\Services\TradingService;
use Database\Seeders\StrategyCatalogSeeder;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Http::preventStrayRequests();
    Mail::fake();
    Cache::flush();
    Strategy::query()->delete();
    config([
        'services.signal_alerts.admin_emails' => 'romark7bayan@gmail.com',
        'services.signal_alerts.actions' => 'BUY,SELL',
    ]);
});

function adminEntrySignal(string $action = 'BUY'): array
{
    return [
        'action' => $action,
        'symbol' => 'XAU/USD',
        'symbol_code' => 'XAUUSD',
        'timestamp' => now()->toIso8601String(),
        'expires_at' => now()->addMinutes(3)->toIso8601String(),
        'price' => 2000,
        'message' => 'Confirmed entry setup',
        'trade_plan' => ['entry_price' => 2000, 'stop_loss' => 1995, 'take_profit_1' => 2010],
    ];
}

function adminEmailStrategy(string $slug = 'gold-risk-breakout'): Strategy
{
    return Strategy::create(['name' => $slug, 'slug' => $slug, 'description' => 'Email test', 'price' => 0]);
}

test('every catalog strategy emails BUY and SELL setups to the configured admin', function (string $action) {
    $this->seed(StrategyCatalogSeeder::class);
    $trading = Mockery::mock(TradingService::class);
    foreach (Strategy::all() as $strategy) {
        $trading->shouldReceive('getSignalForStrategy')->with($strategy->slug)->once()->andReturn(adminEntrySignal($action));
    }

    $result = (new SignalAlertService($trading))->dispatch();

    expect($result['sent'])->toBe(4)->and($result['errors'])->toBe([]);
    Mail::assertSent(StrategySignalAlertMail::class, 4);
    foreach (Strategy::all() as $strategy) {
        Mail::assertSent(StrategySignalAlertMail::class, fn ($mail) =>
            $mail->hasTo('romark7bayan@gmail.com') && $mail->strategy->id === $strategy->id && $mail->signal['action'] === $action);
    }
})->with(['BUY', 'SELL']);

test('entry alerts skip non-entry actions', function (string $action) {
    adminEmailStrategy();
    $trading = Mockery::mock(TradingService::class);
    $trading->shouldReceive('getSignalForStrategy')->andReturn(adminEntrySignal($action));

    expect((new SignalAlertService($trading))->dispatch()['sent'])->toBe(0);
    Mail::assertNothingSent();
})->with(['WAIT', 'HOLD', 'CLOSE', 'NO_DATA']);

test('a failed earlier recipient cannot block the admin and only failed deliveries are retried', function () {
    adminEmailStrategy();
    config(['services.signal_alerts.admin_emails' => 'failed@example.com,romark7bayan@gmail.com']);
    $pending = Mockery::mock(PendingMail::class);
    $pending->shouldReceive('send')->with(Mockery::type(StrategySignalAlertMail::class))->twice();
    $attempts = 0;
    Mail::shouldReceive('to')->with('failed@example.com')->twice()->andReturnUsing(function () use (&$attempts, $pending) {
        if (++$attempts === 1) {
            throw new RuntimeException('Temporary SMTP failure');
        }

        return $pending;
    });
    Mail::shouldReceive('to')->with('romark7bayan@gmail.com')->once()->andReturn($pending);
    $trading = Mockery::mock(TradingService::class);
    $trading->shouldReceive('getSignalForStrategy')->andReturn(adminEntrySignal());
    $service = new SignalAlertService($trading);

    $first = $service->dispatch();
    expect($first['sent'])->toBe(1)->and($first['errors'])->toHaveCount(1);
    expect($service->dispatch()['errors'])->toBe([]);
    expect($service->dispatch()['skipped'])->toBe(1);
});

test('identical snapshots are suppressed while new signals and new recipients are delivered', function () {
    adminEmailStrategy();
    $signal = adminEntrySignal();
    $trading = Mockery::mock(TradingService::class);
    $trading->shouldReceive('getSignalForStrategy')->andReturnUsing(fn () => $signal);
    $service = new SignalAlertService($trading);
    $service->dispatch();
    expect($service->dispatch()['skipped'])->toBe(1);
    config(['services.signal_alerts.admin_emails' => 'romark7bayan@gmail.com,new@example.com']);
    $service->dispatch();
    Mail::assertSent(StrategySignalAlertMail::class, 2);
    Mail::assertSent(StrategySignalAlertMail::class, fn ($mail) => $mail->hasTo('new@example.com'));

    $changedTrading = Mockery::mock(TradingService::class);
    $changedTrading->shouldReceive('getSignalForStrategy')->andReturn(adminEntrySignal('SELL'));
    (new SignalAlertService($changedTrading))->dispatch();
    Mail::assertSent(StrategySignalAlertMail::class, 4);
});

test('expired entry signals are skipped even for a forced dispatch', function () {
    adminEmailStrategy();
    $signal = adminEntrySignal();
    $signal['expires_at'] = now()->subSecond()->toIso8601String();
    $trading = Mockery::mock(TradingService::class);
    $trading->shouldReceive('getSignalForStrategy')->andReturn($signal);

    expect((new SignalAlertService($trading))->dispatch(force: true)['skipped'])->toBe(1);
    Mail::assertNothingSent();
});

test('a strategy data failure does not prevent another strategy email', function () {
    adminEmailStrategy('unavailable');
    adminEmailStrategy('box-theory');
    $trading = Mockery::mock(TradingService::class);
    $trading->shouldReceive('getSignalForStrategy')->with('unavailable')->andThrow(new RuntimeException('Data unavailable'));
    $trading->shouldReceive('getSignalForStrategy')->with('box-theory')->andReturn(adminEntrySignal());

    $result = (new SignalAlertService($trading))->dispatch();

    expect($result['sent'])->toBe(1)->and($result['errors'])->toHaveCount(1);
    Mail::assertSent(StrategySignalAlertMail::class, fn ($mail) => $mail->strategy->slug === 'box-theory');
});

test('entry emails identify the market and include entry stop target and expiry', function () {
    $signal = adminEntrySignal();
    $mail = new StrategySignalAlertMail(adminEmailStrategy(), $signal, now());

    expect($mail->envelope()->subject)->toContain('BUY XAU/USD', 'gold-risk-breakout');
    $mail->assertSeeInHtml('XAU/USD');
    $mail->assertSeeInHtml('2,000.0000');
    $mail->assertSeeInHtml('1,995.0000');
    $mail->assertSeeInHtml('2,010.0000');
    $mail->assertSeeInHtml($signal['expires_at']);
});

test('the email command reports delivery failures with a failing exit code', function () {
    $service = Mockery::mock(SignalAlertService::class);
    $service->shouldReceive('dispatch')->with(false, null)->once()->andReturn([
        'strategies' => 1, 'sent' => 0, 'skipped' => 0, 'recipients' => 1, 'errors' => ['SMTP unavailable'],
    ]);
    $this->app->instance(SignalAlertService::class, $service);

    $this->artisan('signals:email-admin')->expectsOutput('SMTP unavailable')->assertExitCode(1);
});

test('a recipient override delivers only to the requested admin', function () {
    adminEmailStrategy();
    config(['services.signal_alerts.admin_emails' => 'someone-else@example.com']);
    $trading = Mockery::mock(TradingService::class);
    $trading->shouldReceive('getSignalForStrategy')->andReturn(adminEntrySignal());
    $this->app->instance(TradingService::class, $trading);

    $this->artisan('signals:email-admin', ['--to' => 'romark7bayan@gmail.com'])->assertExitCode(0);
    Mail::assertSent(StrategySignalAlertMail::class, 1);
    Mail::assertSent(StrategySignalAlertMail::class, fn ($mail) => $mail->hasTo('romark7bayan@gmail.com') && !$mail->hasTo('someone-else@example.com'));
});

test('an invalid recipient override never falls back to other recipients', function () {
    adminEmailStrategy();
    $trading = Mockery::mock(TradingService::class);
    $trading->shouldNotReceive('getSignalForStrategy');
    $this->app->instance(TradingService::class, $trading);

    $this->artisan('signals:email-admin', ['--to' => 'invalid'])->assertExitCode(1);
    Mail::assertNothingSent();
});

test('an overlapping dispatch cannot send the same signal twice', function () {
    adminEmailStrategy();
    $trading = Mockery::mock(TradingService::class);
    $trading->shouldReceive('getSignalForStrategy')->andReturn(adminEntrySignal());
    $service = new SignalAlertService($trading);
    $pending = Mockery::mock(PendingMail::class);
    $pending->shouldReceive('send')->once();
    Mail::shouldReceive('to')->with('romark7bayan@gmail.com')->once()->andReturnUsing(function () use ($service, $pending) {
        expect($service->dispatch()['skipped'])->toBe(1);

        return $pending;
    });

    expect($service->dispatch()['sent'])->toBe(1);
});
