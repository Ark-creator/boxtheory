<?php

use App\Mail\StrategySignalAlertMail;
use App\Models\AutoTradeOrder;
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
    Strategy::query()->delete();
    config([
        'trading.default_pair' => 'XAUUSD',
        'scalper.gold.version' => 'legacy',
        'services.twelvedata.key' => 'scalper-live-test-key',
        'services.signal_alerts.admin_emails' => 'paper@example.com',
        'services.signal_alerts.actions' => 'BUY,SELL',
    ]);
    $this->mock(Mt5BridgeClient::class)->shouldNotReceive('snapshot');
});

/** Last203 candles contain a fixed genuine SMA9/21 cross with RSI55.12. */
function scalperUpgradeBars(bool $sell = false, bool $countertrend = false, int $count = 250): array
{
    $guard = new MarketDataGuard;
    $cursor = Carbon::createFromTimestampUTC(intdiv(now()->timestamp, 300) * 300)->subMinutes(5);
    $opens = [];
    while (count($opens) < $count) {
        if ($guard->marketStatus('XAUUSD', $cursor)['open']
            && $guard->marketStatus('XAUUSD', $cursor->copy()->addSeconds(299))['open']) {
            $opens[] = $cursor->toIso8601String();
        }
        $cursor->subMinutes(5);
    }
    $bars = [];
    foreach (array_reverse($opens) as $index => $open) {
        $i = $index - ($count - 203);
        $price = 2000 + 0.01 * $i + 2 * sin(($i + 20) * 0.2) + ($i % 2 ? -0.8 : 0.8);
        if ($countertrend && $i < 179) {
            $price = 2050 - $i * 0.2;
        }
        $bars[] = ['timestamp' => $open, 'open' => $price, 'high' => $price + 1, 'low' => $price - 1, 'close' => $price];
    }

    return $sell ? array_map(fn ($bar) => array_replace($bar, ['open' => 4000 - $bar['open'],
        'high' => 4000 - $bar['low'], 'low' => 4000 - $bar['high'], 'close' => 4000 - $bar['close']]), $bars) : $bars;
}

function scalperUpgradeApi(array $bars, array $quoteOverrides = [], ?callable $mutatePayload = null, string $symbol = 'XAU/USD', ?callable $beforeQuote = null): void
{
    Http::fake([
        'api.twelvedata.com/time_series*' => function ($request) use ($bars, $mutatePayload, $symbol) {
            $payload = ['status' => 'ok', 'meta' => ['symbol' => $symbol, 'interval' => '5min', 'exchange_timezone' => 'Australia/Sydney'],
                'values' => array_map(fn ($bar) => ['datetime' => Carbon::parse($bar['timestamp'])->utc()->format('Y-m-d H:i:s'),
                    'open' => (string) $bar['open'], 'high' => (string) $bar['high'],
                    'low' => (string) $bar['low'], 'close' => (string) $bar['close']], $bars)];
            if ($mutatePayload !== null) {
                $payload = $mutatePayload($payload);
            }

            return Http::response($payload);
        },
        'api.twelvedata.com/quote*' => function () use ($bars, $quoteOverrides, $symbol, $beforeQuote) {
            if ($beforeQuote !== null) {
                $beforeQuote();
            }

            return Http::response(array_replace(['symbol' => $symbol,
                'close' => (string) $bars[array_key_last($bars)]['close'], 'is_market_open' => true,
                'last_quote_at' => now()->utc()->startOfMinute()->timestamp,
                // Deliberately old interval OPEN: only last_quote_at proves quote recency.
                'timestamp' => now()->utc()->startOfDay()->timestamp], $quoteOverrides));
        },
    ]);
}

