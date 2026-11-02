<?php

use App\Services\GoldRetestMinuteBacktester;
use App\Services\GoldTrendRetestStrategy;
use Illuminate\Support\Carbon;

function minuteCandidateBar(string $at, float $open = 2000, float $high = 2002, float $low = 1998, float $close = 2000, float $points = 21): array
{
    return ['timestamp' => $at, 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'spread_points' => $points];
}

function minuteCandidateSignal(string $at = '2026-09-14T10:00:00+00:00', string $id = 'setup-1', bool $short = false): array
{
    return ['action' => $short ? 'SELL' : 'BUY', 'timestamp' => $at, 'setup_id' => $id,
        'trade_plan' => ['entry_price' => 2000, 'stop_loss' => $short ? 2010 : 1990, 'take_profit_1' => $short ? 1980 : 2020,
            'entry_min_price' => 1999, 'entry_max_price' => 2001]];
}

function minuteCandidateData(array $bars): array
{
    return ['source' => 'mt5', 'symbol' => 'XAUUSD-STD', 'm1' => $bars, 'm15' => [], 'h1' => [], 'timestamp_assumption' => 'synthetic UTC'];
}

function minuteCandidateTester(array $signals): GoldRetestMinuteBacktester
{
    $engine = new class($signals) extends GoldTrendRetestStrategy
    {
        public function __construct(private array $signals) {}

        public function evaluateHistory(array $m15, array $h1, string $dataSource = 'VT Markets MT5'): array
        {
            return $this->signals;
        }
    };

    return new GoldRetestMinuteBacktester($engine);
}

test('minute diagnostic applies the spread proxy and adverse fill costs', function () {
    $signal = minuteCandidateSignal();
    $data = minuteCandidateData([minuteCandidateBar($signal['timestamp'], high: 2021)]);
    $result = minuteCandidateTester([$signal['timestamp'] => $signal])->run($data, 0.01, 0.1, 0.07);
    expect($result['metrics']['wins'])->toBe(1)
        ->and($result['trades'][0]['entry'])->toBe(2000.31)
        ->and($result['trades'][0]['exit'])->toBe(2019.9)
        ->and($result['metrics']['net_r'])->toEqualWithDelta(19.52 / 10.31, 1e-9)
        ->and($result['contiguous_path_metrics']['closed_trades'])->toBe(1);
});

test('minute diagnostic uses each minutes spread proxy for short ask exits', function () {
    $signal = minuteCandidateSignal(short: true);
    $data = minuteCandidateData([
        minuteCandidateBar($signal['timestamp'], low: 1980, close: 1981),
        minuteCandidateBar('2026-09-14T10:01:00Z', open: 1981, high: 1982, low: 1979.8, close: 1980, points: 28),
        minuteCandidateBar('2026-09-14T10:02:00Z', open: 1981, high: 1982, low: 1979.7, close: 1980),
    ]);
    $result = minuteCandidateTester([$signal['timestamp'] => $signal])->run($data, 0.01, 0.1, 0.07);
    expect($result['trades'][0]['exit_minute_open'])->toBe('2026-09-14T10:02:00+00:00')
        ->and($result['trades'][0]['exit'])->toBe(1980.1)
        ->and($result['metrics']['net_r'])->toEqualWithDelta(19.73 / 10.1, 1e-9);
});

test('minute diagnostic uses conservative both-hit ordering and adverse gap stops', function () {
    $signal = minuteCandidateSignal();
    $tester = minuteCandidateTester([$signal['timestamp'] => $signal]);
    $both = $tester->run(minuteCandidateData([minuteCandidateBar($signal['timestamp'], high: 2030, low: 1980)]), 0.01, 0, 0, spreadMultiplier: 0);
    expect($both['ambiguous_minutes_stop_first'])->toBe(1)->and($both['metrics']['net_r'])->toBe(-1.0);
    $gap = $tester->run(minuteCandidateData([
        minuteCandidateBar($signal['timestamp']),
        minuteCandidateBar('2026-09-14T10:01:00Z', open: 1985, high: 1988, low: 1980, close: 1982),
    ]), 0.01, 0, 0, spreadMultiplier: 0);
    expect($gap['trades'][0]['exit_reason'])->toBe('gap_stop')->and($gap['metrics']['net_r'])->toBe(-1.5);
});

