<?php

use App\Services\MarketDataGuard;
use App\Services\RsiScalperMinuteBacktester;
use App\Services\TradingService;
use Illuminate\Support\Carbon;

function rsiMinuteBar(string $time, float $open = 2000, float $high = 2002, float $low = 1998, float $close = 2000, float $points = 20): array
{
    return ['timestamp' => $time, 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'spread_points' => $points];
}

function rsiMinuteFixture(array $tail = [], int $count = 200, bool $oscillate = false): array
{
    $guard = new MarketDataGuard;
    $cursor = Carbon::parse('2026-09-14T09:55:00Z');
    $opens = [];
    while (count($opens) < $count) {
        if ($guard->marketStatus('XAUUSD', $cursor)['open'] && $guard->marketStatus('XAUUSD', $cursor->copy()->addSeconds(299))['open']) {
            $opens[] = $cursor->toIso8601String();
        }
        $cursor->subMinutes(5);
    }
    $m1 = $m5 = [];
    foreach (array_reverse($opens) as $i => $open) {
        $close = $oscillate ? 2000 + 2 * sin($i * 0.2) + ($i % 2 ? 0.8 : -0.8) : 2000;
        $m5[] = ['timestamp' => $open, 'open' => $close, 'high' => $close + 1, 'low' => $close - 1, 'close' => $close];
        for ($minute = 0; $minute < 5; $minute++) {
            $m1[] = rsiMinuteBar(Carbon::parse($open)->addMinutes($minute)->toIso8601String(), $close,
                $minute === 0 ? $close + 1 : $close, $minute === 0 ? $close - 1 : $close, $close);
        }
    }

    return ['source' => 'mt5', 'symbol' => 'XAUUSD-STD', 'timestamp_assumption' => 'Synthetic UTC', 'm1' => array_merge($m1, $tail), 'm5' => $m5];
}

function rsiMinuteSignal(string $decision = '2026-09-14T10:00:00+00:00', bool $short = false, bool $close = false): array
{
    return ['strategy' => 'rsi_moving_average', 'action' => $close ? 'CLOSE' : ($short ? 'SELL' : 'BUY'),
        'timestamp' => Carbon::parse($decision)->subMinutes(5)->toIso8601String(),
        'trade_plan' => ['entry_price' => 2000, 'stop_loss' => $short ? 2010 : 1990,
            'take_profit_1' => $short ? 1985 : 2015, 'take_profit_2' => $short ? 1975 : 2025]];
}

function rsiMinutePrepared(array $data, array $entries, array $long = [], array $short = []): array
{
    $prepared = (new RsiScalperMinuteBacktester)->prepare($data);
    $prepared['entries'] = $entries;
    $prepared['close_long'] = $long;
    $prepared['close_short'] = $short;

    return $prepared;
}

function rsiMinuteOptions(array $overrides = []): array
{
    return array_replace(['point_size' => 0.01, 'slippage' => 0.1, 'fee' => 0.07,
        'spread_multiplier' => 1, 'target' => 'tp1', 'position_aware' => false, 'from' => null, 'to' => null], $overrides);
}

test('RSI preparation calls the actual evaluator with unchanged rolling 200 bar defaults', function () {
    $data = rsiMinuteFixture(count: 260, oscillate: true);
    $prepared = (new RsiScalperMinuteBacktester)->prepare($data);
    $expected = ['entries' => [], 'close_long' => [], 'close_short' => []];
    $strategy = new TradingService;
    for ($i = 199; $i < count($data['m5']); $i++) {
        $window = array_slice($data['m5'], $i - 199, 200);
        $at = Carbon::parse($data['m5'][$i]['timestamp'])->addMinutes(5)->toIso8601String();
        foreach (['entries' => null, 'close_long' => 'long', 'close_short' => 'short'] as $map => $position) {
            $signal = $strategy->evaluateRsiMovingAverage($window, $position, [], 'MT5 XAUUSD-STD');
            if ($map === 'entries' ? in_array($signal['action'], ['BUY', 'SELL'], true) : $signal['action'] === 'CLOSE') {
                $expected[$map][$at] = $signal;
            }
        }
    }
    expect($prepared['entries'])->not->toBeEmpty()
        ->and($prepared['entries'])->toBe($expected['entries'])
        ->and($prepared['close_long'])->toBe($expected['close_long'])
        ->and($prepared['close_short'])->toBe($expected['close_short'])
        ->and($prepared['diagnostics']['evaluated_decisions'])->toBe(61);
});

