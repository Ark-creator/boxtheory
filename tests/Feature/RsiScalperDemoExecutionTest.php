<?php

use App\Models\AutoTradeOrder;
use App\Services\AutoTraderService;
use App\Services\MarketDataGuard;
use App\Services\TradingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02T12:15:20Z'));
    Cache::flush();
    Http::preventStrayRequests();
    Mail::fake();
    config(['trading.default_pair' => 'XAUUSD', 'scalper.gold.source' => 'mt5', 'scalper.gold.version' => 'trend-session-v2']);
});

function rsiDemoSnapshot(bool $sell = false): array
{
    $guard = new MarketDataGuard;
    $cursor = Carbon::createFromTimestampUTC(intdiv(now()->timestamp, 300) * 300)->subMinutes(5);
    $times = [];
    while (count($times) < 320) {
        if ($guard->marketStatus('XAUUSD', $cursor)['open'] && $guard->marketStatus('XAUUSD', $cursor->copy()->addSeconds(299))['open']) {
            $times[] = $cursor->toIso8601String();
        }
        $cursor->subMinutes(5);
    }
    $bars = [];
    foreach (array_reverse($times) as $index => $time) {
        $i = $index - 117;
        $price = 2000 + 0.01 * $i + 2 * sin(($i + 20) * 0.2) + ($i % 2 ? -0.8 : 0.8);
        if ($sell) {
            $price = 4000 - $price;
        }
        $bars[] = ['timestamp' => $time, 'open' => $price, 'high' => $price + 1, 'low' => $price - 1, 'close' => $price];
    }
    $price = $bars[array_key_last($bars)]['close'];

    return ['status' => 'ok', 'source' => 'mt5', 'observed_at' => now()->toIso8601String(),
        'account_key' => 'pinned-demo-account', 'account' => ['demo' => true], 'symbol' => 'XAUUSD-STD',
        'capabilities' => ['m5_candles', 'quote_timestamp', 'rsi_scalper_v2_demo_execution'], 'positions' => [], 'pending_orders' => [],
        'm5' => $bars, 'quote' => ['bid' => $price - 0.05, 'ask' => $price + 0.05, 'timestamp' => now()->toIso8601String()]];
}

function rsiDemoSettings(AutoTraderService $service, string $slug = 'rsi-gold-scalper'): App\Models\AutoTraderSetting
{
    $settings = $service->settings();
    $settings->update(['enabled' => true, 'execution_mode' => 'bridge', 'pair_code' => 'XAUUSD', 'strategy_slug' => $slug,
        'mt5_symbol' => 'XAUUSD-STD', 'risk_percent_per_trade' => 0.25, 'cooldown_minutes' => 0,
        'bridge_url' => 'http://demo-bridge.test/trade', 'bridge_token' => str_repeat('d', 32)]);

    return $settings;
}

function rsiDemoFake(array $snapshot): void
{
    Http::fake(['demo-bridge.test/snapshot' => Http::response($snapshot),
        'demo-bridge.test/trade' => Http::response(['status' => 'executed', 'order' => 123, 'deal' => 456, 'volume' => 0.03])]);
}

function rsiDemoAssertNoSubmission(int $requests = 1): void
{
    Http::assertSentCount($requests);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    Mail::assertNothingSent();
}

test('RSI demo execution submits a genuine fixed BUY or SELL from exactly one shared snapshot', function (bool $sell, string $slug) {
    $snapshot = rsiDemoSnapshot($sell);
    $expected = app(TradingService::class)->evaluateRsiMt5Snapshot($snapshot, null, 'trend-session-v2');
    $trading = Mockery::mock(TradingService::class)->makePartial();
    $trading->shouldReceive('evaluateRsiMt5Snapshot')->once()->with($snapshot, null, 'trend-session-v2')->passthru();
    $trading->shouldNotReceive('getSignalForStrategy');
    $service = new AutoTraderService($trading);
    rsiDemoSettings($service, $slug);
    rsiDemoFake($snapshot);

    $result = $service->tick();
    expect($result['status'])->toBe('executed')->and($result['signal']['action'])->toBe($sell ? 'SELL' : 'BUY')
        ->and($result['order']->strategy_slug)->toBe('rsi-gold-scalper-v2')->and((float) $result['order']->volume)->toBe(0.03)
        ->and($result['signal']['research_only'])->toBeTrue()->and(AutoTradeOrder::count())->toBe(1);
    Http::assertSentCount(2);
    Http::assertSent(function ($request) use ($expected, $sell) {
        if ($request->method() !== 'POST') {
            return false;
        }
        $payload = $request->data();

        return $request->url() === 'http://demo-bridge.test/trade' && $payload['action'] === ($sell ? 'sell' : 'buy')
            && $payload['strategy'] === 'rsi-gold-scalper-v2' && $payload['strategy_slug'] === 'rsi-gold-scalper-v2'
            && $payload['strategy_version'] === 2 && $payload['exit_policy'] === 'full_tp1' && $payload['demo_only'] === true
            && $payload['freshness'] === $expected['freshness'] && $payload['account_key'] === 'pinned-demo-account'
            && $payload['entry_price'] === $expected['trade_plan']['entry_price']
            && $payload['stop_loss'] === $expected['trade_plan']['stop_loss'] && $payload['take_profit_1'] === $expected['trade_plan']['take_profit_1']
            && $payload['take_profit_2'] === null && $payload['risk_percent'] === 0.25 && ! array_key_exists('volume', $payload);
    });
    Mail::assertNothingSent();
})->with([false, true])->with(['rsi-gold-scalper-v2', 'rsi-gold-scalper', 'rsi-moving-average', 'rsi-moving-averages', 'rsi-ma', 'rsi-ma-method', 'rsi-scalper']);

