<?php

use App\Mail\StrategySignalAlertMail;
use App\Models\AutoTradeOrder;
use App\Services\AutoTraderService;
use App\Services\GoldBreakoutStrategy;
use App\Services\Mt5BridgeClient;
use App\Services\SignalAlertService;
use App\Services\TradingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-14T10:00:10Z'));
    Cache::flush();
    Http::preventStrayRequests();
    Mail::fake();
    config([
        'gold_breakout.signal_source' => 'twelvedata',
        'services.twelvedata.key' => 'test-key',
        'services.signal_alerts.admin_emails' => 'romark7bayan@gmail.com',
        'services.signal_alerts.actions' => 'BUY,SELL',
    ]);
});

function goldApiBars(string $interval, bool $sell = false): array
{
    $at = Carbon::parse('2026-09-14T10:00:00Z');
    $minutes = $interval === '1h' ? 60 : 15;
    $bars = [];
    for ($i = 0; $i < 300; $i++) {
        $close = $minutes === 60 ? 1970 + $i * 0.1 : 1999;
        $bars[] = [
            'timestamp' => $at->copy()->subMinutes((300 - $i) * $minutes)->toIso8601String(),
            'open' => $close - 0.1, 'high' => $close + 1, 'low' => $close - 1, 'close' => $close,
        ];
    }
    if ($minutes === 15) {
        $bars[299] = array_replace($bars[299], ['open' => 1999, 'high' => 2002, 'low' => 1998.8, 'close' => 2001.5]);
    }

    return $sell ? array_map(fn ($bar) => [
        'timestamp' => $bar['timestamp'], 'open' => 4000 - $bar['open'], 'high' => 4000 - $bar['low'],
        'low' => 4000 - $bar['high'], 'close' => 4000 - $bar['close'],
    ], $bars) : $bars;
}

function goldApiPayload(string $interval, bool $sell = false): array
{
    return [
        'status' => 'ok',
        'meta' => ['symbol' => 'XAU/USD', 'interval' => $interval, 'exchange_timezone' => 'UTC'],
        'values' => array_map(fn ($bar) => [
            'datetime' => Carbon::parse($bar['timestamp'])->utc()->format('Y-m-d H:i:s'),
            'open' => (string) $bar['open'], 'high' => (string) $bar['high'], 'low' => (string) $bar['low'], 'close' => (string) $bar['close'],
        ], goldApiBars($interval, $sell)),
    ];
}

function fakeGoldApi(bool $sell = false): void
{
    Http::fake(['api.twelvedata.com/time_series*' => fn ($request) => Http::response(goldApiPayload($request['interval'], $sell))]);
}

test('API gold signals preserve the strategy rules and identify the actual source without MT5', function (bool $sell) {
    fakeGoldApi($sell);
    $this->mock(Mt5BridgeClient::class)->shouldNotReceive('snapshot');

    $signal = app(TradingService::class)->getSignalForStrategy('gold-risk-breakout');
    $expected = app(GoldBreakoutStrategy::class)->evaluate(goldApiBars('15min', $sell), goldApiBars('1h', $sell), now()->utc());

    expect($signal['action'])->toBe($sell ? 'SELL' : 'BUY')
        ->and($signal['trade_plan'])->toBe($expected['trade_plan'])
        ->and($signal['timestamp'])->toBe($expected['timestamp'])
        ->and($signal['indicators']['data_source'])->toBe('Twelve Data XAU/USD')
        ->and($signal)->not->toHaveKey('broker');
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['symbol'] === 'XAU/USD' && $request['interval'] === '15min' && $request['timezone'] === 'UTC');
    Http::assertSent(fn ($request) => $request['symbol'] === 'XAU/USD' && $request['interval'] === '1h' && $request['outputsize'] === 300);
})->with([false, true]);

test('the new strategy sends real API-derived entry plans through admin email delivery', function () {
    fakeGoldApi();
    $service = app(SignalAlertService::class);

    expect($service->dispatch()['sent'])->toBe(1);
    expect($service->dispatch()['skipped'])->toBe(1);
    Mail::assertSent(StrategySignalAlertMail::class, 1);
    Mail::assertSent(StrategySignalAlertMail::class, function ($mail) {
        $mail->assertSeeInHtml('Twelve Data XAU/USD');

        return $mail->hasTo('romark7bayan@gmail.com') && $mail->strategy->slug === 'gold-risk-breakout'
            && $mail->signal['action'] === 'BUY' && $mail->signal['trade_plan']['stop_loss'] < $mail->signal['trade_plan']['entry_price'];
    });
    expect(AutoTradeOrder::count())->toBe(0);
    Http::assertSentCount(2);
});

