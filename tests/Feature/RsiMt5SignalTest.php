<?php

use App\Mail\StrategySignalAlertMail;
use App\Models\AutoTradeOrder;
use App\Models\AutoTraderSetting;
use App\Models\SignalDelivery;
use App\Models\Strategy;
use App\Models\User;
use App\Services\MarketDataGuard;
use App\Services\Mt5BridgeClient;
use App\Services\SignalAlertService;
use App\Services\TradingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02T12:15:20Z'));
    Cache::flush();
    Http::preventStrayRequests();
    Mail::fake();
    AutoTraderSetting::query()->delete();
    Strategy::query()->delete();
    config([
        'trading.default_pair' => 'XAUUSD', 'scalper.gold.source' => 'mt5', 'scalper.gold.version' => 'trend-session-v2',
        'trading.auto_trader.bridge_url' => 'http://rsi-bridge.test/trade',
        'trading.auto_trader.bridge_token' => str_repeat('t', 32), 'trading.auto_trader.default_account_type' => 'cent',
        'trading.auto_trader.symbols.cent' => 'XAUUSD-STD',
        'services.signal_alerts.admin_emails' => 'paper@example.com', 'services.signal_alerts.actions' => 'BUY,SELL',
        'services.twelvedata.key' => 'rsi-mt5-test-api-key',
    ]);
});

/** Fixed synthetic crossover; the final203 active bars match the production unit fixture. */
function rsiMt5Bars(bool $sell = false, int $count = 320): array
{
    $guard = new MarketDataGuard;
    $cursor = Carbon::createFromTimestampUTC(intdiv(now()->timestamp, 300) * 300)->subMinutes(5);
    $opens = [];
    while (count($opens) < $count) {
        if ($guard->marketStatus('XAUUSD', $cursor)['open'] && $guard->marketStatus('XAUUSD', $cursor->copy()->addSeconds(299))['open']) {
            $opens[] = $cursor->toIso8601String();
        }
        $cursor->subMinutes(5);
    }
    $bars = [];
    foreach (array_reverse($opens) as $index => $open) {
        $i = $index - ($count - 203);
        $price = 2000 + 0.01 * $i + 2 * sin(($i + 20) * 0.2) + ($i % 2 ? -0.8 : 0.8);
        if ($sell) {
            $price = 4000 - $price;
        }
        $bars[] = ['timestamp' => $open, 'open' => $price, 'high' => $price + 1, 'low' => $price - 1, 'close' => $price];
    }

    return $bars;
}

function rsiMt5Snapshot(bool $sell = false): array
{
    $bars = rsiMt5Bars($sell);
    $price = $bars[array_key_last($bars)]['close'];

    return ['status' => 'ok', 'source' => 'mt5', 'account_key' => 'test-pinned-account', 'symbol' => 'XAUUSD-STD',
        // Deliberately no execution capability: RSI requests only its read-only data contract.
        'capabilities' => ['m5_candles', 'quote_timestamp'], 'observed_at' => now()->toIso8601String(), 'm5' => $bars,
        'quote' => ['bid' => $price - 0.05, 'ask' => $price + 0.05, 'timestamp' => now()->toIso8601String()]];
}

function rsiMt5Fake(array $snapshot, int $status = 200): void
{
    Http::fake(['rsi-bridge.test/snapshot' => Http::response($snapshot, $status)]);
}

function rsiMt5AssertOnlySnapshot(int $count = 1): void
{
    Http::assertSentCount($count);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET' || $request->url() !== 'http://rsi-bridge.test/snapshot');
    expect(AutoTradeOrder::count())->toBe(0);
}

test('MT5 RSI versions preserve genuine actual legacy BUY and SELL plans with one broker snapshot', function (string $version, bool $sell) {
    config(['scalper.gold.version' => $version]);
    $snapshot = rsiMt5Snapshot($sell);
    rsiMt5Fake($snapshot);
    $expected = app(TradingService::class)->evaluateRsiMovingAverage(array_slice($snapshot['m5'], -200));
    $signal = app(TradingService::class)->getSignalForStrategy('rsi-gold-scalper');

    expect($signal['action'])->toBe($sell ? 'SELL' : 'BUY')->toBe($expected['action'])
        ->and($signal['strategy'])->toBe($version === 'legacy' ? 'rsi_moving_average' : 'rsi-gold-scalper-v2')
        ->and($signal['research_only'] ?? false)->toBe($version !== 'legacy')
        ->and($signal['indicators']['data_source'])->toBe('MT5 XAUUSD-STD')
        ->and($signal['freshness']['source'])->toBe('mt5')->and($signal['freshness']['verified'])->toBeTrue()
        ->and($signal['freshness']['data_as_of'])->toBe('2026-11-02T12:15:00+00:00')
        ->and($signal['freshness']['observed_at'])->toBe($snapshot['quote']['timestamp'])
        ->and($signal['expires_at'])->toBe('2026-11-02T12:18:00+00:00')
        ->and($signal['broker'])->toBe(['account_key' => 'test-pinned-account', 'symbol' => 'XAUUSD-STD'])
        ->and($signal['quote_price'])->toBe($snapshot['quote'][$sell ? 'bid' : 'ask']);
    foreach (['entry_price', 'stop_loss', 'take_profit_1', 'risk_per_unit', 'rr_tp1'] as $field) {
        expect($signal['trade_plan'][$field])->toBe($expected['trade_plan'][$field]);
    }
    expect($signal['trade_plan']['take_profit_2'])->toBe($version === 'legacy' ? $expected['trade_plan']['take_profit_2'] : null);
    rsiMt5AssertOnlySnapshot();
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.str_repeat('t', 32)));
    Mail::assertNothingSent();
    expect(SignalDelivery::count())->toBe(0);
})->with(['legacy', 'trend-v2', 'trend-session-v2'])->with([false, true]);