test('RSI preparation excludes forming bars and preserves earlier causal decisions', function () {
    $data = rsiMinuteFixture(count: 240, oscillate: true);
    $tester = new RsiScalperMinuteBacktester;
    $before = $tester->prepare($data);
    $data['m5'][] = ['timestamp' => '2026-09-14T10:00:00Z', 'open' => 2000, 'high' => 9000, 'low' => 1, 'close' => 8000];
    $data['m1'][] = rsiMinuteBar('2026-09-14T10:00:00Z', 2000, 9000, 1, 8000);
    $after = $tester->prepare($data);
    foreach (['entries', 'close_long', 'close_short'] as $field) {
        expect($after[$field])->toBe($before[$field]);
    }
    expect($tester->prepare(rsiMinuteFixture(count: 199))['diagnostics']['evaluated_decisions'])->toBe(0);
});

test('RSI preparation removes closed-session M5 bars before indicators', function () {
    $data = rsiMinuteFixture([rsiMinuteBar('2026-09-14T22:00:00Z')]);
    $data['m5'][] = ['timestamp' => '2026-09-14T21:00:00Z', 'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000];
    $prepared = (new RsiScalperMinuteBacktester)->prepare($data);
    expect($prepared['diagnostics']['closed_session_m5_removed'])->toBe(1)
        ->and($prepared['diagnostics']['active_m5_bars'])->toBe(200);
});

test('RSI market fills apply ask spread slippage and fees to the original absolute TP1', function () {
    $signal = rsiMinuteSignal();
    $at = '2026-09-14T10:00:00+00:00';
    $data = rsiMinuteFixture([rsiMinuteBar($at, high: 2016)]);
    $report = (new RsiScalperMinuteBacktester)->run($data, rsiMinutePrepared($data, [$at => $signal]), rsiMinuteOptions());
    expect($report['metrics']['wins'])->toBe(1)
        ->and($report['trades'][0]['entry'])->toBe(2000.3)
        ->and($report['trades'][0]['exit'])->toBe(2014.9)
        ->and($report['trades'][0]['net_r'])->toEqualWithDelta(14.53 / 10.3, 1e-9)
        ->and($report['trades'][0]['signal_timestamp_open'])->toBe('2026-09-14T09:55:00+00:00');
});

test('RSI uses the actual prefill quote for 0.1R without inventing a postfill drift limit', function () {
    $at = '2026-09-14T10:00:00+00:00';
    $signal = rsiMinuteSignal();
    $tester = new RsiScalperMinuteBacktester;
    $data = rsiMinuteFixture([rsiMinuteBar($at, open: 2000.7, high: 2016)]);
    $accepted = $tester->run($data, rsiMinutePrepared($data, [$at => $signal]), rsiMinuteOptions(['slippage' => 0.2]));
    expect($accepted['metrics']['closed_trades'])->toBe(1)
        ->and($accepted['trades'][0]['entry_quote_drift_r'])->toEqualWithDelta(0.09, 1e-9)
        ->and(($accepted['trades'][0]['entry'] - 2000) / 10)->toBeGreaterThan(0.1);
    $data = rsiMinuteFixture([rsiMinuteBar($at, open: 2000.9, high: 2016)]);
    $rejected = $tester->run($data, rsiMinutePrepared($data, [$at => $signal]), rsiMinuteOptions());
    expect($rejected['entry_rejections']['quote_drift'])->toBe(1);
});

test('TP2 keeps the live TP1 prequote bracket guard and alternative full target', function () {
    $at = '2026-09-14T10:00:00+00:00';
    $signal = rsiMinuteSignal();
    $tester = new RsiScalperMinuteBacktester;
    $data = rsiMinuteFixture([rsiMinuteBar($at, high: 2016)]);
    $tp2 = $tester->run($data, rsiMinutePrepared($data, [$at => $signal]), rsiMinuteOptions(['target' => 'tp2']));
    expect($tp2['metrics']['closed_trades'])->toBe(0)->and($tp2['open_position_at_end']['tp'])->toBe(2025.0);
    $data = rsiMinuteFixture([rsiMinuteBar($at, open: 2016, high: 2018, low: 2015, close: 2016)]);
    $invalid = $tester->run($data, rsiMinutePrepared($data, [$at => $signal]), rsiMinuteOptions(['target' => 'tp2']));
    expect($invalid['entry_rejections']['quote_bracket'])->toBe(1);
});

test('short RSI targets require the current minute ask path', function () {
    $at = '2026-09-14T10:00:00+00:00';
    $data = rsiMinuteFixture([
        rsiMinuteBar($at, low: 1985, close: 1986),
        rsiMinuteBar('2026-09-14T10:01:00Z', open: 1986, high: 1987, low: 1984, close: 1985, points: 30),
    ]);
    $report = (new RsiScalperMinuteBacktester)->run($data, rsiMinutePrepared($data, [$at => rsiMinuteSignal(short: true)]), rsiMinuteOptions());
    expect($report['trades'][0]['exit_minute_open'])->toBe('2026-09-14T10:01:00+00:00')
        ->and($report['trades'][0]['exit'])->toBe(1985.1)
        ->and($report['metrics']['net_r'])->toEqualWithDelta(14.73 / 10.1, 1e-9);
});

test('managed RSI CLOSE executes at the causal next open before later intraminute extremes', function () {
    $at = '2026-09-14T10:00:00+00:00';
    $closeAt = '2026-09-14T10:05:00+00:00';
    $data = rsiMinuteFixture([rsiMinuteBar($at), rsiMinuteBar($closeAt, open: 2001, high: 2030, low: 1980, close: 2000)]);
    $prepared = rsiMinutePrepared($data, [$at => rsiMinuteSignal(), $closeAt => rsiMinuteSignal($closeAt, short: true)], [$closeAt => rsiMinuteSignal($closeAt, close: true)]);
    $report = (new RsiScalperMinuteBacktester)->run($data, $prepared, rsiMinuteOptions(['position_aware' => true, 'spread_multiplier' => 0, 'slippage' => 0, 'fee' => 0]));
    expect($report['metrics']['closed_trades'])->toBe(1)->and($report['trades'][0]['exit_reason'])->toBe('strategy_close')
        ->and($report['trades'][0]['exit'])->toBe(2001.0)->and($report['ambiguous_minutes_stop_first'])->toBe(0)
        ->and($report['entry_rejections']['existing_position'])->toBe(1)->and($report['open_position_at_end'])->toBeNull();
});

test('opening broker bracket takes priority over RSI CLOSE without same-minute reversal', function () {
    $at = '2026-09-14T10:00:00+00:00';
    $closeAt = '2026-09-14T10:05:00+00:00';
    $data = rsiMinuteFixture([rsiMinuteBar($at), rsiMinuteBar($closeAt, open: 2020, high: 2022, low: 2018, close: 2020)]);
    $prepared = rsiMinutePrepared($data, [$at => rsiMinuteSignal(), $closeAt => rsiMinuteSignal($closeAt, short: true)], [$closeAt => rsiMinuteSignal($closeAt, close: true)]);
    $report = (new RsiScalperMinuteBacktester)->run($data, $prepared, rsiMinuteOptions(['position_aware' => true, 'spread_multiplier' => 0, 'slippage' => 0, 'fee' => 0]));
    expect($report['trades'][0]['exit_reason'])->toBe('gap_target')->and($report['trades'][0]['exit'])->toBe(2015.0)
        ->and($report['strategy_closes_superseded_by_opening_bracket'])->toBe(1)
        ->and($report['entry_rejections']['existing_position'])->toBe(1)->and($report['metrics']['closed_trades'])->toBe(1);
});

test('manual CLOSE and new RSI entries are blocked during maintenance', function () {
    $at = '2026-09-14T10:00:00+00:00';
    $closedAt = '2026-09-14T21:00:00+00:00';
    $data = rsiMinuteFixture([rsiMinuteBar($at), rsiMinuteBar($closedAt)]);
    $prepared = rsiMinutePrepared($data, [$at => rsiMinuteSignal()], [$closedAt => rsiMinuteSignal($closedAt, close: true)]);
    $tester = new RsiScalperMinuteBacktester;
    $report = $tester->run($data, $prepared, rsiMinuteOptions(['position_aware' => true]));
    expect($report['strategy_closes_blocked_market_closed'])->toBe(1)->and($report['open_position_at_end'])->not->toBeNull();
    $prepared = rsiMinutePrepared($data, [$closedAt => rsiMinuteSignal($closedAt)]);
    expect($tester->run($data, $prepared, rsiMinuteOptions())['entry_rejections']['market_closed'])->toBe(1);
});

test('RSI diagnostic preserves ambiguous stop-first losses gap flags and adverse opening fills', function () {
    $at = '2026-09-14T10:00:00+00:00';
    $tester = new RsiScalperMinuteBacktester;
    $options = rsiMinuteOptions(['spread_multiplier' => 0, 'slippage' => 0, 'fee' => 0]);
    $data = rsiMinuteFixture([rsiMinuteBar($at, high: 2020, low: 1980)]);
    $both = $tester->run($data, rsiMinutePrepared($data, [$at => rsiMinuteSignal()]), $options);
    expect($both['ambiguous_minutes_stop_first'])->toBe(1)->and($both['metrics']['net_r'])->toBe(-1.0);
    $data = rsiMinuteFixture([rsiMinuteBar($at), rsiMinuteBar('2026-09-14T10:02:00Z', open: 1985, high: 1988, low: 1980, close: 1982)]);
    $gap = $tester->run($data, rsiMinutePrepared($data, [$at => rsiMinuteSignal()]), $options);
    expect($gap['trades'][0]['exit_reason'])->toBe('gap_stop')->and($gap['metrics']['net_r'])->toBe(-1.5)
        ->and($gap['trades_crossing_nonconsecutive_minutes'])->toBe(1)->and($gap['contiguous_path_metrics']['closed_trades'])->toBe(0);
});

test('missing immediate RSI entry opens are never deferred and evaluation-end positions stay open', function () {
    $at = '2026-09-14T10:00:00+00:00';
    $tester = new RsiScalperMinuteBacktester;
    $data = rsiMinuteFixture([rsiMinuteBar('2026-09-14T10:01:00Z')]);
    $missed = $tester->run($data, rsiMinutePrepared($data, [$at => rsiMinuteSignal()]), rsiMinuteOptions());
    expect($missed['entry_rejections']['no_immediate_next_open'])->toBe(1)->and($missed['open_position_at_end'])->toBeNull();
    $data = rsiMinuteFixture([rsiMinuteBar($at), rsiMinuteBar('2026-09-14T10:05:00Z', high: 2016)]);
    $censored = $tester->run($data, rsiMinutePrepared($data, [$at => rsiMinuteSignal()]), rsiMinuteOptions(['to' => '2026-09-14T10:05:00Z']));
    expect($censored['metrics']['closed_trades'])->toBe(0)->and($censored['metrics']['win_rate'])->toBeNull()
        ->and($censored['open_position_at_end'])->not->toBeNull();
});

test('fees can make a full RSI target exit a net loss', function () {
    $at = '2026-09-14T10:00:00+00:00';
    $data = rsiMinuteFixture([rsiMinuteBar($at, high: 2016)]);
    $report = (new RsiScalperMinuteBacktester)->run($data, rsiMinutePrepared($data, [$at => rsiMinuteSignal()]), rsiMinuteOptions(['spread_multiplier' => 0, 'slippage' => 0, 'fee' => 20]));
    expect($report['metrics']['wins'])->toBe(0)->and($report['metrics']['losses'])->toBe(1)
        ->and($report['trades'][0]['exit_reason'])->toBe('take_profit')->and($report['metrics']['net_r'])->toBe(-0.5);
});

test('prepared UTC keys normalize consistently and duplicate instants are rejected', function () {
    $data = rsiMinuteFixture([rsiMinuteBar('2026-09-14T10:00:00Z', high: 2016)]);
    $tester = new RsiScalperMinuteBacktester;
    $prepared = rsiMinutePrepared($data, ['2026-09-14T10:00:00Z' => rsiMinuteSignal()]);
    expect($tester->run($data, $prepared, rsiMinuteOptions())['metrics']['closed_trades'])->toBe(1);
    $prepared['entries']['2026-09-14T10:00:00+00:00'] = rsiMinuteSignal();
    expect(fn () => $tester->run($data, $prepared, rsiMinuteOptions()))->toThrow(InvalidArgumentException::class);
});

test('RSI diagnostics reject invalid input provenance timestamps costs and policies', function () {
    $data = rsiMinuteFixture([rsiMinuteBar('2026-09-14T10:00:00Z')]);
    $tester = new RsiScalperMinuteBacktester;
    $prepared = rsiMinutePrepared($data, []);
    foreach ([['point_size' => 0], ['slippage' => NAN], ['fee' => -1], ['target' => 'best'], ['position_aware' => 'true'], ['to' => '2026-02-30T10:00:00Z']] as $bad) {
        expect(fn () => $tester->run($data, $prepared, rsiMinuteOptions($bad)))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $tester->run($data, $prepared, []))->toThrow(InvalidArgumentException::class);
    $changed = $data;
    $changed['symbol'] = 'XAUUSD-other';
    expect(fn () => $tester->run($changed, $prepared, rsiMinuteOptions()))->toThrow(InvalidArgumentException::class);
    $changed = $data;
    $changed['m1'][0]['timestamp'] = '2026-09-14T10:00:00';
    expect(fn () => $tester->prepare($changed))->toThrow(InvalidArgumentException::class);
});