test('RSI demo execution never accepts a live unverified or mismatched broker snapshot', function (string $fault) {
    $service = app(AutoTraderService::class);
    rsiDemoSettings($service);
    $snapshot = rsiDemoSnapshot();
    match ($fault) {
        'live' => $snapshot['account']['demo'] = false,
        'demo missing' => $snapshot['account'] = [],
        'demo string' => $snapshot['account']['demo'] = 'true',
        'wrong source' => $snapshot['source'] = 'api',
        'wrong symbol' => $snapshot['symbol'] = 'EURUSD',
        'missing account' => $snapshot['account_key'] = '',
        'missing positions' => $snapshot['positions'] = null,
        'missing pending orders' => $snapshot['pending_orders'] = null,
        'old bridge' => $snapshot['capabilities'] = ['m5_candles', 'quote_timestamp'],
    };
    rsiDemoFake($snapshot);
    expect($service->tick()['status'])->toBe('rejected')->and(AutoTradeOrder::count())->toBe(0);
    rsiDemoAssertNoSubmission();
})->with(['live', 'demo missing', 'demo string', 'wrong source', 'wrong symbol', 'missing account', 'missing positions', 'missing pending orders', 'old bridge']);

test('API legacy trend-only and unsupported RSI execution are rejected before a broker fetch', function (string $fault) {
    $service = app(AutoTraderService::class);
    $settings = rsiDemoSettings($service);
    match ($fault) {
        'API source' => config(['scalper.gold.source' => 'twelvedata']),
        'legacy' => config(['scalper.gold.version' => 'legacy']),
        'trend only' => config(['scalper.gold.version' => 'trend-v2']),
        'invalid version' => config(['scalper.gold.version' => 'invalid']),
        'FX' => $settings->update(['pair_code' => 'EURUSD']),
        'unknown alias' => $settings->update(['strategy_slug' => 'rsi-unregistered-model']),
    };
    expect($service->tick()['status'])->toBe('rejected')->and(AutoTradeOrder::count())->toBe(0);
    rsiDemoAssertNoSubmission(0);
})->with(['API source', 'legacy', 'trend only', 'invalid version', 'FX', 'unknown alias']);

test('resolved RSI signal cannot bypass actual V2 provenance or its original fixed bracket', function (string $fault) {
    $snapshot = rsiDemoSnapshot();
    $signal = app(TradingService::class)->evaluateRsiMt5Snapshot($snapshot, null, 'trend-session-v2');
    match ($fault) {
        'legacy result' => $signal['strategy'] = 'rsi_moving_average',
        'wrong version' => $signal['strategy_version'] = 1,
        'trend only result' => $signal['indicators']['candidate_filter'] = 'trend',
        'API result' => $signal['freshness']['source'] = 'twelvedata',
        'wrong account' => $signal['broker']['account_key'] = 'another-account',
        'wrong broker symbol' => $signal['broker']['symbol'] = 'XAUUSD',
        'momentum CLOSE' => $signal['action'] = 'CLOSE',
        'TP2 enabled' => $signal['trade_plan']['take_profit_2'] = 2100.0,
        'wrong exit policy' => $signal['trade_plan']['exit_policy'] = 'partial_tp1',
        'wrong target' => $signal['trade_plan']['take_profit_1'] += 2.0,
        'different quote' => $signal['quote_price'] += 0.1,
        'different tick time' => $signal['freshness']['observed_at'] = now()->subSecond()->toIso8601String(),
        'missing session' => $signal['indicators']['entry_session_allowed'] = false,
        'expired' => $signal['expires_at'] = now()->toIso8601String(),
    };
    $trading = Mockery::mock(TradingService::class)->makePartial();
    $trading->shouldReceive('evaluateRsiMt5Snapshot')->once()->andReturn($signal);
    $trading->shouldNotReceive('getSignalForStrategy');
    $service = new AutoTraderService($trading);
    rsiDemoSettings($service);
    rsiDemoFake($snapshot);
    expect($service->tick()['status'])->toBe('rejected')->and(AutoTradeOrder::count())->toBe(0);
    rsiDemoAssertNoSubmission();
})->with(['legacy result', 'wrong version', 'trend only result', 'API result', 'wrong account', 'wrong broker symbol', 'momentum CLOSE',
    'TP2 enabled', 'wrong exit policy', 'wrong target', 'different quote', 'different tick time', 'missing session', 'expired']);

