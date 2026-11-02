<?php

use App\Mail\StrategySignalAlertMail;
use App\Models\AutoTradeOrder;
use App\Models\SignalDelivery;
use App\Models\Strategy;
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
    $this->travelTo(Carbon::parse('2026-11-02T14:00:10Z'));
    Cache::flush();
    Http::preventStrayRequests();
    Mail::fake();
    Strategy::query()->delete();
    config([
        'trading.default_pair' => 'XAUUSD',
        'services.twelvedata.key' => 'v2-live-test-key',
        'services.signal_alerts.admin_emails' => 'paper@example.com',
        'services.signal_alerts.actions' => 'BUY,SELL',
        'services.signal_alerts.retest_v2_enabled' => false,
    ]);
    $this->mock(Mt5BridgeClient::class)->shouldNotReceive('snapshot');
});

/** Real V2 inputs, omitting every closed-session candle before the live adapter. */
function retestV2LiveBars(string $interval, float $retestClose = 2000.4, int $count = 320): array
{
    $minutes = $interval === '1h' ? 60 : 15;
    $end = intdiv(now()->timestamp, $minutes * 60) * $minutes * 60;
    $cursor = Carbon::createFromTimestampUTC($end)->subMinutes($minutes);
    $guard = new MarketDataGuard;
    $opens = [];
    while (count($opens) < $count) {
        if ($guard->marketStatus('XAUUSD', $cursor)['open']
            && $guard->marketStatus('XAUUSD', $cursor->copy()->addMinutes($minutes)->subSecond())['open']) {
            $opens[] = $cursor->toIso8601String();
        }
        $cursor->subMinutes($minutes);
    }
    $opens = array_reverse($opens);
    $bars = [];
    foreach ($opens as $index => $timestamp) {
        $close = $minutes === 60 ? 1970 + $index * 0.1 : 1999;
        $bars[] = ['timestamp' => $timestamp, 'open' => $close, 'high' => $close + 1, 'low' => $close - 1, 'close' => $close];
    }
    if ($minutes === 15) {
        $bars[$count - 2] = array_replace($bars[$count - 2], ['open' => 1999.8, 'high' => 2000.8, 'low' => 1999.7, 'close' => 2000.5]);
        $bars[$count - 1] = array_replace($bars[$count - 1], [
            'open' => 2000.0, 'high' => max(2000.8, $retestClose + 0.1), 'low' => 1999.8, 'close' => $retestClose,
        ]);
    }

    return $bars;
}

function fakeRetestV2Live(float $quotePrice = 2000.4, float $retestClose = 2000.4, int $count = 320, ?callable $beforeQuote = null): void
{
    Http::fake([
        'api.twelvedata.com/time_series*' => function ($request) use ($retestClose, $count) {
            return Http::response([
                'status' => 'ok', 'meta' => ['symbol' => 'XAU/USD', 'interval' => $request['interval']],
                'values' => array_map(fn ($bar) => [
                    'datetime' => Carbon::parse($bar['timestamp'])->utc()->format('Y-m-d H:i:s'),
                    'open' => (string) $bar['open'], 'high' => (string) $bar['high'],
                    'low' => (string) $bar['low'], 'close' => (string) $bar['close'],
                ], retestV2LiveBars($request['interval'], $retestClose, $count)),
            ]);
        },
        'api.twelvedata.com/quote*' => function () use ($quotePrice, $beforeQuote) {
            if ($beforeQuote !== null) {
                $beforeQuote();
            }

            return Http::response([
                'symbol' => 'XAU/USD', 'close' => (string) $quotePrice, 'is_market_open' => true,
                'last_quote_at' => now()->copy()->startOfMinute()->timestamp,
                'timestamp' => now()->copy()->startOfDay()->timestamp,
            ]);
        },
    ]);
}