test('latest API and current CLI expose broker RSI research without execution', function (bool $sell) {
    rsiMt5Fake(rsiMt5Snapshot($sell));
    $strategy = Strategy::create(['name' => 'RSI MT5 research', 'slug' => 'rsi-gold-scalper', 'description' => 'Test', 'price' => 0]);
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin)->getJson(route('signals.latest', $strategy))->assertOk()
        ->assertJsonPath('action', $sell ? 'SELL' : 'BUY')->assertJsonPath('freshness.source', 'mt5')
        ->assertJsonPath('research_only', true)->assertJsonPath('trade_plan.take_profit_2', null);
    $exit = Artisan::call('signals:current', ['strategy' => 'rsi-gold-scalper']);
    $current = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)->and($current['state'])->toBe('RESEARCH_SETUP')->and($current['can_enter'])->toBeFalse()
        ->and($current['paper_setup_available'])->toBeTrue()->and($current['source_quote']['source'])->toBe('mt5')
        ->and($current['automatic_execution_enabled'])->toBeFalse()->and($current['orders_sent'])->toBe(0);
    rsiMt5AssertOnlySnapshot(2);
    Mail::assertNothingSent();
})->with([false, true]);

test('MT5 RSI fails closed on invalid data without API fallback or delivery', function (string $fault) {
    $snapshot = rsiMt5Snapshot();
    switch ($fault) {
        case 'missing quote': unset($snapshot['quote']);
            break;
        case 'stale quote': $snapshot['quote']['timestamp'] = now()->subSeconds(91)->toIso8601String();
            break;
        case 'quote before close': $snapshot['quote']['timestamp'] = '2026-11-02T12:14:59+00:00';
            break;
        case 'future quote': $snapshot['quote']['timestamp'] = now()->addSecond()->toIso8601String();
            break;
        case 'crossed quote': $snapshot['quote']['ask'] = $snapshot['quote']['bid'] - 0.1;
            break;
        case 'zero quote': $snapshot['quote']['bid'] = 0;
            break;
        case 'missing M5': unset($snapshot['m5']);
            break;
        case 'empty M5': $snapshot['m5'] = [];
            break;
        case 'old bridge': unset($snapshot['capabilities']);
            break;
        case 'missing M5 capability': $snapshot['capabilities'] = ['quote_timestamp'];
            break;
        case 'missing quote capability': $snapshot['capabilities'] = ['m5_candles'];
            break;
        case 'wrong source': $snapshot['source'] = 'twelvedata';
            break;
        case 'wrong symbol': $snapshot['symbol'] = 'EURUSD';
            break;
        case 'stale snapshot': $snapshot['observed_at'] = now()->subSeconds(31)->toIso8601String();
            break;
        case 'future snapshot': $snapshot['observed_at'] = now()->addSeconds(6)->toIso8601String();
            break;
        case 'malformed candle': $snapshot['m5'][100]['timestamp'] = '2026-02-30T12:00:00Z';
            break;
        case 'duplicate candle': $snapshot['m5'][] = $snapshot['m5'][array_key_last($snapshot['m5'])];
            break;
        case 'stale candle': array_pop($snapshot['m5']);
            break;
        case 'insufficient warmup': $snapshot['m5'] = array_slice($snapshot['m5'], -202);
            break;
        case 'invalid OHLC': $snapshot['m5'][100]['high'] = 1;
            break;
        case 'disconnected': break;
        default: throw new LogicException('Unexpected test fault.');
    }
    rsiMt5Fake($snapshot, $fault === 'disconnected' ? 503 : 200);
    $signal = app(TradingService::class)->getSignalForStrategy('rsi-gold-scalper');
    expect($signal['action'])->toBe('NO_DATA')->and($signal['error'])->not->toBeNull();
    foreach (['entry_price', 'stop_loss', 'take_profit_1', 'take_profit_2'] as $field) {
        expect(data_get($signal, 'trade_plan.'.$field))->toBeNull();
    }
    rsiMt5AssertOnlySnapshot();
    Mail::assertNothingSent();
    expect(SignalDelivery::count())->toBe(0);
})->with(['missing quote', 'stale quote', 'quote before close', 'future quote', 'crossed quote', 'zero quote', 'missing M5', 'empty M5',
    'old bridge', 'missing M5 capability', 'missing quote capability', 'wrong source', 'wrong symbol', 'stale snapshot',
    'future snapshot', 'malformed candle', 'duplicate candle', 'stale candle', 'insufficient warmup', 'invalid OHLC', 'disconnected']);