function currentScalperUpgrade(string $slug = 'rsi-gold-scalper'): array
{
    $exit = Artisan::call('signals:current', ['strategy' => $slug]);

    return [$exit, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
}

function scalperUpgradeAssertNoEntry(array $signal): void
{
    foreach (['entry_price', 'stop_loss', 'take_profit_1', 'take_profit_2'] as $field) {
        expect(data_get($signal, 'trade_plan.'.$field))->toBeNull();
    }
}

test('configured legacy remains the actual RSI evaluator and V2 requests extra causal M5 history', function (string $version, bool $sell) {
    config(['scalper.gold.version' => $version]);
    $bars = scalperUpgradeBars($sell);
    scalperUpgradeApi($bars);
    $expected = app(TradingService::class)->evaluateRsiMovingAverage(array_slice($bars, -200));
    $actual = app(TradingService::class)->getSignalForStrategy('rsi-gold-scalper');

    expect($actual['action'])->toBe($sell ? 'SELL' : 'BUY')->toBe($expected['action'])
        ->and($actual['strategy'])->toBe($version === 'legacy' ? 'rsi_moving_average' : 'rsi-gold-scalper-v2')
        ->and($actual['research_only'] ?? false)->toBe($version !== 'legacy')
        ->and($actual['freshness']['verified'])->toBeTrue()
        ->and($actual['freshness']['data_as_of'])->toBe('2026-11-02T12:15:00+00:00')
        ->and($actual['freshness']['observed_at'])->toBe('2026-11-02T12:15:00+00:00')
        ->and($actual['expires_at'])->toBe('2026-11-02T12:18:00+00:00');
    foreach (['entry_price', 'stop_loss', 'take_profit_1', 'risk_per_unit', 'rr_tp1'] as $field) {
        expect($actual['trade_plan'][$field])->toBe($expected['trade_plan'][$field]);
    }
    expect($expected['trade_plan']['take_profit_2'])->not->toBeNull()->and($expected['trade_plan']['rr_tp2'])->toBe(2.5);
    if ($version === 'legacy') {
        expect($actual['trade_plan']['take_profit_2'])->toBe($expected['trade_plan']['take_profit_2'])
            ->and($actual['trade_plan']['rr_tp2'])->toBe(2.5);
    } else {
        expect($actual['trade_plan']['take_profit_2'])->toBeNull()->and($actual['trade_plan']['rr_tp2'])->toBeNull()
            ->and($actual['trade_plan']['exit_policy'])->toBe('full_tp1');
    }
    Http::assertSent(fn ($request) => str_contains($request->url(), '/time_series')
        && $request['symbol'] === 'XAU/USD' && $request['interval'] === '5min' && $request['timezone'] === 'UTC'
        && $request['outputsize'] === ($version === 'legacy' ? 1064 : 1114));
    Http::assertSentCount(2);
    Mail::assertNothingSent();
})->with(['legacy', 'trend-v2', 'trend-session-v2'])->with([false, true]);

test('the authenticated latest API returns the real research crossover without any order', function () {
    config(['scalper.gold.version' => 'trend-v2']);
    scalperUpgradeApi(scalperUpgradeBars());
    $strategy = Strategy::create(['name' => 'RSI research', 'slug' => 'rsi-gold-scalper', 'description' => 'Test', 'price' => 0]);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->getJson(route('signals.latest', $strategy))
        ->assertOk()->assertJsonPath('action', 'BUY')->assertJsonPath('strategy', 'rsi-gold-scalper-v2')
        ->assertJsonPath('research_only', true)->assertJsonPath('freshness.verified', true);

    expect(AutoTradeOrder::count())->toBe(0)->and(SignalDelivery::count())->toBe(0);
    Mail::assertNothingSent();
});

test('canonical and mixed RSI aliases retain their research classification in the current CLI', function (string $slug) {
    config(['scalper.gold.version' => 'trend-v2']);
    scalperUpgradeApi(scalperUpgradeBars());
    [$exit, $decision] = currentScalperUpgrade($slug);

    expect($exit)->toBe(0)->and($decision['state'])->toBe('RESEARCH_SETUP')
        ->and($decision['action'])->toBe('BUY')->and($decision['can_enter'])->toBeFalse()
        ->and($decision['paper_setup_available'])->toBeTrue()->and($decision['research_only'])->toBeTrue()
        ->and($decision['automatic_execution_enabled'])->toBeFalse()
        ->and($decision['trade_plan']['rr_tp1'])->toBe(1.5)
        ->and($decision['expires_at_utc'])->toBe('2026-11-02T12:18:00+00:00')
        ->and($decision['emails_sent'])->toBe(0)->and($decision['orders_sent'])->toBe(0)
        ->and(Strategy::count())->toBe(0)->and(SignalDelivery::count())->toBe(0)->and(AutoTradeOrder::count())->toBe(0);
    Mail::assertNothingSent();
})->with(['rsi-gold-scalper', '  RSI-GOLD-SCALPER  ', 'rsi-ma', 'RSI-GOLD-SCALPER-V2']);

test('the explicit V2 slug selects the upgrade even when the generic default is legacy', function () {
    scalperUpgradeApi(scalperUpgradeBars());
    $signal = app(TradingService::class)->getSignalForStrategy('rsi-gold-scalper-v2');

    expect($signal['action'])->toBe('BUY')->and($signal['research_only'])->toBeTrue()
        ->and($signal['strategy'])->toBe('rsi-gold-scalper-v2');
});

test('rejected trend or session crossovers clear their plans and cannot send entry email', function (string $reason) {
    config(['scalper.gold.version' => $reason === 'session' ? 'trend-session-v2' : 'trend-v2']);
    if ($reason === 'session') {
        $this->travelTo(Carbon::parse('2026-11-02T17:00:20Z'));
    }
    $bars = scalperUpgradeBars(countertrend: $reason === 'trend');
    expect(app(TradingService::class)->evaluateRsiMovingAverage($bars)['action'])->toBe('BUY');
    scalperUpgradeApi($bars);
    Strategy::create(['name' => 'RSI research', 'slug' => 'rsi-gold-scalper', 'description' => 'Test', 'price' => 0]);

    $signal = app(TradingService::class)->getSignalForStrategy('rsi-gold-scalper');
    expect($signal['action'])->toBe('HOLD')->and($signal['research_only'])->toBeTrue()
        ->and($signal['trade_plan']['entry_price'])->toBeNull()->and($signal['trade_plan']['stop_loss'])->toBeNull()
        ->and($signal['trade_plan']['take_profit_1'])->toBeNull();
    [$exit, $decision] = currentScalperUpgrade();
    expect($exit)->toBe(0)->and($decision['action'])->toBe('HOLD')
        ->and($decision['can_enter'])->toBeFalse()->and($decision['paper_setup_available'])->toBeFalse()
        ->and($decision['trade_plan'])->toBe([]);
    expect(app(SignalAlertService::class)->dispatch(force: true)['sent'])->toBe(0)
        ->and(SignalDelivery::count())->toBe(0)->and(AutoTradeOrder::count())->toBe(0);
    Mail::assertNothingSent();
})->with(['trend', 'session']);

test('eligible RSI research alerts carry the paper banner and preserve the full original plan', function () {
    config(['scalper.gold.version' => 'trend-v2']);
    scalperUpgradeApi(scalperUpgradeBars());
    Strategy::create(['name' => 'RSI Scalper', 'slug' => 'rsi-gold-scalper', 'description' => 'Test', 'price' => 0]);

    $result = app(SignalAlertService::class)->dispatch();
    expect($result['sent'])->toBe(1)->and($result['errors'])->toBe([])
        ->and(SignalDelivery::sole()->status)->toBe('sent')->and(AutoTradeOrder::count())->toBe(0);
    Mail::assertSent(StrategySignalAlertMail::class, function ($mail) {
        $mail->assertSeeInHtml('PAPER RESEARCH SETUP - profitability is not established.');
        expect($mail->envelope()->subject)->toContain('PAPER BUY');

        return $mail->hasTo('paper@example.com') && $mail->signal['action'] === 'BUY'
            && $mail->signal['research_only'] === true && $mail->signal['strategy'] === 'rsi-gold-scalper-v2'
            && $mail->signal['freshness']['verified'] === true && $mail->signal['trade_plan']['rr_tp1'] === 1.5;
    });
    expect(app(SignalAlertService::class)->dispatch()['sent'])->toBe(0);
    Mail::assertSent(StrategySignalAlertMail::class, 1);
});

test('fresh quote drift is checked against the actual original RSI risk for both directions', function (bool $sell, float $driftR, string $expected) {
    config(['scalper.gold.version' => 'trend-v2']);
    $bars = scalperUpgradeBars($sell);
    $plan = app(TradingService::class)->evaluateRsiMovingAverage($bars)['trade_plan'];
    $quote = $plan['entry_price'] + ($sell ? -1 : 1) * $driftR * abs($plan['entry_price'] - $plan['stop_loss']);
    scalperUpgradeApi($bars, ['close' => (string) $quote]);
    $signal = app(TradingService::class)->getSignalForStrategy('rsi-gold-scalper');

    expect($signal['action'])->toBe($expected === 'entry' ? ($sell ? 'SELL' : 'BUY') : 'NO_DATA');
    if ($expected === 'blocked') {
        expect($signal['error'])->toContain('0.1R');
        scalperUpgradeAssertNoEntry($signal);
    } else {
        expect($signal['trade_plan']['entry_price'])->toBe($plan['entry_price']);
    }
    Mail::assertNothingSent();
})->with([[false, 0.099, 'entry'], [true, 0.099, 'entry'], [false, 0.101, 'blocked'], [true, 0.101, 'blocked']]);

test('unverified stale or future quotes cannot expose or email a research entry', function (string $fault) {
    config(['scalper.gold.version' => 'trend-v2']);
    $overrides = match ($fault) {
        'stale' => ['last_quote_at' => now()->subSeconds(91)->timestamp],
        'future' => ['last_quote_at' => now()->addSecond()->timestamp],
        'missing' => ['last_quote_at' => null],
        'closed' => ['is_market_open' => false],
        'identity' => ['symbol' => 'GC=F'],
    };
    scalperUpgradeApi(scalperUpgradeBars(), $overrides);
    Strategy::create(['name' => 'RSI research', 'slug' => 'rsi-gold-scalper', 'description' => 'Test', 'price' => 0]);

    expect(app(TradingService::class)->getSignalForStrategy('rsi-gold-scalper')['action'])->toBe('NO_DATA')
        ->and(app(SignalAlertService::class)->dispatch(force: true)['sent'])->toBe(0)
        ->and(SignalDelivery::count())->toBe(0);
    Mail::assertNothingSent();
})->with(['stale', 'future', 'missing', 'closed', 'identity']);

test('cached completed candles expire at three minutes even with a newly fresh quote', function () {
    config(['scalper.gold.version' => 'trend-v2']);
    scalperUpgradeApi(scalperUpgradeBars());
    expect(app(TradingService::class)->getSignalForStrategy('rsi-gold-scalper')['action'])->toBe('BUY');
    $this->travelTo(Carbon::parse('2026-11-02T12:18:00Z'));

    [$exit, $decision] = currentScalperUpgrade();
    expect($exit)->toBe(1)->and($decision['state'])->toBe('NO_VERIFIED_SETUP')
        ->and($decision['can_enter'])->toBeFalse()->and($decision['paper_setup_available'])->toBeFalse()
        ->and($decision['trade_plan'])->toBe([])->and($decision['source_quote']['age_seconds'])->toBe(0);
    Mail::assertNothingSent();
});

test('an HTTP response arriving after setup expiry cannot return an entry', function () {
    config(['scalper.gold.version' => 'trend-v2']);
    scalperUpgradeApi(scalperUpgradeBars(), beforeQuote: function () {
        Carbon::setTestNow(Carbon::parse('2026-11-02T12:18:01Z'));
    });

    $signal = app(TradingService::class)->getSignalForStrategy('rsi-gold-scalper');
    expect($signal['action'])->toBe('NO_DATA');
    scalperUpgradeAssertNoEntry($signal);
    Mail::assertNothingSent();
});

test('invalid source candles and short history fail closed before requesting a quote', function (string $fault) {
    config(['scalper.gold.version' => 'trend-v2']);
    $bars = scalperUpgradeBars(count: $fault === 'history' ? 202 : 250);
    if ($fault === 'stale') {
        $bars = array_slice($bars, 0, -2);
    }
    scalperUpgradeApi($bars, mutatePayload: function ($payload) use ($fault) {
        if ($fault === 'OHLC') {
            unset($payload['values'][100]['high']);
        } elseif ($fault === 'identity') {
            $payload['meta']['symbol'] = 'GC=F';
        }

        return $payload;
    });
    $signal = app(TradingService::class)->getSignalForStrategy('rsi-gold-scalper');

    expect($signal['action'])->toBe('NO_DATA');
    scalperUpgradeAssertNoEntry($signal);
    if ($fault === 'history') {
        expect($signal['message'])->toContain('203');
    }
    Http::assertSentCount(1);
    Mail::assertNothingSent();
})->with(['history', 'stale', 'OHLC', 'identity']);

test('forming future API prices do not affect the closed RSI entry or its original plan', function () {
    config(['scalper.gold.version' => 'trend-v2']);
    $bars = scalperUpgradeBars();
    $legacy = app(TradingService::class)->evaluateRsiMovingAverage($bars);
    scalperUpgradeApi($bars, mutatePayload: function ($payload) {
        $payload['values'][] = ['datetime' => '2026-11-02 12:15:00', 'open' => '2000', 'high' => '9999', 'low' => '1', 'close' => '9000'];
        $payload['values'][] = ['datetime' => '2026-11-02 12:20:00', 'open' => '9000', 'high' => '9999', 'low' => '1', 'close' => '1'];

        return $payload;
    });

    $signal = app(TradingService::class)->getSignalForStrategy('rsi-gold-scalper');
    expect($signal['action'])->toBe('BUY')->and($signal['trade_plan']['entry_price'])->toBe($legacy['trade_plan']['entry_price'])
        ->and($signal['trade_plan']['stop_loss'])->toBe($legacy['trade_plan']['stop_loss'])
        ->and($signal['timestamp'])->toBe('2026-11-02T12:15:00+00:00');
});

test('Sunday closed-market RSI requests and forced email polls never fetch or send', function () {
    config(['scalper.gold.version' => 'trend-v2']);
    $this->travelTo(Carbon::parse('2026-11-01T13:10:00+08:00'));
    Strategy::create(['name' => 'RSI research', 'slug' => 'rsi-gold-scalper', 'description' => 'Test', 'price' => 0]);
    [$exit, $decision] = currentScalperUpgrade();

    expect($exit)->toBe(0)->and($decision['state'])->toBe('MARKET_CLOSED')
        ->and($decision['action'])->toBe('WAIT')->and($decision['source_quote'])->toBeNull()
        ->and($decision['can_enter'])->toBeFalse()->and($decision['paper_setup_available'])->toBeFalse()
        ->and(app(SignalAlertService::class)->dispatch(force: true)['sent'])->toBe(0)
        ->and(SignalDelivery::count())->toBe(0)->and(AutoTradeOrder::count())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('Gold upgrade configuration leaves FX on the actual legacy RSI engine', function () {
    config(['scalper.gold.version' => 'trend-session-v2']);
    $this->travelTo(Carbon::parse('2026-11-02T17:00:20Z'));
    $bars = array_map(fn ($bar) => array_replace($bar, ['open' => $bar['open'] / 2000, 'high' => $bar['high'] / 2000,
        'low' => $bar['low'] / 2000, 'close' => $bar['close'] / 2000]), scalperUpgradeBars());
    $expected = app(TradingService::class)->evaluateRsiMovingAverage(array_slice($bars, -200));
    scalperUpgradeApi($bars, symbol: 'EUR/USD');

    $signal = app(TradingService::class)->getSignalForStrategy('rsi-moving-average', null, 'EURUSD');
    expect($signal['action'])->toBe('BUY')->and($signal['strategy'])->toBe('rsi_moving_average')
        ->and($signal['research_only'] ?? false)->toBeFalse()->and($signal['symbol_code'])->toBe('EURUSD')
        ->and($signal['trade_plan'])->toBe($expected['trade_plan']);
});

test('invalid version and the explicit Gold-only slug fail before market-data fetch', function (string $version, string $slug, string $pair, string $error) {
    config(['scalper.gold.version' => $version]);
    $signal = app(TradingService::class)->getSignalForStrategy($slug, null, $pair);

    expect($signal['action'])->toBe('NO_DATA')->and($signal['error'])->toContain($error);
    scalperUpgradeAssertNoEntry($signal);
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with([
    ['unexpected', 'rsi-gold-scalper', 'XAUUSD', 'RSI_GOLD_SCALPER_VERSION'],
    ['trend-v2', 'rsi-gold-scalper-v2', 'EURUSD', 'XAUUSD only'],
]);