function currentRetestV2Decision(?string $slug = null): array
{
    $exit = Artisan::call('signals:current', $slug === null ? [] : ['strategy' => $slug]);

    return [$exit, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
}

test('current V2 uses real fresh retests but exposes only a paper setup for canonical and mixed-case slugs', function (?string $slug) {
    fakeRetestV2Live();
    $signal = app(TradingService::class)->getSignalForStrategy($slug ?? 'gold-trend-retest-v2');
    expect($signal['action'])->toBe('BUY')
        ->and($signal['strategy'])->toBe('gold-trend-retest-v2')
        ->and($signal['research_only'])->toBeTrue()
        ->and($signal['freshness']['verified'])->toBeTrue()
        ->and((new MarketDataGuard)->validateSignal($signal, now()->utc()))->toBeNull();

    [$exit, $decision] = currentRetestV2Decision($slug);

    expect($exit)->toBe(0)->and($decision['state'])->toBe('RESEARCH_SETUP')
        ->and($decision['strategy'])->toBe('gold-trend-retest-v2')
        ->and($decision['action'])->toBe('BUY')
        ->and($decision['can_enter'])->toBeFalse()->and($decision['paper_setup_available'])->toBeTrue()
        ->and($decision['research_only'])->toBeTrue()->and($decision['automatic_execution_enabled'])->toBeFalse()
        ->and($decision['trade_plan'])->toEqual($signal['trade_plan'])
        ->and($decision['source_quote']['price'])->toBe(2000.4)
        ->and($decision['source_quote']['age_seconds'])->toBe(10)
        ->and($decision['confirmed_candle_close_utc'])->toBe('2026-11-02T14:00:00+00:00')
        ->and($decision['expires_at_utc'])->toBe('2026-11-02T14:03:00+00:00')
        ->and($decision['expires_at_manila'])->toContain('2026-11-02 22:03:00', 'PHT')
        ->and($decision['signal_id'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($decision['emails_sent'])->toBe(0)->and($decision['orders_sent'])->toBe(0)
        ->and(Strategy::count())->toBe(0)->and(AutoTradeOrder::count())->toBe(0)->and(SignalDelivery::count())->toBe(0);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/time_series') && $request['interval'] === '15min'
        && $request['outputsize'] >= 320 && $request['timezone'] === 'UTC');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/time_series') && $request['interval'] === '1h'
        && $request['outputsize'] >= 320 && $request['timezone'] === 'UTC');
    Mail::assertNothingSent();
})->with([null, '  GOLD-TREND-RETEST-V2  ']);

test('an expired real V2 setup cannot be revived by a newly refreshed quote', function () {
    fakeRetestV2Live();
    expect(app(TradingService::class)->getSignalForStrategy('gold-trend-retest-v2')['action'])->toBe('BUY');
    $this->travel(181)->seconds();

    [$exit, $decision] = currentRetestV2Decision();

    expect($exit)->toBe(1)->and($decision['state'])->toBe('NO_VERIFIED_SETUP')
        ->and($decision['action'])->toBe('WAIT')->and($decision['can_enter'])->toBeFalse()
        ->and($decision['paper_setup_available'])->toBeFalse()->and($decision['research_only'])->toBeTrue()
        ->and($decision['trade_plan'])->toBe([])->and($decision['signal_id'])->toBeNull()
        ->and($decision['expires_at_utc'])->toBeNull()->and($decision['confirmed_candle_close_utc'])->toBeNull()
        ->and($decision['source_quote']['price'])->toBe(2000.4)
        ->and($decision['source_quote']['observed_at_utc'])->toBe('2026-11-02T14:03:00+00:00')
        ->and(SignalDelivery::count())->toBe(0)->and(AutoTradeOrder::count())->toBe(0);
    Mail::assertNothingSent();
});

test('expiry crossed during the provider response hides the plan even when the returned quote is fresh', function () {
    $beforeResponse = null;
    fakeRetestV2Live(beforeQuote: function () use (&$beforeResponse) {
        $beforeResponse = now()->utc()->toIso8601String();
        $this->travelTo(Carbon::parse('2026-11-02T14:03:00Z'));
    });

    [$exit, $decision] = currentRetestV2Decision();

    expect($beforeResponse)->toBe('2026-11-02T14:00:10+00:00')
        ->and($exit)->toBe(1)->and($decision['state'])->toBe('NO_VERIFIED_SETUP')
        ->and($decision['action'])->toBe('WAIT')->and($decision['can_enter'])->toBeFalse()
        ->and($decision['paper_setup_available'])->toBeFalse()->and($decision['trade_plan'])->toBe([])
        ->and($decision['expires_at_utc'])->toBeNull()->and($decision['signal_id'])->toBeNull()
        ->and($decision['checked_at_utc'])->toBe('2026-11-02T14:03:00+00:00')
        ->and($decision['source_quote']['observed_at_utc'])->toBe('2026-11-02T14:03:00+00:00')
        ->and($decision['source_quote']['age_seconds'])->toBe(0)
        ->and(SignalDelivery::count())->toBe(0)->and(AutoTradeOrder::count())->toBe(0);
    Mail::assertNothingSent();
});

test('unknown strategy slugs containing rsi are rejected before any provider request', function () {
    $this->artisan('signals:current', ['strategy' => 'unknown-rsi-strategy'])->assertFailed();

    expect(SignalDelivery::count())->toBe(0)->and(AutoTradeOrder::count())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('an unavailable fallback quote exposes its provider error without a price or trade plan', function () {
    Http::fake([
        'api.twelvedata.com/time_series*' => Http::response(['status' => 'error', 'message' => 'Candle feed unavailable.']),
        'api.twelvedata.com/quote*' => Http::response(['status' => 'error', 'message' => 'Rate limit'], 429),
    ]);

    [$exit, $decision] = currentRetestV2Decision();

    expect($exit)->toBe(1)->and($decision['state'])->toBe('NO_VERIFIED_SETUP')
        ->and($decision['source_quote'])->toBeNull()->and($decision['source_quote_error'])->toContain('HTTP 429')
        ->and($decision['can_enter'])->toBeFalse()->and($decision['paper_setup_available'])->toBeFalse()
        ->and($decision['trade_plan'])->toBe([])->and($decision['expires_at_utc'])->toBeNull()
        ->and(SignalDelivery::count())->toBe(0)->and(AutoTradeOrder::count())->toBe(0);
    Mail::assertNothingSent();
});

test('current quotes outside a V2 retest bound hide the rejected plan while retaining a fresh price', function () {
    // Entry close 2000.9 gives a max entry bound of 2001.0; this quote is only
    // 0.2 away (under 0.1R), so the frozen retest bound is the rejecting gate.
    fakeRetestV2Live(quotePrice: 2001.1, retestClose: 2000.9);

    [$exit, $decision] = currentRetestV2Decision();

    expect($exit)->toBe(1)->and($decision['state'])->toBe('NO_VERIFIED_SETUP')
        ->and($decision['message'])->toContain('entry bounds')
        ->and($decision['can_enter'])->toBeFalse()->and($decision['paper_setup_available'])->toBeFalse()
        ->and($decision['trade_plan'])->toBe([])->and($decision['expires_at_utc'])->toBeNull()
        ->and($decision['source_quote']['price'])->toBe(2001.1)
        ->and($decision['source_quote']['age_seconds'])->toBe(10)
        ->and(SignalDelivery::count())->toBe(0)->and(AutoTradeOrder::count())->toBe(0);
    Mail::assertNothingSent();
});

test('insufficient V2 history never fabricates a paper setup from a valid standalone quote', function () {
    fakeRetestV2Live(count: 319);

    [$exit, $decision] = currentRetestV2Decision();

    expect($exit)->toBe(1)->and($decision['state'])->toBe('NO_VERIFIED_SETUP')
        ->and($decision['message'])->toContain('320')
        ->and($decision['can_enter'])->toBeFalse()->and($decision['paper_setup_available'])->toBeFalse()
        ->and($decision['trade_plan'])->toBe([])->and($decision['source_quote']['price'])->toBe(2000.4);
    Mail::assertNothingSent();
});

test('Sunday closed-market current V2 decisions send no HTTP email or order', function () {
    $this->travelTo(Carbon::parse('2026-11-01T13:10:00+08:00'));

    [$exit, $decision] = currentRetestV2Decision();

    expect($exit)->toBe(0)->and($decision['state'])->toBe('MARKET_CLOSED')
        ->and($decision['action'])->toBe('WAIT')->and($decision['research_only'])->toBeTrue()
        ->and($decision['can_enter'])->toBeFalse()->and($decision['paper_setup_available'])->toBeFalse()
        ->and($decision['source_quote'])->toBeNull()->and($decision['trade_plan'])->toBe([])
        ->and(SignalDelivery::count())->toBe(0)->and(AutoTradeOrder::count())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('V2 email opt-out also blocks forced dispatch and mixed-case catalog slugs before any fetch', function (string $slug, bool $force) {
    Strategy::create(['name' => 'V2 research', 'slug' => $slug, 'description' => 'Research', 'price' => 0]);
    $trading = Mockery::mock(TradingService::class);
    $trading->shouldNotReceive('getSignalForStrategy');
    app()->instance(TradingService::class, $trading);

    $this->artisan('signals:email-admin', ['--force' => $force])->assertSuccessful();

    expect(SignalDelivery::count())->toBe(0)->and(AutoTradeOrder::count())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with(['gold-trend-retest-v2', '  GOLD-TREND-RETEST-V2  '])->with([false, true]);

test('explicit paper email opt-in sends only a real fresh research signal and creates no order', function () {
    fakeRetestV2Live();
    config(['services.signal_alerts.retest_v2_enabled' => true]);
    Strategy::create(['name' => 'V2 research', 'slug' => 'gold-trend-retest-v2', 'description' => 'Research', 'price' => 0]);

    $result = app(SignalAlertService::class)->dispatch();

    expect($result['sent'])->toBe(1)->and($result['errors'])->toBe([])
        ->and(SignalDelivery::sole()->status)->toBe('sent')->and(AutoTradeOrder::count())->toBe(0);
    Mail::assertSent(StrategySignalAlertMail::class, function ($mail) {
        $mail->assertSeeInHtml('PAPER RESEARCH SETUP');

        return $mail->hasTo('paper@example.com') && $mail->signal['action'] === 'BUY'
            && $mail->signal['research_only'] === true && $mail->signal['strategy'] === 'gold-trend-retest-v2'
            && $mail->signal['freshness']['verified'] === true;
    });
});

test('registering V2 paper alerts requires both the enabled flag and explicit command opt-in', function (bool $enabled, bool $option) {
    config(['services.signal_alerts.retest_v2_enabled' => $enabled]);

    $this->artisan('signals:register-retest-v2', ['--paper-alerts' => $option])->assertFailed();

    expect(Strategy::count())->toBe(0)->and(SignalDelivery::count())->toBe(0)->and(AutoTradeOrder::count())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with([[false, false], [false, true], [true, false]]);

test('explicit V2 registration is idempotent and does not send anything', function () {
    config(['services.signal_alerts.retest_v2_enabled' => true]);
    $this->artisan('signals:register-retest-v2', ['--paper-alerts' => true])->assertSuccessful();
    $strategy = Strategy::sole();
    expect($strategy->slug)->toBe('gold-trend-retest-v2')->and($strategy->name)->toContain('Research');

    $this->artisan('signals:register-retest-v2', ['--paper-alerts' => true])->assertSuccessful();

    expect(Strategy::count())->toBe(1)->and(Strategy::sole()->id)->toBe($strategy->id)
        ->and(SignalDelivery::count())->toBe(0)->and(AutoTradeOrder::count())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('V2 registration preserves an existing strategy name description price and identity', function () {
    config(['services.signal_alerts.retest_v2_enabled' => true]);
    $strategy = Strategy::create([
        'slug' => 'gold-trend-retest-v2', 'name' => 'Existing research record',
        'description' => 'Keep this reviewed description', 'price' => 42,
    ]);
    $before = $strategy->fresh()->getAttributes();

    $this->artisan('signals:register-retest-v2', ['--paper-alerts' => true])->assertSuccessful();

    expect(Strategy::count())->toBe(1)->and($strategy->fresh()->getAttributes())->toBe($before)
        ->and(SignalDelivery::count())->toBe(0)->and(AutoTradeOrder::count())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});
