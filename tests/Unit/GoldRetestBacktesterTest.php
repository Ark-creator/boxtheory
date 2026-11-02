<?php

use App\Services\GoldRetestBacktester;
use App\Services\GoldTrendRetestStrategy;
use Illuminate\Support\Carbon;

function retestExecutionBar(string $time, float $open = 2000, float $high = 2002, float $low = 1998, float $close = 2000): array
{
    return ['timestamp' => $time, 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close];
}

function retestExecutionData(array $tail): array
{
    $at = Carbon::parse('2026-09-14T10:00:00Z');
    $m15 = [];
    $h1 = [];
    for ($i = 50; $i > 0; $i--) {
        $m15[] = retestExecutionBar($at->copy()->subMinutes($i * 15)->toIso8601String());
    }
    for ($i = 300; $i > 0; $i--) {
        $h1[] = retestExecutionBar($at->copy()->subHours($i)->toIso8601String());
    }

    return ['source' => 'mt5', 'symbol' => 'XAUUSD', 'm15' => array_merge($m15, $tail), 'h1' => $h1];
}

function retestExecutionSignal(string $time = '2026-09-14T10:00:00+00:00', string $id = 'setup-1', bool $short = false): array
{
    return [
        'action' => $short ? 'SELL' : 'BUY', 'timestamp' => $time, 'setup_id' => $id,
        'trade_plan' => ['entry_price' => 2000.0, 'stop_loss' => $short ? 2010.0 : 1990.0,
            'take_profit_1' => $short ? 1980.0 : 2020.0, 'risk_per_unit' => 10.0,
            'entry_min_price' => 1999.0, 'entry_max_price' => 2001.0],
    ];
}

function retestExecutionTester(array $signals): GoldRetestBacktester
{
    // Isolate fill accounting from signal discovery; real-engine tests cover discovery separately.
    $engine = new class($signals) extends GoldTrendRetestStrategy
    {
        public function __construct(private array $signals) {}

        public function evaluateHistory(array $m15, array $h1, string $dataSource = 'VT Markets MT5'): array
        {
            return $this->signals;
        }
    };

    return new GoldRetestBacktester($engine);
}

test('retest simulator applies long ask entry, adverse slippage and round-trip fees', function () {
    $signal = retestExecutionSignal();
    $data = retestExecutionData([retestExecutionBar($signal['timestamp'], high: 2021)]);
    $result = retestExecutionTester([$signal['timestamp'] => $signal])->run($data, 0.5, 0.1, 0.2);
    expect($result['wins'])->toBe(1)
        ->and($result['trades'][0]['entry'])->toBe(2000.6)
        ->and($result['trades'][0]['exit'])->toBe(2019.9)
        ->and($result['trades'][0]['net_r'])->toEqualWithDelta(19.1 / 10.6, 1e-9);
});

test('retest simulator uses ask for short targets rather than the bid low', function () {
    $signal = retestExecutionSignal(short: true);
    $data = retestExecutionData([
        retestExecutionBar($signal['timestamp'], low: 1980, close: 1981),
        retestExecutionBar('2026-09-14T10:15:00Z', open: 1981, high: 1982, low: 1979, close: 1980),
    ]);
    $result = retestExecutionTester([$signal['timestamp'] => $signal])->run($data, 0.5, 0.1, 0.2);
    expect($result['wins'])->toBe(1)
        ->and($result['trades'][0]['exit_bar_open'])->toBe('2026-09-14T10:15:00+00:00')
        ->and($result['trades'][0]['entry'])->toBe(1999.9)
        ->and($result['trades'][0]['exit'])->toBe(1980.1)
        ->and($result['trades'][0]['net_r'])->toEqualWithDelta(19.6 / 10.1, 1e-9);
});

test('retest simulator resolves ambiguous intrabar paths at stop and includes gap losses', function () {
    $signal = retestExecutionSignal();
    $tester = retestExecutionTester([$signal['timestamp'] => $signal]);
    $both = $tester->run(retestExecutionData([retestExecutionBar($signal['timestamp'], high: 2030, low: 1980)]), 0, 0, 0);
    expect($both['losses'])->toBe(1)
        ->and($both['ambiguous_bars_stop_first'])->toBe(1)
        ->and($both['trades'][0]['net_r'])->toBe(-1.0);
    $gap = $tester->run(retestExecutionData([
        retestExecutionBar($signal['timestamp']),
        retestExecutionBar('2026-09-14T10:15:00Z', open: 1985, high: 1988, low: 1980, close: 1982),
    ]), 0, 0, 0);
    expect($gap['trades'][0]['exit_reason'])->toBe('gap_stop')
        ->and($gap['trades'][0]['net_r'])->toBe(-1.5)
        ->and($gap['max_closed_trade_drawdown_r'])->toBe(1.5);
});

test('target hit is classified by net returns and open positions stay separate', function () {
    $signal = retestExecutionSignal();
    $tester = retestExecutionTester([$signal['timestamp'] => $signal]);
    $costly = $tester->run(retestExecutionData([retestExecutionBar($signal['timestamp'], high: 2021)]), 0, 0, 25);
    expect($costly['wins'])->toBe(0)->and($costly['losses'])->toBe(1)
        ->and($costly['trades'][0]['exit_reason'])->toBe('take_profit')
        ->and($costly['trades'][0]['net_r'])->toBe(-0.5);
    $flat = $tester->run(retestExecutionData([retestExecutionBar($signal['timestamp'], high: 2021)]), 0, 0, 20);
    expect($flat['breakeven'])->toBe(1);
    $open = $tester->run(retestExecutionData([retestExecutionBar($signal['timestamp'])]), 0, 0, 0);
    expect($open['closed_trades'])->toBe(0)->and($open['win_rate'])->toBeNull()
        ->and($open['open_position_at_end']['setup_id'])->toBe('setup-1');
});

