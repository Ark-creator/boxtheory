<?php

use App\Services\MarketDataGuard;
use App\Services\RsiGoldScalperV2Strategy;
use App\Services\TradingService;
use Illuminate\Support\Carbon;

function scalperV2Fixture(string $decision = '2026-11-02T12:15:00Z', int $count = 203): array
{
    $guard = new MarketDataGuard;
    $at = Carbon::parse($decision);
    $cursor = $at->copy()->subMinutes(5);
    $opens = [];
    while (count($opens) < $count) {
        if ($guard->marketStatus('XAUUSD', $cursor)['open'] && $guard->marketStatus('XAUUSD', $cursor->copy()->addSeconds(299))['open']) {
            $opens[] = $cursor->toIso8601String();
        }
        $cursor->subMinutes(5);
    }
    $bars = [];
    foreach (array_reverse($opens) as $i => $open) {
        // Fixed synthetic bullish crossover with RSI55.12, not market-performance fitting.
        $price = 2000 + 0.01 * $i + 2 * sin(($i + 20) * 0.2) + ($i % 2 ? -0.8 : 0.8);
        $bars[] = ['timestamp' => $open, 'open' => $price, 'high' => $price + 1, 'low' => $price - 1, 'close' => $price];
    }

    return [$bars, $at->copy()->addSeconds(20)];
}

function scalperV2Mirror(array $bars): array
{
    return array_map(fn ($b) => array_replace($b, ['open' => 4000 - $b['open'], 'high' => 4000 - $b['low'],
        'low' => 4000 - $b['high'], 'close' => 4000 - $b['close']]), $bars);
}

function scalperV2Close(array $bar, float $price): array
{
    return array_replace($bar, ['open' => $price, 'high' => $price + 1, 'low' => $price - 1, 'close' => $price]);
}

test('RSI V2 preserves actual legacy BUY and mirrored SELL plans when symmetric trend qualifies', function (bool $short) {
    [$bars, $at] = scalperV2Fixture();
    if ($short) {
        $bars = scalperV2Mirror($bars);
    }
    $source = 'Synthetic source';
    $legacy = (new TradingService)->evaluateRsiMovingAverage(array_slice($bars, -200), null, [], $source);
    $result = (new RsiGoldScalperV2Strategy)->evaluate($bars, null, $source, $at);
    expect($legacy['action'])->toBe($short ? 'SELL' : 'BUY')
        ->and($result['action'])->toBe($legacy['action'])
        ->and($result['strategy'])->toBe('rsi-gold-scalper-v2')
        ->and($result['research_only'])->toBeTrue()
        ->and($result['indicators']['data_source'])->toBe($source)
        ->and($result['indicators'][$short ? 'trend_down_confirmed' : 'trend_up_confirmed'])->toBeTrue();
    foreach (['entry_price', 'stop_loss', 'take_profit_1', 'risk_per_unit', 'rr_tp1'] as $field) {
        expect($result['trade_plan'][$field])->toBe($legacy['trade_plan'][$field]);
    }
    expect($result['trade_plan']['rr_tp1'])->toBe(1.5)
        ->and($legacy['trade_plan']['rr_tp2'])->toBe(2.5)->and($legacy['trade_plan']['take_profit_2'])->not->toBeNull()
        ->and($result['trade_plan']['rr_tp2'])->toBeNull()->and($result['trade_plan']['take_profit_2'])->toBeNull()
        ->and($result['trade_plan']['exit_policy'])->toBe('full_tp1');
})->with([false, true]);

test('RSI V2 rejects actual countertrend crossovers and removes their executable plans', function (bool $short) {
    [$bars, $at] = scalperV2Fixture();
    for ($i = 0; $i < 179; $i++) {
        $bars[$i] = scalperV2Close($bars[$i], 2050 - $i * 0.2);
    }
    if ($short) {
        $bars = scalperV2Mirror($bars);
    }
    $legacy = (new TradingService)->evaluateRsiMovingAverage($bars);
    $result = (new RsiGoldScalperV2Strategy)->evaluate($bars, null, 'Synthetic', $at);
    expect($legacy['action'])->toBe($short ? 'SELL' : 'BUY')
        ->and($result['action'])->toBe('HOLD')
        ->and($result['indicators']['rejected_crossover'])->toBe($legacy['action'])
        ->and($result['trade_plan']['side'])->toBe('wait')
        ->and($result['trade_plan']['entry_price'])->toBeNull()
        ->and($result['trade_plan']['stop_loss'])->toBeNull()
        ->and($result['trade_plan']['take_profit_1'])->toBeNull()
        ->and($result['trade_plan']['take_profit_2'])->toBeNull()
        ->and($result['research_only'])->toBeTrue();
})->with([false, true]);

test('RSI V2 treats an equal SMA200 slope as flat despite summation noise', function () {
    [$bars, $at] = scalperV2Fixture();
    for ($i = 0; $i < 3; $i++) {
        $bars[$i] = scalperV2Close($bars[$i], $bars[200 + $i]['close'] + 1e-10);
    }
    expect((new TradingService)->evaluateRsiMovingAverage($bars)['action'])->toBe('BUY');
    $result = (new RsiGoldScalperV2Strategy)->evaluate($bars, null, 'Synthetic', $at);
    expect($result['action'])->toBe('HOLD')
        ->and($result['indicators']['trend_sma_200'])->toBe($result['indicators']['trend_sma_200_prior'])
        ->and($result['indicators']['trend_slope'])->toBe(0.0)
        ->and($result['indicators']['trend_up_confirmed'])->toBeFalse()
        ->and($result['indicators']['trend_down_confirmed'])->toBeFalse();
});

