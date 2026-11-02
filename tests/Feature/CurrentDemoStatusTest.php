<?php

use App\Models\AutoTradeOrder;
use App\Models\AutoTraderSetting;
use App\Services\AutoTraderService;
use App\Services\MarketDataGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02T12:15:20Z'));
    Http::preventStrayRequests();
    Mail::fake();
    config([
        'trading.default_pair' => 'XAUUSD', 'scalper.gold.source' => 'mt5', 'scalper.gold.version' => 'trend-session-v2',
        'trading.auto_trader.bridge_url' => 'http://bridge.test/trade', 'trading.auto_trader.bridge_token' => str_repeat('x', 32),
        'trading.auto_trader.symbols.cent' => 'XAUUSD-STD', 'trading.auto_trader.symbols.dollar' => 'XAUUSD-STD',
    ]);
    $this->mock(AutoTraderService::class)->shouldNotReceive('tick');
});

function currentDemoSnapshot(bool $cross = false): array
{
    $guard = new MarketDataGuard;
    $cursor = Carbon::parse('2026-11-02T12:10:00Z');
    $opens = [];
    while (count($opens) < 250) {
        if ($guard->marketStatus('XAUUSD', $cursor)['open']
            && $guard->marketStatus('XAUUSD', $cursor->copy()->addSeconds(299))['open']) {
            $opens[] = $cursor->toIso8601String();
        }
        $cursor->subMinutes(5);
    }
    $bars = [];
    foreach (array_reverse($opens) as $index => $open) {
        $i = $index - 47;
        $price = $cross ? 2000 + 0.01 * $i + 2 * sin(($i + 20) * 0.2) + ($i % 2 ? -0.8 : 0.8) : 2000;
        $bars[] = ['timestamp' => $open, 'open' => $price, 'high' => $price + 1, 'low' => $price - 1, 'close' => $price];
    }
    $price = $bars[array_key_last($bars)]['close'];

    return ['status' => 'ok', 'source' => 'mt5', 'account_key' => 'unit-test-account', 'symbol' => 'XAUUSD-STD',
        'observed_at' => now()->utc()->toIso8601String(), 'account' => ['demo' => true],
        'capabilities' => ['m5_candles', 'quote_timestamp', 'rsi_scalper_v2_demo_execution'],
        'm5' => $bars, 'quote' => ['bid' => $price, 'ask' => $price + .01, 'timestamp' => now()->utc()->toIso8601String()],
        'positions' => [], 'pending_orders' => []];
}

function currentDemoSettings(array $changes = []): AutoTraderSetting
{
    return AutoTraderSetting::create(array_replace([
        'id' => 1, 'enabled' => true, 'execution_mode' => 'bridge', 'strategy_slug' => 'rsi-gold-scalper-v2',
        'pair_code' => 'XAUUSD', 'mt5_symbol' => 'XAUUSD-STD', 'account_type' => 'dollar',
        'bridge_url' => 'http://bridge.test/trade', 'bridge_token' => str_repeat('x', 32),
    ], $changes));
}

