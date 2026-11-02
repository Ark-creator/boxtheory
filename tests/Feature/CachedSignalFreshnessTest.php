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
    $this->travelTo(Carbon::parse('2026-11-02T14:00:10Z'));
    $this->withoutVite();
    Cache::flush();
    Http::preventStrayRequests();
    Mail::fake();
    config([
        'trading.default_pair' => 'XAUUSD', 'trading.matrix.pair_codes' => 'XAUUSD',
        'trading.matrix.refresh_pairs_per_request' => 1, 'trading.matrix.cache_seconds' => 900,
        'trading.watchlist.default_pair_codes' => 'XAUUSD', 'trading.watchlist.max_pairs' => 1,
        'trading.watchlist.cache_seconds' => 180,
    ]);
    Strategy::query()->delete();
    $this->strategy = Strategy::create(['name' => 'RSI Gold Scalper', 'slug' => 'rsi-scalper', 'description' => 'Cache tests', 'price' => 0]);
    $this->viewer = User::factory()->create(['role' => 'admin', 'watchlist_pairs' => ['XAUUSD']]);
    $this->actingAs($this->viewer);
});

function cachedViewSignal(string $message = 'Current verified signal', string $pair = 'XAUUSD'): array
{
    $closed = Carbon::createFromTimestampUTC(intdiv(now()->timestamp, 300) * 300);

    return [
        'strategy' => 'rsi_moving_average', 'action' => 'BUY', 'price' => 2000.0,
        'symbol' => 'XAU/USD', 'symbol_code' => $pair, 'pair_name' => 'Gold Spot',
        'timestamp' => $closed->toIso8601String(), 'expires_at' => $closed->copy()->addMinutes(3)->toIso8601String(),
        'trend' => 'bullish', 'position' => 'flat', 'message' => $message, 'error' => null,
        'trade_plan' => ['entry_price' => 2000, 'stop_loss' => 1995, 'take_profit_1' => 2007.5, 'take_profit_2' => 2012.5],
        'indicators' => ['data_source' => 'twelvedata_xauusd'],
        'freshness' => [
            'verified' => true, 'source' => 'twelvedata', 'interval_seconds' => 300,
            'data_as_of' => $closed->toIso8601String(), 'observed_at' => now()->utc()->toIso8601String(),
            'generated_at' => now()->utc()->toIso8601String(), 'market_open' => true,
        ],
    ];
}

function cachedViewKey(string $route, User $user, Strategy $strategy): string
{
    return $route === 'watchlist.index' ? 'watchlist:'.$user->id.':xauusd:'.$strategy->id.':flat'
        : 'signals_matrix:xauusd:'.$strategy->id.':flat';
}