test('flat prices cannot invent a trend or entry and full entry history is required', function () {
    [$bars, $at] = scalperV2Fixture();
    $flat = array_map(fn ($b) => scalperV2Close($b, 2000), $bars);
    $engine = new RsiGoldScalperV2Strategy;
    $result = $engine->evaluate($flat, null, 'Synthetic', $at);
    expect($result['action'])->toBe('HOLD')->and($result['trend'])->toBe('flat')
        ->and($result['research_only'])->toBeTrue();
    $shortHistory = $engine->evaluate(array_slice($bars, 1), null, 'Synthetic', $at);
    expect($shortHistory['action'])->toBe('NO_DATA')
        ->and($shortHistory['message'])->toContain('203')
        ->and($shortHistory['research_only'])->toBeTrue();
});

test('forming and future M5 candles cannot contaminate a confirmed RSI V2 decision', function () {
    [$bars, $at] = scalperV2Fixture();
    $engine = new RsiGoldScalperV2Strategy;
    $before = $engine->evaluate($bars, null, 'Synthetic', $at);
    $bars[] = ['timestamp' => $at->copy()->startOfMinute()->toIso8601String(), 'open' => 2000, 'high' => 9000, 'low' => 1, 'close' => 8000];
    $bars[] = ['timestamp' => $at->copy()->startOfMinute()->addMinutes(5)->toIso8601String(), 'open' => 8000, 'high' => 10000, 'low' => 1, 'close' => 9000];
    expect($engine->evaluate($bars, null, 'Synthetic', $at))->toBe($before);
});

test('RSI V2 fails closed for stale malformed duplicate or invalid source candles', function (string $fault) {
    [$bars, $at] = scalperV2Fixture();
    match ($fault) {
        'stale' => $at->addMinutes(7),
        'OHLC' => $bars[100]['high'] = 1,
        'duplicate' => $bars[] = $bars[array_key_last($bars)],
        'misaligned' => $bars[202]['timestamp'] = Carbon::parse($bars[202]['timestamp'])->addMinute()->toIso8601String(),
        'calendar' => $bars[100]['timestamp'] = '2026-02-30T12:00:00Z',
    };
    $result = (new RsiGoldScalperV2Strategy)->evaluate($bars, null, 'Synthetic', $at);
    expect($result['action'])->toBe('NO_DATA')->and($result['error'])->not->toBeNull()
        ->and($result['trade_plan'])->toBe([])->and($result['research_only'])->toBeTrue();
})->with(['stale', 'OHLC', 'duplicate', 'misaligned', 'calendar']);

test('known-position CLOSE survives missing trend history and the optional entry-session filter', function (string $position) {
    [$bars, $at] = scalperV2Fixture('2026-11-02T06:55:00Z', 30);
    foreach ($bars as $i => &$bar) {
        $bar = scalperV2Close($bar, 2000 + ($position === 'long' ? 1 : -1) * $i * 0.2);
    }
    unset($bar);
    $legacy = (new TradingService)->evaluateRsiMovingAverage($bars, $position);
    $result = (new RsiGoldScalperV2Strategy)->evaluate($bars, $position, 'Synthetic', $at, true);
    expect($legacy['action'])->toBe('CLOSE')->and($result['action'])->toBe('CLOSE')
        ->and($result['trade_plan']['side'])->toBe($position === 'long' ? 'close_long' : 'close_short')
        ->and($result['research_only'])->toBeTrue();
})->with(['long', 'short']);

test('known positions do not admit another same-direction RSI V2 entry', function (bool $short) {
    [$bars, $at] = scalperV2Fixture();
    if ($short) {
        $bars = scalperV2Mirror($bars);
    }
    $position = $short ? 'short' : 'long';
    expect((new TradingService)->evaluateRsiMovingAverage($bars, $position)['action'])->toBe($short ? 'SELL' : 'BUY');
    $result = (new RsiGoldScalperV2Strategy)->evaluate($bars, $position, 'Synthetic', $at);
    expect($result['action'])->toBe('HOLD')->and($result['trade_plan']['entry_price'])->toBeNull()
        ->and($result['message'])->toContain('already open')->and($result['research_only'])->toBeTrue();
})->with([false, true]);

test('optional RSI V2 session uses the candle close rather than candle open', function (string $decision, string $expected) {
    [$bars, $at] = scalperV2Fixture($decision);
    $engine = new RsiGoldScalperV2Strategy;
    expect($engine->evaluate($bars, null, 'Synthetic', $at)['action'])->toBe('BUY');
    $restricted = $engine->evaluate($bars, null, 'Synthetic', $at, true);
    expect($restricted['action'])->toBe($expected)
        ->and($restricted['indicators']['entry_session_allowed'])->toBe($expected === 'BUY')
        ->and($restricted['research_only'])->toBeTrue();
    if ($expected === 'HOLD') {
        expect($restricted['trade_plan']['entry_price'])->toBeNull();
    }
})->with([
    ['2026-11-02T06:55:00Z', 'HOLD'],
    ['2026-11-02T07:00:00Z', 'BUY'],
    ['2026-11-02T16:55:00Z', 'BUY'],
    ['2026-11-02T17:00:00Z', 'HOLD'],
    ['2026-11-01T23:05:00Z', 'HOLD'],
]);