test('MT5 RSI ignores forming and future bars without relabeling their prices or timestamps', function () {
    $snapshot = rsiMt5Snapshot();
    rsiMt5Fake($snapshot);
    $expected = app(TradingService::class)->getSignalForStrategy('rsi-gold-scalper');
    foreach (['2026-11-02T12:15:00+00:00', '2026-11-02T12:20:00+00:00'] as $time) {
        $snapshot['m5'][] = ['timestamp' => $time, 'open' => 5000, 'high' => 9000, 'low' => 1, 'close' => 8000];
    }
    rsiMt5Fake($snapshot);
    $actual = app(TradingService::class)->getSignalForStrategy('rsi-gold-scalper');
    expect($actual)->toBe($expected);
    Http::assertSentCount(1);
    Mail::assertNothingSent();
    expect(AutoTradeOrder::count())->toBe(0);
});

test('MT5 RSI current CLI does not fetch an external quote after bridge failure', function () {
    rsiMt5Fake(['status' => 'rejected', 'message' => 'Disconnected'], 503);
    $exit = Artisan::call('signals:current', ['strategy' => 'rsi-gold-scalper']);
    $current = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($exit)->toBe(1)->and($current['state'])->toBe('NO_VERIFIED_SETUP')->and($current['source_quote'])->toBeNull()
        ->and($current['can_enter'])->toBeFalse()->and($current['trade_plan'])->toBe([]);
    rsiMt5AssertOnlySnapshot();
    Mail::assertNothingSent();
});

test('Sunday closure blocks MT5 RSI before any bridge or API request', function () {
    $this->travelTo(Carbon::parse('2026-11-01T12:00:00Z'));
    $this->mock(Mt5BridgeClient::class)->shouldNotReceive('snapshot');
    expect(app(TradingService::class)->getSignalForStrategy('rsi-gold-scalper')['market_status'])->toBe('closed');
    Http::assertNothingSent();
    Mail::assertNothingSent();
    expect(AutoTradeOrder::count())->toBe(0);
});

test('MT5 source for gold leaves FX RSI on its original API provider', function () {
    $this->mock(Mt5BridgeClient::class)->shouldNotReceive('snapshot');
    $bars = array_map(fn ($bar) => array_replace($bar, ['open' => $bar['open'] / 2000, 'high' => $bar['high'] / 2000,
        'low' => $bar['low'] / 2000, 'close' => $bar['close'] / 2000]), rsiMt5Bars());
    Http::fake([
        'api.twelvedata.com/time_series*' => Http::response(['status' => 'ok', 'meta' => ['symbol' => 'EUR/USD', 'interval' => '5min'],
            'values' => array_map(fn ($bar) => ['datetime' => Carbon::parse($bar['timestamp'])->format('Y-m-d H:i:s'),
                'open' => (string) $bar['open'], 'high' => (string) $bar['high'], 'low' => (string) $bar['low'], 'close' => (string) $bar['close']], $bars)]),
        'api.twelvedata.com/quote*' => Http::response(['symbol' => 'EUR/USD', 'close' => (string) $bars[array_key_last($bars)]['close'],
            'is_market_open' => true, 'last_quote_at' => now()->startOfMinute()->timestamp]),
    ]);
    $signal = app(TradingService::class)->getSignalForStrategy('rsi-moving-average', null, 'EURUSD');
    expect($signal['action'])->toBe('BUY')->and($signal['strategy'])->toBe('rsi_moving_average')
        ->and($signal['freshness']['source'])->toBe('twelvedata')->and($signal['research_only'] ?? false)->toBeFalse();
    Http::assertSentCount(2);
    Mail::assertNothingSent();
    expect(AutoTradeOrder::count())->toBe(0);
});

test('invalid RSI data source fails before any provider request', function () {
    config(['scalper.gold.source' => 'unrecognized']);
    $signal = app(TradingService::class)->getSignalForStrategy('rsi-gold-scalper');
    expect($signal['action'])->toBe('NO_DATA')->and($signal['error'])->toContain('RSI_GOLD_SCALPER_SOURCE');
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('explicit alert dispatch consumes the same MT5 research source using only fake mail', function () {
    rsiMt5Fake(rsiMt5Snapshot());
    Strategy::create(['name' => 'RSI broker research', 'slug' => 'rsi-gold-scalper', 'description' => 'Test', 'price' => 0]);
    $result = app(SignalAlertService::class)->dispatch(recipient: 'paper@example.com');
    expect($result['sent'])->toBe(1)->and($result['errors'])->toBe([]);
    Mail::assertSent(StrategySignalAlertMail::class, fn ($mail) => $mail->signal['freshness']['source'] === 'mt5'
        && $mail->signal['research_only'] === true && $mail->signal['trade_plan']['take_profit_2'] === null);
    rsiMt5AssertOnlySnapshot();
    expect(SignalDelivery::count())->toBe(1);
});
