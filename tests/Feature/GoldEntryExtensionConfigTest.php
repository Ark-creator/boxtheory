<?php

use App\Services\AutoTraderService;
use App\Services\GoldBreakoutStrategy;
use App\Services\GoldStrategyBacktester;
use App\Services\Mt5BridgeClient;
use Illuminate\Support\Facades\Http;

test('signal evaluator and historical runner share the configured entry cap', function () {
    config(['gold_breakout.max_entry_extension_atr' => '0.4']);
    expect(app(GoldBreakoutStrategy::class)->maxEntryExtensionAtr)->toBe(0.4);

    $bar = ['timestamp' => '2026-09-14T10:00:00Z', 'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000];
    $report = app(GoldStrategyBacktester::class)->run(['source' => 'mt5', 'm15' => [$bar], 'h1' => [$bar]], 0, 0, 0);
    expect($report['parameters']['max_entry_extension_atr'])->toBe(0.4);
});

test('invalid entry cap configuration fails closed', function ($value) {
    config(['gold_breakout.max_entry_extension_atr' => $value]);
    expect(fn () => app(GoldBreakoutStrategy::class))->toThrow(InvalidArgumentException::class);
})->with(['blank' => '', 'text' => 'invalid', 'zero' => 0, 'negative' => -0.75, 'infinite' => INF, 'not a number' => NAN]);

test('older bridges cannot silently bypass the executable entry limit', function () {
    $settings = app(AutoTraderService::class)->settings();
    $settings->update(['bridge_url' => 'http://bridge.test/trade', 'bridge_token' => str_repeat('x', 32), 'mt5_symbol' => 'XAUUSD']);
    $snapshot = ['status' => 'ok', 'source' => 'mt5', 'account_key' => 'test-account', 'symbol' => 'XAUUSD', 'observed_at' => now()->toIso8601String()];
    Http::fake(['bridge.test/snapshot' => Http::sequence()
        ->push($snapshot)
        ->push($snapshot + ['capabilities' => ['entry_extension_limit']])]);
    expect(fn () => app(Mt5BridgeClient::class)->snapshot($settings))->toThrow(RuntimeException::class, 'Update the MT5 bridge');
    expect(app(Mt5BridgeClient::class)->snapshot($settings)['capabilities'])->toBe(['entry_extension_limit']);
});
