<?php

use App\Mail\StrategySignalAlertMail;
use App\Models\AutoTraderSetting;
use App\Models\Strategy;
use App\Services\GoldBreakoutStrategy;
use App\Services\LiveMarketDataService;
use App\Services\Mt5BridgeClient;
use App\Services\SignalAlertService;
use App\Services\TradingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02T14:00:10Z'));
    Cache::flush();
    Http::preventStrayRequests();
    Mail::fake();
    config(['services.twelvedata.key' => 'test-secret-key', 'gold_breakout.signal_source' => 'twelvedata', 'scalper.gold.version' => 'legacy',
        'services.signal_alerts.admin_emails' => 'test@example.com', 'services.signal_alerts.actions' => 'BUY,SELL']);
});

function livePipelinePair(): array
{
    return ['code' => 'XAUUSD', 'twelvedata_symbol' => 'XAU/USD'];
}

function livePipelineCandles(string $interval = '5min', int $shiftSeconds = 0): array
{
    $minutes = $interval === '1h' ? 60 : (int) $interval;
    $end = intdiv(now()->timestamp, $minutes * 60) * $minutes * 60 + $shiftSeconds;
    $values = [];
    for ($i = 80; $i > 0; $i--) {
        $values[] = ['datetime' => gmdate('Y-m-d H:i:s', $end - $i * $minutes * 60),
            'open' => '2000', 'high' => '2001', 'low' => '1999', 'close' => '2000'];
    }

    return ['status' => 'ok', 'meta' => ['symbol' => 'XAU/USD', 'interval' => $interval, 'exchange_timezone' => 'Australia/Sydney'], 'values' => $values];
}

function livePipelineQuote(array $changes = []): array
{
    return array_replace(['symbol' => 'XAU/USD', 'close' => '2000', 'is_market_open' => true,
        'timestamp' => now()->copy()->startOfDay()->timestamp, 'last_quote_at' => now()->copy()->startOfMinute()->timestamp], $changes);
}

function fakeLivePipeline(?array $candles = null, ?array $quote = null): void
{
    Http::fake(['api.twelvedata.com/time_series*' => fn ($r) => Http::response($candles ?? livePipelineCandles($r['interval'])),
        'api.twelvedata.com/quote*' => fn () => Http::response($quote ?? livePipelineQuote())]);
}

function livePipelineRsiSnapshot(array $candles): array
{
    return ['strategy' => 'rsi_moving_average', 'action' => 'BUY', 'price' => 2000,
        'timestamp' => $candles[array_key_last($candles)]['timestamp'], 'trend' => 'bullish', 'position' => 'flat',
        'message' => 'Test confirmed crossover', 'error' => null, 'indicators' => ['data_source' => 'twelvedata_xauusd'],
        'trade_plan' => ['entry_price' => 2000, 'stop_loss' => 1995, 'take_profit_1' => 2007.5, 'take_profit_2' => 2012.5]];
}

test('provider quote uses last_quote_at rather than the daily interval opening timestamp', function () {
    fakeLivePipeline();
    $quote = app(LiveMarketDataService::class)->quote(livePipelinePair());
    expect($quote['price'])->toBe(2000.0)->and($quote['observed_at'])->toBe('2026-11-02T14:00:00+00:00');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/quote') && $r['timezone'] === 'UTC' && $r['interval'] === '1min');
});

test('missing stale future closed or delayed provider quote cannot be called live', function (array $changes) {
    fakeLivePipeline(quote: livePipelineQuote($changes));
    expect(fn () => app(LiveMarketDataService::class)->quote(livePipelinePair()))->toThrow(RuntimeException::class);
})->with([
    'no source observation' => [['last_quote_at' => null]],
    'stale source observation' => [['last_quote_at' => Carbon::parse('2026-11-02T13:58:00Z')->timestamp]],
    'future observation' => [['last_quote_at' => Carbon::parse('2026-11-02T14:01:00Z')->timestamp]],
    'market closed' => [['is_market_open' => false]],
    'open flag missing' => [['is_market_open' => null]],
    'delayed entitlement' => [['is_delayed' => true]],
    'wrong symbol' => [['symbol' => 'GC=F']],
    'invalid price' => [['close' => 'NaN']],
]);

