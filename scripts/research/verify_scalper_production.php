<?php

// Offline implementation parity only: no application boot, provider, email or order.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Services\MarketDataGuard;
use App\Services\RsiGoldScalperV2Strategy;
use App\Services\TradingService;
use Illuminate\Support\Carbon;

$root = dirname(__DIR__, 2);
$study = 'docs/research/scalper-upgrade-2026-11-02';
$private = 'storage/app/private/signal-research/scalper-upgrade-2026-11-02';
$files = [
    'app/Services/RsiGoldScalperV2Strategy.php', 'app/Services/TradingService.php',
    'app/Services/MarketDataGuard.php', 'scripts/research/verify_scalper_production.php',
    $study.'/PRE_REGISTERED.md', $study.'/comparison-summary.json',
];
foreach ([3, 0] as $clock) {
    $files[] = $private.'/input-utcplus'.$clock.'.json';
    $files[] = $study.'/filter-decisions-utcplus'.$clock.'.json';
    foreach (['tp1', 'tp2'] as $target) {
        $files[] = $private.'/baseline-utcplus'.$clock.'-full-'.$target.'-gross.json';
    }
}
$hashes = static function () use ($files, $root): array {
    $result = [];
    foreach ($files as $file) {
        if (! is_file($root.'/'.$file)) {
            throw new RuntimeException('Required frozen parity input is missing: '.$file);
        }
        $result[$file] = hash_file('sha256', $root.'/'.$file);
    }

    return $result;
};
$before = $hashes();
$read = static fn (string $file): array => json_decode(file_get_contents($root.'/'.$file), true, 512, JSON_THROW_ON_ERROR);
$require = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$sameNumber = static function ($actual, $expected, string $context) use ($require): void {
    $require(is_numeric($actual) && is_numeric($expected) && abs((float) $actual - (float) $expected) <= 1e-8,
        'Production parity difference: '.$context);
};
$guard = new MarketDataGuard;
$legacy = new TradingService;
$engine = new RsiGoldScalperV2Strategy($legacy, $guard);
$summary = $read($study.'/comparison-summary.json');
$result = [];
$total = $evaluations = $plansCompared = $frozenTradePlans = 0;
foreach ([3, 0] as $clock) {
    $data = $read($private.'/input-utcplus'.$clock.'.json');
    $locked = $read($study.'/filter-decisions-utcplus'.$clock.'.json');
    $fingerprint = hash('sha256', json_encode([$data['source'], $data['symbol'], $data['m1'], $data['m5']], JSON_THROW_ON_ERROR));
    $require($fingerprint === $locked['baseline_diagnostics']['input_sha256'], 'Input fingerprint differs from frozen study.');
    $require(count($locked['rows']) === $locked['baseline_diagnostics']['entry_signals'], 'Frozen entry count does not reconcile.');
    $end = Carbon::parse($data['m1'][array_key_last($data['m1'])]['timestamp'])->utc()->addMinute();
    $bars = $guard->validateCandles($data['m5'], 300, $end, false);
    $bars = array_values(array_filter($bars, static function ($bar) use ($guard) {
        $open = Carbon::parse($bar['timestamp'])->utc();

        return $guard->marketStatus('XAUUSD', $open)['open']
            && $guard->marketStatus('XAUUSD', $open->copy()->addSeconds(299))['open'];
    }));
    $require(count($bars) === $locked['baseline_diagnostics']['active_m5_bars'], 'Active session history differs from study.');
    $byOpen = array_flip(array_column($bars, 'timestamp'));
    $counts = ['baseline_decisions' => count($locked['rows']), 'trend_entries' => 0, 'trend_session_entries' => 0,
        'trend_rejections' => 0, 'trend_session_rejections' => 0, 'closed_market_decisions_engine_only' => 0];
    $referenceSignals = [];
    foreach ($locked['rows'] as $row) {
        $index = $byOpen[$row['signal_open']] ?? throw new RuntimeException('Missing locked source candle.');
        $require($index + 1 === $row['history_bars'], 'Study history count differs.');
        $require($index >= 202, 'A locked baseline crossover lacks the required 203 bars.');
        $decision = Carbon::parse($row['decision_at'])->utc();
        $require(Carbon::parse($bars[$index]['timestamp'])->utc()->addMinutes(5)->equalTo($decision), 'Decision is not its source candle close.');
        // Both windows end at this decision: no future bar is passed to either evaluator.
        $baseline = $legacy->evaluateRsiMovingAverage(array_slice($bars, $index - 199, 200), null, [], 'MT5 '.$data['symbol']);
        $require($baseline['action'] === $row['action'] && $baseline['timestamp'] === $row['signal_open'], 'Unchanged legacy evaluator differs from the locked crossover.');
        $sameNumber($baseline['trade_plan']['entry_price'], $row['m5_close'], 'locked entry price');
        $referenceSignals[$row['decision_at']] = $baseline;
        $counts['closed_market_decisions_engine_only'] += (int) (! $guard->marketStatus('XAUUSD', $decision)['open']);
        foreach ([false, true] as $session) {
            $evaluations++;
            $actual = $engine->evaluate(array_slice($bars, $index - 202, 203), null, 'MT5 '.$data['symbol'], $decision->copy()->addSeconds(20), $session);
            $allowed = $row['trend_pass'] && (! $session || $row['session_pass']);
            $key = $session ? 'trend_session' : 'trend';
            $counts[$key.($allowed ? '_entries' : '_rejections')]++;
            $require($actual['action'] === ($allowed ? $row['action'] : 'HOLD'), 'Production action differs at '.$row['decision_at']);
            $require(($actual['research_only'] ?? false) === true && $actual['strategy'] === RsiGoldScalperV2Strategy::SLUG, 'Production research metadata differs.');
            $require($actual['timestamp'] === $row['signal_open'], 'Production rewrote the source candle time.');
            $sameNumber($actual['indicators']['trend_sma_200'], $row['sma200'], 'SMA200 now');
            $sameNumber($actual['indicators']['trend_sma_200_prior'], $row['sma200_three_active_bars_earlier'], 'SMA200 three active bars earlier');
            $require($actual['indicators']['entry_session_allowed'] === (! $session || $row['session_pass']), 'Production closed-candle session differs.');
            if ($allowed) {
                $plansCompared++;
                foreach (['entry_price', 'stop_loss', 'take_profit_1', 'risk_per_unit', 'rr_tp1'] as $field) {
                    $sameNumber($actual['trade_plan'][$field], $baseline['trade_plan'][$field], $field.' at '.$row['decision_at']);
                }
                $require($actual['trade_plan']['take_profit_2'] === null && $actual['trade_plan']['rr_tp2'] === null
                    && $actual['trade_plan']['exit_policy'] === 'full_tp1', 'Production TP1-only exit policy differs.');
            } else {
                foreach (['entry_price', 'stop_loss', 'take_profit_1', 'take_profit_2'] as $field) {
                    $require(($actual['trade_plan'][$field] ?? null) === null, 'Rejected production signal retained an executable '.$field);
                }
            }
        }
        $total++;
    }
    // The original study stores absolute plans for executed trades. Check both
    // TP policies against those frozen prices as well as legacy regeneration.
    foreach (['tp1', 'tp2'] as $target) {
        $file = $private.'/baseline-utcplus'.$clock.'-full-'.$target.'-gross.json';
        $studyCase = array_values(array_filter($summary['comparisons'], static fn ($case) => $case['raw_file'] === $file));
        $require(count($studyCase) === 1 && $studyCase[0]['result_sha256'] === $before[$file], 'Frozen trade reference hash differs.');
        $raw = $read($file);
        $trades = $raw['trades'];
        if ($raw['open_position_at_end'] !== null) {
            $trades[] = $raw['open_position_at_end'];
        }
        foreach ($trades as $trade) {
            $plan = $referenceSignals[$trade['decision_at']]['trade_plan'] ?? throw new RuntimeException('Frozen trade has no causal source signal.');
            $sameNumber($trade['signal_entry_price'], $plan['entry_price'], 'frozen original entry');
            $sameNumber($trade['sl'], $plan['stop_loss'], 'frozen absolute stop');
            $sameNumber($trade['tp'], $plan[$target === 'tp1' ? 'take_profit_1' : 'take_profit_2'], 'frozen absolute target');
            $frozenTradePlans++;
        }
    }
    $result[] = ['csv_clock_offset_hours' => $clock, 'active_m5_bars' => count($bars)] + $counts;
}
$require($total === 1000 && $evaluations === 2000, 'Expected all 1,000 locked crossovers under both declared filters.');
$require($hashes() === $before, 'Parity inputs or production sources changed during verification.');
$report = ['status' => 'passed', 'checked_at_utc' => Carbon::now('UTC')->toIso8601String(),
    'method' => 'Actual production RsiGoldScalperV2Strategy versus frozen research filter decisions, using only the causal last203 session-valid M5 bars.',
    'baseline_crossovers_checked' => $total, 'production_evaluations' => $evaluations,
    'accepted_production_plans_compared_with_actual_legacy' => $plansCompared,
    'original_study_trade_plans_crosschecked' => $frozenTradePlans,
    'action_indicator_session_or_plan_differences' => 0,
    'source_and_input_hashes_verified_unchanged_during_run' => $before, 'clocks' => $result,
    'limits' => ['Implementation parity, not an additional profitability test or unseen holdout.',
        'No provider, application boot, email, database or execution path is invoked.',
        'Legacy absolute levels are regenerated by the unchanged actual evaluator on last200; stored original-study trade plans are also hash-verified and crosschecked.',
        'Production preserves original entry, SL and full TP1, removes alternative TP2 and declares full_tp1; the separate TP2 research diagnostics remain unchanged.',
        'Raw UTC0 maintenance-boundary decisions are compared before the separate live application market preflight. Actual live preflight blocks closed-market entries.',
        'This verifies source-candle calculations; the live provider and minute-minimum spread proxy are not actual broker execution.']];
$output = $root.'/'.$study.'/production-parity.json';
file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['status' => 'passed', 'baseline_crossovers_checked' => $total, 'production_evaluations' => $evaluations,
    'accepted_plans_compared' => $plansCompared, 'frozen_trade_plans_crosschecked' => $frozenTradePlans,
    'differences' => 0, 'output' => $study.'/production-parity.json'], JSON_THROW_ON_ERROR)."\n";
