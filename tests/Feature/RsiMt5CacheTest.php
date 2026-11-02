<?php

use App\Models\Strategy;
use App\Models\User;
use App\Services\SignalSnapshotCache;
use App\Services\TradingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02T12:15:20Z'));
    $this->withoutVite();
    Cache::flush();
    Http::preventStrayRequests();
    Mail::fake();
    config(['scalper.gold.source' => 'twelvedata', 'trading.default_pair' => 'XAUUSD',
        'trading.matrix.pair_codes' => 'XAUUSD', 'trading.matrix.refresh_pairs_per_request' => 1,
        'trading.matrix.cache_seconds' => 900, 'trading.watchlist.default_pair_codes' => 'XAUUSD',
        'trading.watchlist.max_pairs' => 1, 'trading.watchlist.cache_seconds' => 180]);
});

function rsiProviderCacheSignal(string $provider, string $strategy = 'rsi-gold-scalper-v2', string $pair = 'XAUUSD'): array
{
    $close = Carbon::createFromTimestampUTC(intdiv(now()->timestamp, 300) * 300);

    return ['strategy' => $strategy, 'action' => 'BUY', 'price' => 2000.0, 'symbol_code' => $pair, 'symbol' => $pair,
        'timestamp' => $close->toIso8601String(), 'expires_at' => $close->copy()->addMinutes(3)->toIso8601String(),
        'quote_price' => 2000.0, 'signal_id' => 'test-'.$provider, 'trend' => 'bullish', 'position' => 'flat',
        'message' => 'Verified '.$provider, 'error' => null, 'research_only' => true,
        'trade_plan' => ['entry_price' => 2000.0, 'stop_loss' => 1995.0, 'take_profit_1' => 2007.5, 'take_profit_2' => null],
        'indicators' => ['data_source' => $provider],
        'freshness' => ['verified' => true, 'source' => $provider, 'interval_seconds' => 300,
            'data_as_of' => $close->toIso8601String(), 'observed_at' => now()->toIso8601String(),
            'generated_at' => now()->toIso8601String(), 'market_open' => true]];
}

test('RSI cache reads discard the prior provider immediately after either source switch', function (string $from, string $to, string $strategy) {
    $cache = app(SignalSnapshotCache::class);
    $signal = rsiProviderCacheSignal($from, $strategy);
    config(['scalper.gold.source' => $from]);
    $cache->put('provider-switch', $signal, 900);
    expect($cache->get('provider-switch', 'XAUUSD'))->toBe($signal);
    config(['scalper.gold.source' => $to]);

    expect($cache->get('provider-switch', 'XAUUSD'))->toBeNull()->and(Cache::has('provider-switch'))->toBeFalse();
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with([['twelvedata', 'mt5'], ['mt5', 'twelvedata']])->with(['rsi_moving_average', 'rsi-gold-scalper-v2', 'rsi-scalper', '  RSI-MA  ']);

test('RSI cache writes reject a mismatched provider and accept the configured provider', function (string $from, string $to, string $strategy) {
    config(['scalper.gold.source' => $to]);
    $cache = app(SignalSnapshotCache::class);
    Cache::put('provider-write', ['old' => true], 900);
    $cache->put('provider-write', rsiProviderCacheSignal($from, $strategy), 900);
    expect(Cache::has('provider-write'))->toBeFalse();
    $fresh = rsiProviderCacheSignal($to, $strategy);
    $cache->put('provider-write', $fresh, 900);
    expect($cache->get('provider-write', 'XAUUSD'))->toBe($fresh);
    Http::assertNothingSent();
})->with([['twelvedata', 'mt5'], ['mt5', 'twelvedata']])->with(['rsi_moving_average', 'rsi-gold-scalper-v2', 'rsi-scalper', '  RSI-MA  ']);

test('RSI final view validation strips old-provider plans even when the candle and quote are fresh', function (string $from, string $to, string $strategy) {
    config(['scalper.gold.source' => $to]);
    $result = app(SignalSnapshotCache::class)->forView(rsiProviderCacheSignal($from, $strategy), 'XAUUSD');
    expect($result['action'])->toBe('NO_DATA')->and($result['trade_plan'])->toBe([])
        ->and($result['freshness']['verified'])->toBeFalse()->and($result['quote_price'])->toBeNull()
        ->and($result['signal_id'])->toBeNull()->and($result['timestamp'])->toBeNull()
        ->and($result['error'])->toContain('different configured data source');
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with([['twelvedata', 'mt5'], ['mt5', 'twelvedata']])->with(['rsi_moving_average', 'rsi-gold-scalper-v2', 'rsi-scalper', '  RSI-MA  ']);

test('RSI provider enforcement normalizes the configured source and preserves matched view payloads', function () {
    config(['scalper.gold.source' => '  MT5  ']);
    $signal = rsiProviderCacheSignal('mt5');
    $cache = app(SignalSnapshotCache::class);
    $cache->put('normalized-provider', $signal, 900);
    expect($cache->get('normalized-provider', 'XAUUSD'))->toBe($signal)
        ->and($cache->forView($signal, 'XAUUSD'))->toBe($signal);
});

test('provider enforcement leaves other strategies and FX cache behavior unchanged', function (string $strategy, string $pair) {
    config(['scalper.gold.source' => 'mt5']);
    $signal = rsiProviderCacheSignal('twelvedata', $strategy, $pair);
    $cache = app(SignalSnapshotCache::class);
    $cache->put('other-market', $signal, 900);
    expect($cache->get('other-market', $pair))->toBe($signal)->and($cache->forView($signal, $pair))->toBe($signal);
})->with([['rsi_moving_average', 'EURUSD'], ['rsi-gold-scalper-v2', 'EURUSD'], ['gold-risk-breakout', 'XAUUSD'], ['conservative_v2', 'XAUUSD']]);

test('both dashboard views recalculate a fresh RSI cache hit from the wrong provider', function (string $route, string $from, string $to) {
    config(['scalper.gold.source' => $to]);
    Strategy::query()->delete();
    $strategy = Strategy::create(['name' => 'RSI source cache', 'slug' => 'rsi-scalper', 'description' => 'Test', 'price' => 0]);
    $user = User::factory()->create(['role' => 'admin', 'watchlist_pairs' => ['XAUUSD']]);
    $this->actingAs($user);
    $key = $route === 'watchlist.index' ? 'watchlist:'.$user->id.':xauusd:'.$strategy->id.':flat'
        : 'signals_matrix:xauusd:'.$strategy->id.':flat';
    Cache::put($key, rsiProviderCacheSignal($from), 900);
    $fresh = rsiProviderCacheSignal($to);
    $mock = Mockery::mock(TradingService::class)->makePartial();
    $mock->shouldReceive('getSignalForStrategy')->once()->with('rsi-scalper', null, 'XAUUSD')->andReturn($fresh);
    $this->app->instance(TradingService::class, $mock);

    $response = $this->get(route($route))->assertOk();
    expect($response->viewData('matrix')['XAUUSD'][$strategy->id]['freshness']['source'])->toBe($to)
        ->and(Cache::get($key)['freshness']['source'])->toBe($to);
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with(['watchlist.index', 'admin.signals.all-pairs'])->with([['twelvedata', 'mt5'], ['mt5', 'twelvedata']]);
