<?php

use App\Services\GoldBreakoutStrategy;
use App\Services\GoldStrategyBacktester;
use Illuminate\Support\Carbon;

function goldBars(string $start, int $count, int $minutes, float $base, float $step): array
{
    return array_map(function ($i) use ($start, $minutes, $base, $step) {
        $close = $base + $i * $step;

        return ['timestamp' => Carbon::parse($start)->addMinutes($i * $minutes)->toIso8601String(), 'open' => $close - 0.1, 'high' => $close + 1, 'low' => $close - 1, 'close' => $close];
    }, range(0, $count - 1));
}

function goldFixture(): array
{
    $at = Carbon::parse('2026-09-14T10:00:00Z');
    $h1 = goldBars($at->copy()->subHours(300)->toIso8601String(), 300, 60, 1970, 0.1);
    $m15 = goldBars($at->copy()->subMinutes(300 * 15)->toIso8601String(), 300, 15, 1999, 0);
    $m15[299] = array_replace($m15[299], ['open' => 1999, 'high' => 2002, 'low' => 1998.8, 'close' => 2001.5]);

    return [$m15, $h1, $at];
}

test('confirmed breakout produces a directional stop and full 2R target', function () {
    [$m15, $h1, $at] = goldFixture();
    $signal = (new GoldBreakoutStrategy)->evaluate($m15, $h1, $at);
    expect($signal['action'])->toBe('BUY');
    $plan = $signal['trade_plan'];
    expect($plan['stop_loss'])->toBeLessThan($plan['entry_price']);
    expect(round(($plan['take_profit_1'] - $plan['entry_price']) / ($plan['entry_price'] - $plan['stop_loss']), 4))->toBe(2.0);
    expect($signal['timestamp'])->toBe('2026-09-14T10:00:00+00:00');
});

test('future and forming candles cannot change a confirmed signal', function () {
    [$m15, $h1, $at] = goldFixture();
    $engine = new GoldBreakoutStrategy;
    $before = $engine->evaluate($m15, $h1, $at);
    $m15[] = ['timestamp' => $at->toIso8601String(), 'open' => 2000, 'high' => 9000, 'low' => 1, 'close' => 8000];
    $h1[] = ['timestamp' => $at->toIso8601String(), 'open' => 2000, 'high' => 9000, 'low' => 1, 'close' => 8000];
    expect($engine->evaluate($m15, $h1, $at))->toBe($before);
});

test('stale data, duplicate bars, existing exposure and off-session entries are blocked', function () {
    [$m15, $h1, $at] = goldFixture();
    $engine = new GoldBreakoutStrategy;
    expect($engine->evaluate($m15, $h1, $at->copy()->addMinutes(4))['action'])->toBe('NO_DATA');
    expect($engine->evaluate($m15, $h1, $at, 'long')['action'])->toBe('HOLD');
    $m15[] = end($m15);
    expect($engine->evaluate($m15, $h1, $at)['action'])->toBe('NO_DATA');
});

test('reversing the trend and price path produces a sell', function () {
    [$m15, $h1, $at] = goldFixture();
    $mirror = fn ($bars) => array_map(fn ($b) => ['timestamp' => $b['timestamp'], 'open' => 4000 - $b['open'], 'close' => 4000 - $b['close'], 'high' => 4000 - $b['low'], 'low' => 4000 - $b['high']], $bars);
    $signal = (new GoldBreakoutStrategy)->evaluate($mirror($m15), $mirror($h1), $at);
    expect($signal['action'])->toBe('SELL');
    expect($signal['trade_plan']['stop_loss'])->toBeGreaterThan($signal['price']);
});

test('historical simulator resolves a both-hit entry bar at the stop', function () {
    [$m15, $h1, $at] = goldFixture();
    $m15[] = ['timestamp' => $at->toIso8601String(), 'open' => 2001.5, 'high' => 2020, 'low' => 1980, 'close' => 2000];
    $report = (new GoldStrategyBacktester)->run(['source' => 'mt5', 'm15' => $m15, 'h1' => $h1], 0, 0, 0);
    expect($report['closed_trades'])->toBe(1);
    expect($report['ambiguous_bars_stop_first'])->toBe(1);
    expect($report['trades'][0]['net_r'])->toBe(-1.0);
});