test('entry price bounds and missing immediate next opens reject execution', function () {
    $signal = retestExecutionSignal();
    $tester = retestExecutionTester([$signal['timestamp'] => $signal]);
    $gap = $tester->run(retestExecutionData([retestExecutionBar($signal['timestamp'], open: 2003, high: 2004)]), 0, 0, 0);
    expect($gap['entry_rejections']['entry_bounds'])->toBe(1)->and($gap['closed_trades'])->toBe(0);
    $missing = $tester->run(retestExecutionData([retestExecutionBar('2026-09-14T10:15:00Z')]), 0, 0, 0);
    expect($missing['entry_rejections']['no_immediate_next_open'])->toBe(1)->and($missing['open_position_at_end'])->toBeNull();
});

test('retest simulator blocks simultaneous positions and consumes each setup once', function () {
    $a = retestExecutionSignal();
    $b = retestExecutionSignal('2026-09-14T10:15:00+00:00', 'setup-2');
    $c = retestExecutionSignal('2026-09-14T11:00:00+00:00', 'setup-1');
    $data = retestExecutionData([
        retestExecutionBar($a['timestamp']),
        retestExecutionBar($b['timestamp'], high: 2021),
        retestExecutionBar($c['timestamp'], high: 2021),
    ]);
    $result = retestExecutionTester([$a['timestamp'] => $a, $b['timestamp'] => $b, $c['timestamp'] => $c])->run($data, 0, 0, 0);
    expect($result['closed_trades'])->toBe(1)
        ->and($result['entry_rejections']['existing_position'])->toBe(1)
        ->and($result['entry_rejections']['duplicate_setup'])->toBe(1);
});

test('retest simulator enforces a daily entry cap and thirty-minute cooldown', function () {
    $signals = [];
    $tail = [];
    foreach (['10:00', '10:15', '11:00', '12:00'] as $index => $hour) {
        $time = '2026-09-14T'.$hour.':00+00:00';
        $signals[$time] = retestExecutionSignal($time, 'setup-'.$index);
        $tail[] = retestExecutionBar($time, high: 2021);
    }
    $result = retestExecutionTester($signals)->run(retestExecutionData($tail), 0, 0, 0);
    expect($result['closed_trades'])->toBe(2)
        ->and($result['entry_rejections']['cooldown'])->toBe(1)
        ->and($result['entry_rejections']['daily_entry_limit'])->toBe(1);
});

test('evaluation end censors positions and excludes later targets', function () {
    $signal = retestExecutionSignal();
    $data = retestExecutionData([
        retestExecutionBar($signal['timestamp']),
        retestExecutionBar('2026-09-14T10:15:00Z', high: 2021),
    ]);
    $result = retestExecutionTester([$signal['timestamp'] => $signal])->run($data, 0, 0, 0,
        Carbon::parse($signal['timestamp']), Carbon::parse('2026-09-14T10:15:00Z'));
    expect($result['evaluation']['bars'])->toBe(1)->and($result['closed_trades'])->toBe(0)
        ->and($result['open_position_at_end'])->not->toBeNull();
});

test('retest simulator rejects invalid costs, exports and evaluation boundaries', function () {
    $tester = retestExecutionTester([]);
    $data = retestExecutionData([]);
    foreach ([-1.0, INF, NAN] as $cost) {
        expect(fn () => $tester->run($data, $cost, 0, 0))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $tester->run(array_replace($data, ['source' => 'spot']), 0, 0, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $tester->run(array_replace($data, ['symbol' => 'EURUSD']), 0, 0, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $tester->run(array_replace($data, ['symbol' => 'XAUJPY']), 0, 0, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $tester->run($data, 0, 0, 0, Carbon::parse('2026-09-14T10:01:00Z')))->toThrow(InvalidArgumentException::class);
});

test('real retest engine and simulator enter only after the closed retest', function () {
    $data = retestExecutionData([
        retestExecutionBar('2026-09-14T10:00:00Z', open: 2002, high: 2002.8, low: 2001.9, close: 2002.5),
        retestExecutionBar('2026-09-14T10:15:00Z', open: 2002.5, high: 2020, low: 2001, close: 2015),
    ]);
    $data['m15'][49] = retestExecutionBar('2026-09-14T09:45:00Z', open: 2001.9, high: 2003.2, low: 2001.7, close: 2002.8);
    foreach ($data['h1'] as $i => &$bar) {
        $close = 1970 + $i * 0.1;
        $bar = array_replace($bar, ['open' => $close - 0.1, 'high' => $close + 1, 'low' => $close - 1, 'close' => $close]);
    }
    unset($bar);
    $report = (new GoldRetestBacktester)->run($data, 0, 0, 0);
    expect($report['signals'])->toBe(1)->and($report['wins'])->toBe(1)
        ->and($report['trades'][0]['signal_at'])->toBe('2026-09-14T10:15:00+00:00')
        ->and($report['trades'][0]['opened_at'])->toBe('2026-09-14T10:15:00+00:00')
        ->and($report['trades'][0]['net_r'])->toEqualWithDelta(2.0, 1e-9);
});
