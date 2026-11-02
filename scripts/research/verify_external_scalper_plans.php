<?php

// Offline provenance and actual-PHP parity audit. No Laravel boot or external I/O.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Services\MarketDataGuard;
use App\Services\RsiGoldScalperV2Strategy;
use App\Services\TradingService;
use Illuminate\Support\Carbon;

$root = dirname(__DIR__, 2);
$private = 'storage/app/private/signal-research/external-validation';
$study = 'docs/research/scalper-upgrade-2026-11-02';
$input = $private.'/twelvedata-jun-aug-2026-m1.json';
$reference = $study.'/external-entry-snapshots-js-rounding-v1.json';
$plan = $study.'/EXTERNAL_VALIDATION_PLAN.md';
$read = static fn (string $file): array => json_decode(file_get_contents($root.'/'.$file), true, 512, JSON_THROW_ON_ERROR);
$require = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$iso = static fn (int $time): string => gmdate('Y-m-d\TH:i:s+00:00', $time);
$timestamp = static function (string $text) use ($require): int {
    $require((bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00(?:Z|\+00:00)$/D', $text), 'A price timestamp is not an explicit UTC minute.');
    $canonical = preg_replace('/Z$/', '+00:00', $text);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $canonical, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    $require($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d\TH:i:sP') === $canonical, 'Invalid calendar minute.');

    return $date->getTimestamp();
};
$metaTimestamp = static function (string $text) use ($timestamp): int {
    return $timestamp(str_replace('.000Z', 'Z', $text));
};
$data = $read($input);
$locked = $read($reference);
$require(hash_file('sha256', $root.'/'.$input) === 'e480f68a79dda7b7e2597a4168a4258a450d2836d3edb3260158861a724b3e35', 'External source changed.');
$require(hash('sha256', str_replace("\r\n", "\n", file_get_contents($root.'/'.$plan))) === '8f55fe3553559df4990ceda05578a9ccf360ae852f2b7f88712badf8d877ec8b', 'Frozen validation plan changed.');
$require($data['source'] === 'twelvedata_history' && $data['symbol'] === 'XAUUSD' && str_contains($data['timestamp_assumption'], 'UTC'), 'External source identity or UTC provenance changed.');
$require($locked['input_sha256'] === hash_file('sha256', $root.'/'.$input), 'Reference uses another source.');
$provenance = $data['provenance'];
$require($provenance['provider'] === 'Twelve Data' && $provenance['endpoint'] === 'https://api.twelvedata.com/time_series'
    && $provenance['symbol'] === 'XAU/USD' && $provenance['interval'] === '1min' && $provenance['requested_timezone'] === 'UTC', 'Provider request identity differs.');
$require($provenance['spread_is_observed_history'] === false && $provenance['spread_assumption_usd'] === 0.37
    && $provenance['actual_account_fills'] === false && $provenance['historical_holiday_open_status_available'] === false, 'Execution limitations differ from the frozen declaration.');
$start = $timestamp('2026-05-29T00:00:00+00:00');
$from = $timestamp('2026-06-01T00:00:00+00:00');
$to = $timestamp('2026-09-01T00:00:00+00:00');
$require($timestamp($provenance['requested_from_inclusive']) === $start
    && $timestamp($provenance['warmup_from_inclusive']) === $start
    && $timestamp($provenance['evaluation_from_inclusive']) === $from
    && $timestamp($provenance['requested_to_exclusive']) === $to, 'Provenance window differs.');
$require(count($data['m1']) === 136800 && $provenance['raw_rows'] === 136800, 'Expected all 95 calendar days including warmup.');
$files = [$input, $reference, $plan, 'app/Services/TradingService.php', 'app/Services/RsiGoldScalperV2Strategy.php',
    'app/Services/MarketDataGuard.php', 'scripts/research/verify_external_scalper_plans.php', 'scripts/research/verify_scalper_candidates.cjs'];
foreach ($provenance['source_chunks'] as $chunk) {
    $require(basename($chunk['file']) === $chunk['file'], 'Unexpected source chunk path.');
    $files[] = $private.'/'.$chunk['file'];
}
$hashes = static function () use ($root, $files): array {
    $result = [];
    foreach ($files as $file) {
        $result[$file] = hash_file('sha256', $root.'/'.$file);
    }

    return $result;
};
$before = $hashes();
$coreSource = str_replace("\r\n", "\n", file_get_contents($root.'/scripts/research/verify_scalper_candidates.cjs'));
$coreStart = strpos($coreSource, 'const iso = seconds =>');
$coreEnd = strpos($coreSource, 'function replay(');
$require($coreStart !== false && $coreEnd !== false && $coreEnd > $coreStart, 'Independent math boundaries are missing.');
$require(hash('sha256', substr($coreSource, $coreStart, $coreEnd - $coreStart)) === $locked['independent_math_core_sha256']
    && $locked['independent_math_core_sha256'] === '74105055096b8ef497ec6bf9f06444741936184b4fae48f80ec6dcbefe8f93b2', 'Frozen independent decision math changed.');

$normalizedByTime = [];
$monthly = [];
foreach ($data['m1'] as $index => $row) {
    $time = $timestamp($row['timestamp']);
    $require($time === $start + $index * 60 && $time < $to, 'Duplicate, missing, unordered, or out-of-window normalized minute.');
    foreach (['open', 'high', 'low', 'close'] as $field) {
        $require((is_float($row[$field]) || is_int($row[$field])) && is_finite((float) $row[$field]) && $row[$field] > 0, 'Invalid normalized OHLC.');
    }
    $require($row['high'] >= max($row['open'], $row['low'], $row['close']) && $row['low'] <= min($row['open'], $row['close']), 'Inconsistent normalized OHLC.');
    $require(! isset($row['ask_open']) && ! isset($row['ask']), 'Unexpected actual ask data in declared spread-proxy input.');
    $require(! isset($row['spread_usd']) || $row['spread_usd'] === 0.37, 'Contradictory per-minute spread.');
    $normalizedByTime[$time] = $index;
    $month = gmdate('Y-m', $time);
    $monthly[$month] ??= ['raw_m1' => 0, 'session_valid_m1' => 0, 'closed_session_m1_removed' => 0, 'complete_session_m5' => 0];
    $monthly[$month]['raw_m1']++;
}
$require($timestamp($provenance['observed_first_utc']) === $start && $timestamp($provenance['observed_last_utc']) === $to - 60, 'Observed source bounds differ.');
$joined = [];
$chunkCursor = $start;
$rawRows = 0;
$chunkHashes = [];
foreach ($provenance['source_chunks'] as $chunk) {
    $file = $private.'/'.$chunk['file'];
    $require($before[$file] === $chunk['sha256'], 'Captured provider chunk hash differs.');
    $raw = $read($file);
    $request = $raw['request'];
    $left = $metaTimestamp($chunk['requested_from_utc']);
    $right = $metaTimestamp($chunk['requested_to_exclusive_utc']);
    $require($left === $chunkCursor && $right > $left && $right <= $to, 'Captured request windows overlap or have a gap.');
    $require($request['symbol'] === 'XAU/USD' && $request['interval'] === '1min' && $request['timezone'] === 'UTC'
        && $timestamp($request['from_inclusive']) === $left && $timestamp($request['to_exclusive']) === $right, 'Captured request UTC bounds or instrument differ.');
    $require($raw['meta']['symbol'] === 'XAU/USD' && $raw['meta']['interval'] === '1min'
        && $raw['meta']['type'] === 'Precious Metal' && $raw['meta']['currency_base'] === 'Gold Spot'
        && $raw['meta']['currency_quote'] === 'US Dollar', 'Captured provider instrument identity differs.');
    $require(count($raw['values']) === $chunk['rows'] && $chunk['rows'] === intdiv($right - $left, 60), 'Captured chunk does not contain every requested minute.');
    foreach ($raw['values'] as $row) {
        $time = $timestamp(str_replace(' ', 'T', $row['datetime']).'+00:00');
        $require($time >= $left && $time < $right && ! isset($joined[$time]) && isset($normalizedByTime[$time]), 'Duplicate or out-of-window captured minute.');
        $normalized = $data['m1'][$normalizedByTime[$time]];
        foreach (['open', 'high', 'low', 'close'] as $field) {
            $require(is_numeric($row[$field]) && (float) $row[$field] === (float) $normalized[$field], 'Normalized price differs from its captured provider response.');
        }
        $joined[$time] = true;
        $rawRows++;
    }
    $chunkHashes[$chunk['file']] = $chunk['sha256'];
    $chunkCursor = $right;
}
$require(count($provenance['source_chunks']) === 32 && $chunkCursor === $to && $rawRows === 136800 && count($joined) === count($data['m1']), 'Captured chunks do not reconcile to normalized source.');
unset($joined, $normalizedByTime, $raw);

$guard = new MarketDataGuard;
$legacy = new TradingService;
$engine = new RsiGoldScalperV2Strategy($legacy, $guard);
$minutes = [];
foreach ($data['m1'] as $row) {
    $at = Carbon::parse($row['timestamp'])->utc();
    $month = $at->format('Y-m');
    if (! $guard->marketStatus('XAUUSD', $at)['open'] || ! $guard->marketStatus('XAUUSD', $at->copy()->addSeconds(59))['open']) {
        $monthly[$month]['closed_session_m1_removed']++;

        continue;
    }
    $monthly[$month]['session_valid_m1']++;
    $row['time'] = $at->getTimestamp();
    $minutes[] = $row;
}
unset($data['m1']);
$bars = [];
$bucket = [];
$bucketTime = null;
$incomplete = $closedBuckets = 0;
$flush = static function () use (&$bucket, &$bucketTime, &$bars, &$incomplete, &$closedBuckets, &$monthly, $guard, $iso): void {
    if ($bucket === []) {
        return;
    }
    if (count($bucket) !== 5) {
        $incomplete++;

        return;
    }
    foreach ($bucket as $offset => $minute) {
        if ($minute['time'] !== $bucketTime + $offset * 60) {
            $incomplete++;

            return;
        }
    }
    $at = Carbon::createFromTimestampUTC($bucketTime);
    if (! $guard->marketStatus('XAUUSD', $at)['open'] || ! $guard->marketStatus('XAUUSD', $at->copy()->addSeconds(299))['open']) {
        $closedBuckets++;

        return;
    }
    $bars[] = ['timestamp' => $iso($bucketTime), 'open' => $bucket[0]['open'], 'high' => max(array_column($bucket, 'high')),
        'low' => min(array_column($bucket, 'low')), 'close' => $bucket[4]['close']];
    $monthly[$at->format('Y-m')]['complete_session_m5']++;
};
foreach ($minutes as $row) {
    $at = intdiv($row['time'], 300) * 300;
    if ($bucketTime !== $at) {
        $flush();
        $bucket = [];
        $bucketTime = $at;
    }
    $bucket[] = $row;
}
$flush();
unset($minutes, $bucket);
$require($monthly['2026-05']['session_valid_m1'] === 1380 && $monthly['2026-05']['complete_session_m5'] === 276, 'Expected prior-session warmup differs.');
foreach (['2026-06' => 30360, '2026-07' => 31620, '2026-08' => 29100] as $month => $expected) {
    $require($monthly[$month]['session_valid_m1'] === $expected && $monthly[$month]['complete_session_m5'] === intdiv($expected, 5), 'NY regular-session monthly coverage differs.');
}
$require($incomplete === 0 && $closedBuckets === 0 && count($bars) === 18492, 'Unexpected incomplete or closed M5 history.');

$references = [];
foreach ($locked['snapshots'] as $row) {
    $time = $timestamp($row['decision_at']);
    $require(! isset($references[$time]) && $time >= $from && $time < $to, 'Duplicate or out-of-period reference decision.');
    $references[$time] = $row;
}
$rsiMethod = new ReflectionMethod(TradingService::class, 'rsi');
$atrMethod = new ReflectionMethod(TradingService::class, 'atr');
$mismatches = $examples = $maxErrors = [];
$compare = static function ($actual, $expected, string $field, string $at, float $tolerance = 1e-8) use (&$mismatches, &$examples, &$maxErrors): void {
    $numeric = is_numeric($actual) && is_numeric($expected);
    $error = $numeric ? abs((float) $actual - (float) $expected) : null;
    if ($numeric) {
        $maxErrors[$field] = max($maxErrors[$field] ?? 0.0, $error);
    }
    if (($numeric && $error <= $tolerance) || (! $numeric && $actual === $expected)) {
        return;
    }
    $mismatches[$field] = ($mismatches[$field] ?? 0) + 1;
    if (count($examples) < 60) {
        $examples[] = ['decision_at' => $at, 'field' => $field, 'actual_php' => $actual, 'frozen_javascript' => $expected, 'absolute_difference' => $error];
    }
};
$snapshots = [];
$counts = ['actual_legacy_evaluations' => 0, 'baseline_crossovers' => 0, 'v2_evaluations' => 0, 'trend_entries' => 0,
    'trend_session_entries' => 0, 'accepted_v2_plans_compared' => 0, 'raw_closed_market_decisions' => 0];
$actualTimes = [];
for ($i = 199; $i < count($bars); $i++) {
    $decisionTime = $timestamp($bars[$i]['timestamp']) + 300;
    if ($decisionTime < $from || $decisionTime >= $to) {
        continue;
    }
    $window = array_slice($bars, $i - 199, 200);
    $baseline = $legacy->evaluateRsiMovingAverage($window, null, [], 'Twelve Data external offline audit');
    $counts['actual_legacy_evaluations']++;
    if (! in_array($baseline['action'], ['BUY', 'SELL'], true)) {
        continue;
    }
    $counts['baseline_crossovers']++;
    $actualTimes[$decisionTime] = true;
    $at = $iso($decisionTime);
    $row = $references[$decisionTime] ?? null;
    $require($row !== null, 'Actual PHP found an extra crossover absent from the frozen independent reference: '.$at);
    $require($i >= 202, 'A crossover lacks the declared 203 completed trading-session bars.');
    $compare($baseline['action'], $row['action'], 'action', $at);
    $compare($baseline['timestamp'], $row['signal_open'], 'signal_open', $at);
    $compare($i + 1, $row['history_bars'], 'history_bars', $at);
    $compare($bars[$i]['close'], $row['source_close_full_precision'], 'source_close_full_precision', $at, 1e-12);
    foreach (['entry_price', 'stop_loss', 'take_profit_1', 'take_profit_2'] as $field) {
        $compare($baseline['trade_plan'][$field], $row[$field], $field, $at);
    }
    foreach (['rsi', 'atr', 'short_ma', 'long_ma'] as $field) {
        $compare($baseline['indicators'][$field], $row['indicators'][$field], 'indicator_'.$field, $at);
    }
    $rsi = $rsiMethod->invoke($legacy, array_column($window, 'close'), 14);
    $atr = $atrMethod->invoke($legacy, $window, 14);
    if ($atr === null || $atr <= 0) {
        $atr = max(0.5, $bars[$i]['high'] - $bars[$i]['low']);
    }
    $compare($rsi, $row['rsi_unrounded'], 'rsi_unrounded', $at, 1e-10);
    $compare($atr, $row['atr_unrounded'], 'atr_unrounded', $at, 1e-10);
    $decision = Carbon::createFromTimestampUTC($decisionTime);
    $counts['raw_closed_market_decisions'] += (int) (! $guard->marketStatus('XAUUSD', $decision)['open']);
    $actualTrend = $actualSession = null;
    foreach ([false, true] as $session) {
        $counts['v2_evaluations']++;
        $actual = $engine->evaluate(array_slice($bars, $i - 202, 203), null, 'Twelve Data external offline audit', $decision, $session);
        $allowed = $row['trend_pass'] && (! $session || $row['session_pass']);
        $compare($actual['action'], $allowed ? $row['action'] : 'HOLD', $session ? 'trend_session_action' : 'trend_action', $at);
        $require(($actual['research_only'] ?? false) === true && $actual['strategy'] === RsiGoldScalperV2Strategy::SLUG, 'Research-only identity metadata is missing.');
        $compare($actual['timestamp'], $row['signal_open'], 'v2_signal_open', $at);
        $compare($actual['indicators']['trend_sma_200'] ?? null, $row['sma200'], 'sma200', $at, 1e-9);
        $compare($actual['indicators']['trend_sma_200_prior'] ?? null, $row['sma200_three_active_bars_earlier'], 'sma200_three_active_bars_earlier', $at, 1e-9);
        $compare($actual['indicators']['entry_session_allowed'] ?? null, ! $session || $row['session_pass'], 'entry_session_allowed', $at);
        $directionPass = $row['action'] === 'BUY' ? ($actual['indicators']['trend_up_confirmed'] ?? null) : ($actual['indicators']['trend_down_confirmed'] ?? null);
        $compare($directionPass, $row['trend_pass'], 'trend_filter', $at);
        if (! $session) {
            $actualTrend = $actual;
        } else {
            $actualSession = $actual;
        }
        if (in_array($actual['action'], ['BUY', 'SELL'], true)) {
            $counts[$session ? 'trend_session_entries' : 'trend_entries']++;
            $counts['accepted_v2_plans_compared']++;
            foreach (['entry_price', 'stop_loss', 'take_profit_1', 'risk_per_unit', 'rr_tp1'] as $field) {
                $require($actual['trade_plan'][$field] === $baseline['trade_plan'][$field], 'V2 changed its actual legacy plan: '.$at.' '.$field);
            }
            $require($actual['trade_plan']['take_profit_2'] === null && $actual['trade_plan']['rr_tp2'] === null
                && $actual['trade_plan']['exit_policy'] === 'full_tp1', 'V2 TP1-only policy is missing.');
        } else {
            foreach (['entry_price', 'stop_loss', 'take_profit_1', 'take_profit_2'] as $field) {
                $require(($actual['trade_plan'][$field] ?? null) === null, 'Rejected V2 crossover retains an executable plan.');
            }
        }
    }
    $snapshots[] = ['decision_at' => $at, 'signal_open' => $baseline['timestamp'], 'action' => $baseline['action'],
        'source_close_full_precision' => $bars[$i]['close'], 'entry_price' => $baseline['trade_plan']['entry_price'],
        'stop_loss' => $baseline['trade_plan']['stop_loss'], 'take_profit_1' => $baseline['trade_plan']['take_profit_1'],
        'take_profit_2' => $baseline['trade_plan']['take_profit_2'], 'risk_per_unit' => $baseline['trade_plan']['risk_per_unit'],
        'rsi_unrounded' => $rsi, 'atr_unrounded' => $atr,
        'indicators' => array_intersect_key($baseline['indicators'], array_flip(['rsi', 'atr', 'short_ma', 'long_ma'])),
        'sma200' => $actualTrend['indicators']['trend_sma_200'],
        'sma200_three_active_bars_earlier' => $actualTrend['indicators']['trend_sma_200_prior'],
        'trend_pass' => $actualTrend['action'] === $baseline['action'],
        'session_pass' => $actualSession['indicators']['entry_session_allowed'],
        'trend_session_pass' => $actualSession['action'] === $baseline['action'], 'history_bars' => $i + 1];
}
$require(count($actualTimes) === count($references) && count(array_diff_key($references, $actualTimes)) === 0, 'PHP and independent reference crossover time sets differ.');
$require($counts['baseline_crossovers'] === 760 && $counts['v2_evaluations'] === 1520, 'Expected every frozen external crossover and both filter modes.');
$require($hashes() === $before, 'Source code, plan or reference inputs changed during this verification.');
ksort($mismatches);
ksort($maxErrors);
$status = $mismatches === [] ? 'passed' : 'numerical_differences_require_replay';
$report = ['status' => $status, 'checked_at_utc' => Carbon::now('UTC')->toIso8601String(),
    'method' => 'Independently join every captured UTC provider minute, exclude NY-closed M1 before complete M5 aggregation, discover every crossover with actual PHP legacy evaluator, and evaluate actual research V2 on causal last203 session-valid bars.',
    'input_sha256' => $before[$input], 'frozen_reference_sha256' => $before[$reference],
    'provenance_audit' => ['status' => 'passed', 'captured_chunks' => count($chunkHashes), 'captured_rows' => $rawRows,
        'all_normalized_ohlc_equal_captured_provider_values' => true, 'every_requested_calendar_minute_present' => true,
        'from_inclusive' => $iso($from), 'to_exclusive' => $iso($to), 'warmup_from_inclusive' => $iso($start),
        'warmup_m5_bars_before_evaluation' => 276, 'active_m5_bars_including_warmup' => count($bars),
        'incomplete_m5_buckets' => $incomplete, 'closed_m5_buckets_after_m1_filter' => $closedBuckets, 'monthly' => $monthly,
        'captured_chunk_sha256' => $chunkHashes],
    'independent_all_entry_discovery_verified' => true,
    'frozen_parameters' => ['m5_legacy_window' => 200, 'm5_v2_window' => 203, 'sma_short' => 9, 'sma_long' => 21,
        'rsi_period' => 14, 'atr_period' => 14, 'trend_period' => 200, 'trend_slope_active_bars' => 3,
        'restricted_session_utc' => '07:00-17:00 weekdays', 'target' => 'full_tp1_1.5R'],
    'counts' => $counts, 'difference_counts_by_field' => $mismatches, 'maximum_absolute_errors_by_field' => $maxErrors,
    'difference_examples' => $examples, 'source_and_inputs_verified_unchanged_during_run' => $before,
    'snapshot_policy' => 'Canonical actual-PHP baseline absolute levels; V2 admits only its trend/session gates, preserves entry/SL/TP1, removes TP2 and is always research_only. These snapshots allow numerical replay without changing frozen candidate rules.',
    'snapshots' => $snapshots,
    'limits' => ['This checks implementation and provenance; it does not calculate or certify returns.',
        'Captured JSON response wrappers and hashes prove consistency with preserved responses, not independent third-party authentication of the provider.',
        'Provider historical OHLC is not independently confirmed broker bid. Historical bid/ask, variable spread and account fills are unavailable; $0.37 spread is an explicit assumption.',
        'NY regular sessions exclude ordinary weekend and daily closures; historical holiday and early-close status is unavailable.',
        'No future bars are passed to the evaluators. Warmup supplies indicators only; the separate replay must begin flat at June1.',
        'Raw source-candle decisions at market close are audited before the separate live application preflight. Execution must reject closed-session decisions.',
        'This previously uninspected June-August period chronologically precedes inspected September-October development; it is not forward live validation.']];
$output = $study.'/external-production-parity.json';
file_put_contents($root.'/'.$output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['status' => $status, 'counts' => $counts, 'difference_counts_by_field' => $mismatches,
    'monthly_coverage' => $monthly, 'canonical_php_snapshots_output' => $output], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
exit($mismatches === [] ? 0 : 1);
