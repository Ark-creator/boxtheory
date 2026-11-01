<?php

use App\Mail\StrategySignalAlertMail;
use App\Models\AutoTradeOrder;
use App\Models\Strategy;
use App\Services\AutoTraderService;
use App\Services\Mt5BridgeClient;
use App\Services\SignalAlertService;
use App\Services\TradingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function readyGoldSignal(): array
{
    return ['action' => 'BUY', 'timestamp' => now()->toIso8601String(), 'expires_at' => now()->addMinutes(3)->toIso8601String(),
        'message' => 'Test breakout', 'price' => 2000, 'trade_plan' => ['entry_price' => 2000, 'stop_loss' => 1995, 'take_profit_1' => 2010, 'risk_per_unit' => 5, 'entry_limit_price' => 2000.25],
        'broker' => ['account_key' => 'test-account', 'symbol' => 'XAUUSD']];
}

test('dry run preserves emails and never submits an MT5 order', function () {
    Http::preventStrayRequests();
    Mail::fake();
    config(['services.signal_alerts.admin_emails' => 'test@example.com']);
    $trading = Mockery::mock(TradingService::class);
    $trading->shouldReceive('getSignalForStrategy')->andReturn(readyGoldSignal());
    $service = new AutoTraderService($trading);
    $settings = $service->settings();
    $settings->update(['enabled' => true, 'execution_mode' => 'dry_run', 'strategy_slug' => 'gold-risk-breakout', 'mt5_symbol' => 'XAUUSD', 'cooldown_minutes' => 0]);
    expect($service->tick()['status'])->toBe('dry_run');
    expect($service->tick()['status'])->toBe('duplicate');
    expect(AutoTradeOrder::count())->toBe(1);
    $result = (new SignalAlertService($trading))->dispatch();
    expect($result['sent'])->toBeGreaterThan(0);
    Mail::assertSent(StrategySignalAlertMail::class, fn ($mail) => $mail->signal['trade_plan']['stop_loss'] === 1995 && $mail->signal['trade_plan']['take_profit_1'] === 2010);
    Http::assertNothingSent();
});

test('broker failure is never promoted to success and timeouts remain unresolved', function () {
    $service = Mockery::mock(AutoTraderService::class, [app(TradingService::class)])->makePartial();
    $service->shouldReceive('latestSignal')->andReturn(readyGoldSignal());
    $settings = $service->settings();
    $settings->update(['enabled' => true, 'execution_mode' => 'bridge', 'strategy_slug' => 'gold-risk-breakout', 'mt5_symbol' => 'XAUUSD', 'risk_percent_per_trade' => 0.25, 'bridge_url' => 'http://bridge.test/trade', 'bridge_token' => str_repeat('x', 32)]);
    $this->mock(Mt5BridgeClient::class, function ($mock) {
        $mock->shouldReceive('snapshot')->andReturn(['positions' => [], 'pending_orders' => [], 'account_key' => 'test-account', 'symbol' => 'XAUUSD']);
    });
    Http::fake(['bridge.test/*' => Http::response(['status' => 'failed', 'message' => 'Broker rejected'], 200)]);
    expect($service->tick()['status'])->toBe('failed');
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['entry_limit_price'] === 2000.25);
    expect((float) AutoTradeOrder::first()->decision_payload['signal']['trade_plan']['entry_limit_price'])->toBe(2000.25);
    Http::fake(['bridge.test/*' => Http::failedConnection()]);
    expect($service->tick()['status'])->toBe('unknown');
    expect($service->tick()['status'])->toBe('rejected');
});

test('a broker position blocks entry even with an empty local order history', function () {
    $service = Mockery::mock(AutoTraderService::class, [app(TradingService::class)])->makePartial();
    $service->shouldReceive('latestSignal')->andReturn(readyGoldSignal());
    $settings = $service->settings();
    $settings->update(['enabled' => true, 'execution_mode' => 'bridge', 'strategy_slug' => 'gold-risk-breakout', 'mt5_symbol' => 'XAUUSD', 'risk_percent_per_trade' => 0.25]);
    $this->mock(Mt5BridgeClient::class, function ($mock) {
        $mock->shouldReceive('snapshot')->andReturn(['positions' => [['symbol' => 'EURUSD', 'magic' => 0]], 'pending_orders' => []]);
    });
    Http::fake();
    expect($service->tick()['status'])->toBe('rejected');
    Http::assertNothingSent();
});

test('new gold strategy is installed by migration', function () {
    expect(Strategy::where('slug', 'gold-risk-breakout')->exists())->toBeTrue();
});

test('reconciliation recovers a confirmed fill after a lost HTTP response', function () {
    $service = new AutoTraderService(app(TradingService::class));
    $settings = $service->settings();
    $settings->update(['bridge_url' => 'http://bridge.test/trade', 'bridge_token' => str_repeat('x', 32)]);
    $order = AutoTradeOrder::create(['uuid' => (string) Illuminate\Support\Str::uuid(), 'signal_signature' => str_repeat('a', 64), 'strategy_slug' => 'gold-risk-breakout', 'pair_code' => 'XAUUSD', 'mt5_symbol' => 'XAUUSD', 'execution_mode' => 'bridge', 'action' => 'BUY', 'status' => 'unknown']);
    Http::fake(['bridge.test/orders/*' => Http::response(['status' => 'executed', 'order' => 123, 'deal' => 456], 200)]);
    expect($service->reconcile($settings))->toBe(1);
    expect($order->fresh()->status)->toBe('executed');
    Http::assertSent(fn ($request) => $request->method() === 'GET');
});