test('both signal views reuse a verified current cache hit without fetching prices', function (string $route) {
    $key = cachedViewKey($route, $this->viewer, $this->strategy);
    Cache::put($key, cachedViewSignal(), 900);
    $mock = Mockery::mock(TradingService::class)->makePartial();
    $mock->shouldNotReceive('getSignalForStrategy');
    $this->app->instance(TradingService::class, $mock);

    $response = $this->get(route($route))->assertOk();
    expect($response->viewData('matrix')['XAUUSD'][$this->strategy->id]['action'])->toBe('BUY');
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with(['watchlist.index', 'admin.signals.all-pairs']);

test('both signal views replace expired unverified wrong-pair and stale-quote cache entries', function (string $route, string $fault) {
    $key = cachedViewKey($route, $this->viewer, $this->strategy);
    $old = cachedViewSignal('Old cache payload');
    match ($fault) {
        'expired' => $old['expires_at'] = now()->copy()->subSecond()->toIso8601String(),
        'unverified' => $old['freshness'] = [],
        'wrong pair' => $old['symbol_code'] = 'EURUSD',
        'stale quote' => $old['freshness']['observed_at'] = now()->copy()->subSeconds(91)->toIso8601String(),
    };
    Cache::put($key, $old, 900);
    $mock = Mockery::mock(TradingService::class)->makePartial();
    $mock->shouldReceive('getSignalForStrategy')->once()->with('rsi-scalper', null, 'XAUUSD')->andReturn(cachedViewSignal());
    $this->app->instance(TradingService::class, $mock);

    $response = $this->get(route($route))->assertOk();
    expect($response->viewData('matrix')['XAUUSD'][$this->strategy->id]['message'])->toBe('Current verified signal')
        ->and(Cache::get($key)['message'])->toBe('Current verified signal');
    Http::assertNothingSent();
})->with(['watchlist.index', 'admin.signals.all-pairs'])->with(['expired', 'unverified', 'wrong pair', 'stale quote']);

test('both views discard cached entry signals on Sunday without provider requests even with zero matrix budget', function (string $route) {
    $this->travelTo(Carbon::parse('2026-11-01T04:00:10Z'));
    config(['trading.matrix.refresh_pairs_per_request' => 0]);
    $key = cachedViewKey($route, $this->viewer, $this->strategy);
    Cache::put($key, cachedViewSignal('Sunday cache must disappear'), 900);

    $response = $this->get(route($route))->assertOk();
    $signal = $response->viewData('matrix')['XAUUSD'][$this->strategy->id];
    expect($signal['action'])->toBe('NO_DATA')
        ->and($signal['market_status'])->toBe('closed')
        ->and(Cache::has($key))->toBeFalse();
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with(['watchlist.index', 'admin.signals.all-pairs']);

test('closed-market errors are never cached through reopening in either view', function (string $route) {
    $key = cachedViewKey($route, $this->viewer, $this->strategy);
    $this->travelTo(Carbon::parse('2026-11-01T22:59:50Z'));
    $this->get(route($route))->assertOk();
    expect(Cache::has($key))->toBeFalse();

    // Gold opens at 23:00 UTC after the November DST change. At the first
    // completed M5 close, the previous closed response must not shadow a setup.
    $this->travelTo(Carbon::parse('2026-11-01T23:05:10Z'));
    $mock = Mockery::mock(TradingService::class)->makePartial();
    $mock->shouldReceive('getSignalForStrategy')->once()->andReturn(cachedViewSignal('After reopening'));
    $this->app->instance(TradingService::class, $mock);
    $response = $this->get(route($route))->assertOk();

    expect($response->viewData('matrix')['XAUUSD'][$this->strategy->id]['message'])->toBe('After reopening');
    Http::assertNothingSent();
})->with(['watchlist.index', 'admin.signals.all-pairs']);

test('open-market provider failures are recalculated rather than cached in either view', function (string $route) {
    $key = cachedViewKey($route, $this->viewer, $this->strategy);
    $failure = ['action' => 'NO_DATA', 'price' => 0.0, 'timestamp' => null, 'trade_plan' => [],
        'symbol_code' => 'XAUUSD', 'message' => 'Provider unavailable', 'error' => 'Provider unavailable'];
    $mock = Mockery::mock(TradingService::class)->makePartial();
    $mock->shouldReceive('getSignalForStrategy')->twice()->andReturn($failure, cachedViewSignal());
    $this->app->instance(TradingService::class, $mock);

    $this->get(route($route))->assertOk();
    expect(Cache::has($key))->toBeFalse();
    $response = $this->get(route($route))->assertOk();
    expect($response->viewData('matrix')['XAUUSD'][$this->strategy->id]['action'])->toBe('BUY');
})->with(['watchlist.index', 'admin.signals.all-pairs']);

test('cache lifetime ends at source observation freshness instead of the configured fifteen minutes', function () {
    $cache = app(SignalSnapshotCache::class);
    $cache->put('test-freshness-bound', cachedViewSignal(), 900);
    expect(Cache::has('test-freshness-bound'))->toBeTrue();
    $this->travelTo(Carbon::parse('2026-11-02T14:01:41Z'));
    expect(Cache::has('test-freshness-bound'))->toBeFalse();
});

test('NO_DATA is never cached even if it carries otherwise fresh metadata', function () {
    $cache = app(SignalSnapshotCache::class);
    $failure = cachedViewSignal();
    $failure['action'] = 'NO_DATA';
    $cache->put('test-no-data-cache', $failure, 900);
    expect(Cache::has('test-no-data-cache'))->toBeFalse();
    Cache::put('test-no-data-cache', $failure, 900);
    expect($cache->get('test-no-data-cache', 'XAUUSD'))->toBeNull()
        ->and(Cache::has('test-no-data-cache'))->toBeFalse();
});

test('a signal that expires while other matrix cells load is suppressed immediately before rendering', function () {
    $cache = app(SignalSnapshotCache::class);
    $signal = cachedViewSignal();
    $signal['expires_at'] = now()->copy()->addSecond()->toIso8601String();
    $cache->put('test-render-delay', $signal, 900);
    $hit = $cache->get('test-render-delay', 'XAUUSD');
    expect($hit['action'])->toBe('BUY');
    $this->travelTo(Carbon::parse('2026-11-02T14:00:12Z'));

    $safe = $cache->forView($hit, 'XAUUSD');
    expect($safe['action'])->toBe('NO_DATA')
        ->and($safe['trade_plan'])->toBe([])
        ->and($safe['price'])->toBe(0.0);
});