test('closed UTC bars use requested timezone and ignore forming prices', function () {
    $data = livePipelineCandles();
    $data['values'][] = ['datetime' => '2026-11-02 14:00:00', 'open' => '2000', 'high' => '9000', 'low' => '1', 'close' => '8000'];
    fakeLivePipeline($data);
    $bars = app(LiveMarketDataService::class)->candles(livePipelinePair(), '5min', 200)['candles'];
    expect($bars[array_key_last($bars)]['timestamp'])->toBe('2026-11-02T13:55:00+00:00')
        ->and($bars[array_key_last($bars)]['close'])->toBe(2000.0);
    Http::assertSent(fn ($r) => $r['timezone'] === 'UTC');
});

test('old candles mismatched identity missing OHLC and duplicate timestamps fail closed', function (string $defect) {
    $data = livePipelineCandles();
    if ($defect === 'stale') {
        $data = livePipelineCandles(shiftSeconds: -3600);
    }
    if ($defect === 'symbol') {
        $data['meta']['symbol'] = 'GC=F';
    }
    if ($defect === 'interval') {
        $data['meta']['interval'] = '1h';
    }
    if ($defect === 'OHLC') {
        unset($data['values'][79]['high']);
    }
    if ($defect === 'duplicate') {
        $data['values'][] = $data['values'][79];
    }
    fakeLivePipeline($data);
    $exception = in_array($defect, ['symbol', 'interval'], true) ? RuntimeException::class : InvalidArgumentException::class;
    expect(fn () => app(LiveMarketDataService::class)->candles(livePipelinePair(), '5min', 200))->toThrow($exception);
})->with(['stale', 'symbol', 'interval', 'OHLC', 'duplicate']);

test('provider errors never substitute Alpha Vantage Yahoo futures or fabricated prices', function () {
    Http::fake(['api.twelvedata.com/*' => Http::response(['status' => 'error', 'message' => 'Quota exceeded'], 200)]);
    $result = app(TradingService::class)->getSignalForStrategy('rsi-scalper');
    expect($result['action'])->toBe('NO_DATA')->and($result['error'])->toContain('Quota exceeded');
    Http::assertSentCount(1);
    Mail::assertNothingSent();
});