test('existing broker exposure blocks RSI without requesting position-aware momentum exits', function (string $kind) {
    $snapshot = rsiDemoSnapshot();
    if ($kind === 'position') {
        $snapshot['positions'] = [['symbol' => 'XAUUSD-STD', 'magic' => (int) config('trading.auto_trader.magic'), 'type' => 0]];
    } else {
        $snapshot['pending_orders'] = [['symbol' => 'EURUSD', 'magic' => 0]];
    }
    $trading = Mockery::mock(TradingService::class);
    $trading->shouldNotReceive('evaluateRsiMt5Snapshot');
    $trading->shouldNotReceive('getSignalForStrategy');
    $service = new AutoTraderService($trading);
    rsiDemoSettings($service);
    rsiDemoFake($snapshot);
    $result = $service->tick();
    expect($result['status'])->toBe('rejected')->and($result['message'])->toContain('fixed SL/TP')->and(AutoTradeOrder::count())->toBe(0);
    rsiDemoAssertNoSubmission();
})->with(['position', 'pending order']);

test('an RSI signal that expires during order persistence is rejected immediately before POST', function () {
    $this->travelTo(Carbon::parse('2026-11-02T12:17:50Z'));
    $service = app(AutoTraderService::class);
    rsiDemoSettings($service);
    rsiDemoFake(rsiDemoSnapshot());
    Event::listen('eloquent.created: '.AutoTradeOrder::class, function () {
        $this->travel(10)->seconds();
    });
    $result = $service->tick();
    expect($result['status'])->toBe('rejected')->and($result['message'])->toContain('expired')
        ->and($result['order']->status)->toBe('rejected')->and(AutoTradeOrder::count())->toBe(1);
    rsiDemoAssertNoSubmission();
});

test('switching RSI aliases cannot resubmit an already handled canonical signal', function () {
    $service = app(AutoTraderService::class);
    $settings = rsiDemoSettings($service, 'rsi-gold-scalper');
    rsiDemoFake(rsiDemoSnapshot());
    expect($service->tick()['status'])->toBe('executed');
    $settings->update(['strategy_slug' => 'rsi-ma']);
    expect($service->tick()['status'])->toBe('duplicate')->and(AutoTradeOrder::count())->toBe(1);
    Http::assertSentCount(3);
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(1);
    Mail::assertNothingSent();
});

test('uncertain RSI execution blocks resubmission until the same UUID is reconciled', function () {
    $service = app(AutoTraderService::class);
    rsiDemoSettings($service);
    Http::fake(['demo-bridge.test/snapshot' => Http::response(rsiDemoSnapshot()),
        'demo-bridge.test/trade' => Http::failedConnection(),
        'demo-bridge.test/orders/*' => Http::sequence()->push(['status' => 'unknown'])->push(['status' => 'executed', 'order' => 123, 'deal' => 456])]);
    $first = $service->tick();
    expect($first['status'])->toBe('unknown');
    $uuid = $first['order']->uuid;
    expect($service->tick()['status'])->toBe('rejected')->and($first['order']->fresh()->status)->toBe('unknown');
    expect($service->tick()['status'])->toBe('duplicate')->and($first['order']->fresh()->status)->toBe('executed')
        ->and(AutoTradeOrder::count())->toBe(1);
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'http://demo-bridge.test/orders/'.$uuid);
    Mail::assertNothingSent();
});

test('Sunday RSI demo execution is blocked before a bridge request', function () {
    $this->travelTo(Carbon::parse('2026-11-01T12:00:00Z'));
    $service = app(AutoTraderService::class);
    rsiDemoSettings($service);
    expect($service->tick()['status'])->toBe('rejected')->and(AutoTradeOrder::count())->toBe(0);
    rsiDemoAssertNoSubmission(0);
});