function currentDemoDecision(array $snapshot, string $strategy = 'rsi-gold-scalper'): array
{
    Http::fake(['bridge.test/snapshot' => Http::response($snapshot)]);
    $exit = Artisan::call('signals:current', ['strategy' => $strategy]);

    return [$exit, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
}

test('current status distinguishes enabled verified demo automation from this read-only command', function (bool $cross, string $alias) {
    $settings = currentDemoSettings();
    $before = $settings->fresh()->getAttributes();

    [$exit, $result] = currentDemoDecision(currentDemoSnapshot($cross), $alias);

    expect($exit)->toBe(0)->and($result['action'])->toBe($cross ? 'BUY' : 'HOLD')
        ->and($result['demo_auto_trader_configured'])->toBeTrue()->and($result['automatic_execution_enabled'])->toBeTrue()
        ->and($result['command_places_orders'])->toBeFalse()->and($result['live_execution_enabled'])->toBeFalse()
        ->and($result['worker_process_health'])->toBe('not_checked')
        ->and($result['note'])->toContain('do not establish worker process health')
        ->and($result['can_enter'])->toBeFalse()->and($result['research_only'])->toBeTrue()
        ->and($result['orders_sent'])->toBe(0)->and($result['emails_sent'])->toBe(0)
        ->and($settings->fresh()->getAttributes())->toBe($before)->and(AutoTradeOrder::count())->toBe(0);
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    Mail::assertNothingSent();
})->with([false, true])->with(['rsi-gold-scalper', '  RSI-MA  ', 'rsi-gold-scalper-v2']);

test('current status does not create an auto trader setting when none exists', function () {
    expect(AutoTraderSetting::count())->toBe(0);
    [$exit, $result] = currentDemoDecision(currentDemoSnapshot());

    expect($exit)->toBe(0)->and($result['demo_auto_trader_configured'])->toBeFalse()
        ->and($result['automatic_execution_enabled'])->toBeFalse()->and(AutoTraderSetting::count())->toBe(0)
        ->and(AutoTradeOrder::count())->toBe(0)->and($result['command_places_orders'])->toBeFalse();
    Mail::assertNothingSent();
});

test('saved settings alone cannot verify demo execution against a live or unknown account', function ($demo) {
    currentDemoSettings();
    $snapshot = currentDemoSnapshot();
    if ($demo === null) {
        unset($snapshot['account']['demo']);
    } else {
        $snapshot['account']['demo'] = $demo;
    }
    [$exit, $result] = currentDemoDecision($snapshot);

    expect($exit)->toBe(0)->and($result['demo_auto_trader_configured'])->toBeTrue()
        ->and($result['automatic_execution_enabled'])->toBeFalse()->and($result['live_execution_enabled'])->toBeFalse()
        ->and($result['command_places_orders'])->toBeFalse()->and(AutoTradeOrder::count())->toBe(0);
})->with([false, null, 'true']);

test('disabled dry-run wrong-market and non-RSI settings are not configured RSI demo automation', function (array $changes) {
    currentDemoSettings($changes);
    [$exit, $result] = currentDemoDecision(currentDemoSnapshot());

    expect($exit)->toBe(0)->and($result['demo_auto_trader_configured'])->toBeFalse()
        ->and($result['automatic_execution_enabled'])->toBeFalse()->and($result['command_places_orders'])->toBeFalse();
})->with([
    [['enabled' => false]], [['execution_mode' => 'dry_run']], [['pair_code' => 'EURUSD']], [['strategy_slug' => 'gold-risk-breakout']],
]);

test('configured legacy or trend-only global rules do not claim enabled combined RSI execution', function (string $version) {
    config(['scalper.gold.version' => $version]);
    currentDemoSettings();
    [$exit, $result] = currentDemoDecision(currentDemoSnapshot());

    expect($exit)->toBe(0)->and($result['demo_auto_trader_configured'])->toBeFalse()
        ->and($result['automatic_execution_enabled'])->toBeFalse();
})->with(['legacy', 'trend-v2']);

test('an expired source quote cannot claim automatic execution is currently verified', function () {
    currentDemoSettings();
    $snapshot = currentDemoSnapshot();
    $snapshot['quote']['timestamp'] = now()->utc()->subSeconds(91)->toIso8601String();
    [$exit, $result] = currentDemoDecision($snapshot);

    expect($exit)->toBe(1)->and($result['state'])->toBe('NO_VERIFIED_SETUP')
        ->and($result['demo_auto_trader_configured'])->toBeTrue()->and($result['automatic_execution_enabled'])->toBeFalse()
        ->and($result['command_places_orders'])->toBeFalse()->and($result['live_execution_enabled'])->toBeFalse()
        ->and($result['source_quote'])->toBeNull()->and(AutoTradeOrder::count())->toBe(0);
    Http::assertSentCount(1);
    Mail::assertNothingSent();
});