test('the exact Sunday from the reported emails fetches and sends nothing for every strategy', function (bool $force) {
    $this->travelTo(Carbon::parse('2026-11-01T15:49:00Z'));
    foreach (['rsi-scalper', 'conservative-v2', 'box-theory', 'gold-risk-breakout'] as $slug) {
        expect(app(TradingService::class)->getSignalForStrategy($slug)['action'])->toBe('NO_DATA');
    }
    expect(app(SignalAlertService::class)->dispatch(force: $force)['sent'])->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with([false, true]);

test('closed-session historical bars do not contaminate fresh indicators after reopening', function () {
    $data = livePipelineCandles();
    array_unshift($data['values'], ['datetime' => '2026-11-01 15:45:00', 'open' => '2000', 'high' => '2001', 'low' => '1999', 'close' => '2000']);
    fakeLivePipeline($data);
    $bars = app(LiveMarketDataService::class)->candles(livePipelinePair(), '5min', 200)['candles'];
    expect(array_column($bars, 'timestamp'))->not->toContain('2026-11-01T15:45:00+00:00');
});

test('a current closed-bar setup is attested and emailed with Philippine times', function () {
    Strategy::query()->delete();
    Strategy::create(['name' => 'RSI Gold Scalper', 'slug' => 'rsi-scalper', 'description' => 'Test', 'price' => 0]);
    fakeLivePipeline();
    $trading = Mockery::mock(TradingService::class)->makePartial();
    $trading->shouldReceive('evaluateRsiMovingAverage')->once()->andReturnUsing(fn ($bars) => livePipelineRsiSnapshot($bars));
    $this->app->instance(TradingService::class, $trading);
    expect(app(SignalAlertService::class)->dispatch()['sent'])->toBe(1);
    Mail::assertSent(StrategySignalAlertMail::class, function ($mail) {
        $mail->assertSeeInHtml('Provider quote at check');
        $mail->assertSeeInHtml('Confirmed candle close');
        $mail->assertSeeInHtml('2026-11-02 22:00:00');
        $mail->assertSeeInHtml('PHT (UTC+8)');

        return $mail->signal['freshness']['verified'] === true
            && $mail->signal['timestamp'] === '2026-11-02T14:00:00+00:00'
            && $mail->signal['expires_at'] === '2026-11-02T14:03:00+00:00';
    });
    Http::assertSentCount(2);
});

test('a plan whose live quote has already moved is not sent', function () {
    fakeLivePipeline(quote: livePipelineQuote(['close' => '2005']));
    $trading = Mockery::mock(TradingService::class)->makePartial();
    $trading->shouldReceive('evaluateRsiMovingAverage')->andReturnUsing(fn ($bars) => livePipelineRsiSnapshot($bars));
    expect($trading->getSignalForStrategy('rsi-scalper')['action'])->toBe('NO_DATA');
    Mail::assertNothingSent();
});

test('API work across a candle boundary cannot relabel an earlier setup as a new signal', function () {
    $this->travelTo(Carbon::parse('2026-11-02T14:04:59Z'));
    Http::fake(['api.twelvedata.com/time_series*' => Http::response(livePipelineCandles()),
        'api.twelvedata.com/quote*' => function () {
            Carbon::setTestNow(Carbon::parse('2026-11-02T14:05:01Z'));

            return Http::response(livePipelineQuote());
        }]);
    $trading = Mockery::mock(TradingService::class)->makePartial();
    $trading->shouldReceive('evaluateRsiMovingAverage')->andReturnUsing(fn ($bars) => livePipelineRsiSnapshot($bars));
    $signal = $trading->getSignalForStrategy('rsi-scalper');
    expect($signal['action'])->toBe('NO_DATA');
    Http::assertSentCount(2);
});

test('missing relative or invalid original signal time cannot be relabeled at a close', function (string $badTime) {
    $this->travelTo(Carbon::parse('2026-11-02T14:00:00Z'));
    fakeLivePipeline();
    $trading = Mockery::mock(TradingService::class)->makePartial();
    $trading->shouldReceive('evaluateRsiMovingAverage')->andReturnUsing(function ($bars) use ($badTime) {
        return array_replace(livePipelineRsiSnapshot($bars), ['timestamp' => $badTime]);
    });
    $signal = $trading->getSignalForStrategy('rsi-scalper');
    expect($signal['action'])->toBe('NO_DATA')->and($signal['error'])->toContain('no timestamp is relabeled');
})->with(['', 'now', '2026-10-33T14:00:00+00:00', '2026-11-02T13:50:00+00:00']);

test('read-only health check reports source ages without evaluating or emailing strategies', function () {
    fakeLivePipeline();
    $this->artisan('signals:check-data')->expectsOutputToContain('"emails_sent": 0')->assertSuccessful();
    Mail::assertNothingSent();
});

test('MT5 snapshot creation time cannot replace a missing or stale tick time', function (string $defect) {
    config(['gold_breakout.signal_source' => 'mt5']);
    $snapshot = ['symbol' => 'XAUUSD', 'account_key' => 'test-account', 'observed_at' => now()->toIso8601String(),
        'm15' => [['timestamp' => '2026-11-02T13:45:00+00:00', 'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000]],
        'h1' => [['timestamp' => '2026-11-02T13:00:00+00:00', 'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000]],
        'quote' => ['bid' => 2000, 'ask' => 2000.1, 'timestamp' => now()->toIso8601String()]];
    if ($defect === 'missing') {
        unset($snapshot['quote']);
    }
    if ($defect === 'stale') {
        $snapshot['quote']['timestamp'] = '2026-11-02T13:00:00+00:00';
    }
    if ($defect === 'wrong symbol') {
        $snapshot['symbol'] = 'EURUSD';
    }
    $this->mock(Mt5BridgeClient::class)->shouldReceive('snapshot')->once()->andReturn($snapshot);
    $this->mock(GoldBreakoutStrategy::class)->shouldReceive('evaluate')->zeroOrMoreTimes()->andReturn([
        'strategy' => 'gold-risk-breakout', 'action' => 'BUY', 'timestamp' => '2026-11-02T14:00:00+00:00', 'price' => 2000,
        'trade_plan' => ['entry_price' => 2000, 'stop_loss' => 1995, 'take_profit_1' => 2010]]);
    expect(app(TradingService::class)->getSignalForStrategy('gold-risk-breakout')['action'])->toBe('NO_DATA');
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with(['missing', 'stale', 'wrong symbol']);

test('config-only MT5 connection cannot relabel another instrument as gold', function () {
    AutoTraderSetting::query()->delete();
    config(['trading.auto_trader.bridge_url' => 'http://bridge.test', 'trading.auto_trader.bridge_token' => 'test-token']);
    Http::fake(['bridge.test/snapshot' => Http::response(['status' => 'ok', 'source' => 'mt5', 'account_key' => 'account',
        'capabilities' => ['entry_extension_limit'], 'symbol' => 'EURUSD', 'observed_at' => now()->toIso8601String()])]);
    expect(fn () => app(Mt5BridgeClient::class)->snapshot())->toThrow(RuntimeException::class, 'symbol differs');
});