test('minute diagnostic isolates trades crossing absent minutes', function () {
    $signal = minuteCandidateSignal();
    $data = minuteCandidateData([
        minuteCandidateBar($signal['timestamp']),
        minuteCandidateBar('2026-09-14T10:02:00Z', high: 2021),
    ]);
    $result = minuteCandidateTester([$signal['timestamp'] => $signal])->run($data, 0.01, 0, 0, spreadMultiplier: 0);
    expect($result['metrics']['wins'])->toBe(1)
        ->and($result['trades_crossing_nonconsecutive_minutes'])->toBe(1)
        ->and($result['contiguous_path_metrics']['closed_trades'])->toBe(0);
});

test('minute diagnostic never delays an entry into the next available quote', function () {
    $signal = minuteCandidateSignal();
    $data = minuteCandidateData([minuteCandidateBar('2026-09-14T10:01:00Z', high: 2021)]);
    $result = minuteCandidateTester([$signal['timestamp'] => $signal])->run($data, 0.01, 0, 0, from: Carbon::parse($signal['timestamp']));
    expect($result['entry_rejections']['no_immediate_next_open'])->toBe(1)
        ->and($result['metrics']['closed_trades'])->toBe(0)->and($result['open_position_at_end'])->toBeNull();
});

test('minute diagnostic censors positions at evaluation end and leaves fees in outcome accounting', function () {
    $signal = minuteCandidateSignal();
    $tester = minuteCandidateTester([$signal['timestamp'] => $signal]);
    $data = minuteCandidateData([
        minuteCandidateBar($signal['timestamp']),
        minuteCandidateBar('2026-09-14T10:15:00Z', high: 2021),
    ]);
    $open = $tester->run($data, 0.01, 0, 0, to: Carbon::parse('2026-09-14T10:15:00Z'), spreadMultiplier: 0);
    expect($open['metrics']['closed_trades'])->toBe(0)->and($open['open_position_at_end'])->not->toBeNull();
    $loss = $tester->run($data, 0.01, 0, 25, spreadMultiplier: 0);
    expect($loss['metrics']['losses'])->toBe(1)->and($loss['trades'][0]['exit_reason'])->toBe('take_profit');
});

test('minute diagnostic enforces one position, daily cap, cooldown and setup consumption', function () {
    $signals = [];
    $bars = [];
    foreach (['10:00', '10:15', '11:00', '12:00', '13:00'] as $index => $clock) {
        $at = '2026-09-14T'.$clock.':00+00:00';
        $signals[$at] = minuteCandidateSignal($at, $index === 4 ? 'setup-0' : 'setup-'.$index);
        $bars[] = minuteCandidateBar($at, high: 2021);
    }
    $result = minuteCandidateTester($signals)->run(minuteCandidateData($bars), 0.01, 0, 0, spreadMultiplier: 0);
    expect($result['metrics']['closed_trades'])->toBe(2)
        ->and($result['entry_rejections']['cooldown'])->toBe(1)
        ->and($result['entry_rejections']['daily_entry_limit'])->toBe(1)
        ->and($result['entry_rejections']['duplicate_setup'])->toBe(1);
});

test('minute diagnostic rejects invalid timestamps quotes symbols and costs', function () {
    $tester = minuteCandidateTester([]);
    $data = minuteCandidateData([minuteCandidateBar('2026-09-14T10:00:00Z')]);
    foreach (['2026-02-30T10:00:00Z', '2026-09-14T10:00:00', '2026-09-14T10:00:30Z'] as $timestamp) {
        $invalid = $data;
        $invalid['m1'][0]['timestamp'] = $timestamp;
        expect(fn () => $tester->run($invalid, 0.01, 0, 0))->toThrow(InvalidArgumentException::class);
    }
    $invalid = $data;
    $invalid['m1'][0]['high'] = 1900;
    expect(fn () => $tester->run($invalid, 0.01, 0, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $tester->run(array_replace($data, ['symbol' => 'XAUJPY']), 0.01, 0, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $tester->run($data, 0, 0, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $tester->run($data, 0.01, NAN, 0))->toThrow(InvalidArgumentException::class);
});