test('forming API candles do not influence the entry signal', function () {
    Http::fake(['api.twelvedata.com/time_series*' => function ($request) {
        $data = goldApiPayload($request['interval']);
        $data['values'][] = ['datetime' => '2026-09-14 10:00:00', 'open' => '2000', 'high' => '9000', 'low' => '1', 'close' => '8000'];

        return Http::response($data);
    }]);
    $signal = app(TradingService::class)->getSignalForStrategy('gold-risk-breakout');

    expect($signal['action'])->toBe('BUY')->and($signal['trade_plan']['entry_price'])->toBe(2001.5)
        ->and($signal['timestamp'])->toBe('2026-09-14T10:00:00+00:00');
});

test('cached candles do not extend the three-minute entry window', function () {
    fakeGoldApi();
    $service = app(TradingService::class);
    expect($service->getSignalForStrategy('gold-risk-breakout')['action'])->toBe('BUY');
    $this->travelTo(Carbon::parse('2026-09-14T10:03:00Z'));

    expect($service->getSignalForStrategy('gold-risk-breakout')['action'])->toBe('NO_DATA');
    expect(app(SignalAlertService::class)->dispatch(force: true)['sent'])->toBe(0);
    Mail::assertNothingSent();
    Http::assertSentCount(2);
});

test('each API timeframe refreshes when its next candle closes', function () {
    fakeGoldApi();
    $service = app(TradingService::class);
    $service->getSignalForStrategy('gold-risk-breakout');
    $this->travelTo(Carbon::parse('2026-09-14T10:15:01Z'));
    $service->getSignalForStrategy('gold-risk-breakout');
    Http::assertSentCount(3);
    $this->travelTo(Carbon::parse('2026-09-14T11:00:01Z'));
    $service->getSignalForStrategy('gold-risk-breakout');
    Http::assertSentCount(5);
});

test('a delayed API candle is retried rather than cached for the full interval', function () {
    $calls = 0;
    Http::fake(['api.twelvedata.com/time_series*' => function ($request) use (&$calls) {
        $data = goldApiPayload($request['interval']);
        if ($request['interval'] === '15min' && ++$calls === 1) {
            array_pop($data['values']);
        }

        return Http::response($data);
    }]);
    $service = app(TradingService::class);
    expect($service->getSignalForStrategy('gold-risk-breakout')['action'])->toBe('NO_DATA');
    $this->travel(31)->seconds();
    expect($service->getSignalForStrategy('gold-risk-breakout')['action'])->toBe('BUY');
    Http::assertSentCount(3);
});

test('provider failures do not fall back to a different gold instrument or broker feed', function () {
    Http::fake(['api.twelvedata.com/time_series*' => Http::response(['status' => 'error', 'message' => 'API quota exceeded'])]);
    $this->mock(Mt5BridgeClient::class)->shouldNotReceive('snapshot');

    $signal = app(TradingService::class)->getSignalForStrategy('gold-risk-breakout');

    expect($signal['action'])->toBe('NO_DATA')->and($signal['error'])->toBe('API quota exceeded');
    Http::assertSentCount(1);
});

test('incomplete OHLC data cannot produce an entry email', function () {
    Http::fake(['api.twelvedata.com/time_series*' => function ($request) {
        $data = goldApiPayload($request['interval']);
        unset($data['values'][299]['high']);

        return Http::response($data);
    }]);

    expect(app(SignalAlertService::class)->dispatch()['sent'])->toBe(0);
    Mail::assertNothingSent();
});

test('MT5 signal mode remains available and retains broker metadata', function () {
    config(['gold_breakout.signal_source' => 'mt5']);
    $this->mock(Mt5BridgeClient::class)->shouldReceive('snapshot')->once()->andReturn([
        'm15' => goldApiBars('15min'), 'h1' => goldApiBars('1h'), 'account_key' => 'test-account', 'symbol' => 'XAUUSD',
    ]);

    $signal = app(TradingService::class)->getSignalForStrategy('gold-risk-breakout');

    expect($signal['action'])->toBe('BUY')->and($signal['indicators']['data_source'])->toBe('VT Markets MT5')
        ->and($signal['broker']['account_key'])->toBe('test-account');
    Http::assertNothingSent();
});

test('API email mode cannot bypass the MT5 requirement for broker execution', function () {
    $this->mock(Mt5BridgeClient::class)->shouldReceive('snapshot')->once()->andThrow(new RuntimeException('MT5 is not connected'));
    $service = app(AutoTraderService::class);
    $settings = $service->settings();
    $settings->update(['enabled' => true, 'execution_mode' => 'bridge', 'strategy_slug' => 'gold-risk-breakout', 'pair_code' => 'XAUUSD']);

    $result = $service->tick();

    expect($result['status'])->toBe('rejected')->and($result['message'])->toBe('MT5 is not connected')
        ->and(AutoTradeOrder::count())->toBe(0);
    Http::assertNothingSent();
});

test('unsupported source settings fail without requesting market data', function () {
    config(['gold_breakout.signal_source' => 'unknown']);

    expect(app(TradingService::class)->getSignalForStrategy('gold-risk-breakout')['action'])->toBe('NO_DATA');
    Http::assertNothingSent();
});
