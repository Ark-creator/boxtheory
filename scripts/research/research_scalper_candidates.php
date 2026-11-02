<?php

// Offline research bridge: invokes the unchanged RSI evaluator/execution model.
require getcwd().'/vendor/autoload.php';

use App\Services\MarketDataGuard;
use App\Services\RsiScalperMinuteBacktester;
use Illuminate\Support\Carbon;

if ($argc !== 4) {
    throw new InvalidArgumentException('Expected data JSON, experiment JSON, and an existing raw output directory.');
}
$data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$experiment = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$directory = realpath($argv[3]);
if ($directory === false) {
    throw new InvalidArgumentException('Raw output directory is unavailable.');
}
$tester = new RsiScalperMinuteBacktester;
$prepared = $tester->prepare($data);
$guard = new MarketDataGuard;
$last = Carbon::parse($data['m1'][array_key_last($data['m1'])]['timestamp'])->addMinute();
$bars = $guard->validateCandles($data['m5'], 300, $last, false);
$bars = array_values(array_filter($bars, function ($bar) use ($guard) {
    $at = Carbon::parse($bar['timestamp']);

    return $guard->marketStatus('XAUUSD', $at)['open'] && $guard->marketStatus('XAUUSD', $at->copy()->addSeconds(299))['open'];
}));
$byOpening = array_flip(array_column($bars, 'timestamp'));
$closes = array_column($bars, 'close');
$maps = ['baseline' => [], 'trend' => [], 'session' => [], 'trend-session' => []];
$filterRows = [];
foreach ($prepared['entries'] as $decision => $signal) {
    $opening = Carbon::parse($signal['timestamp'])->toIso8601String();
    $i = $byOpening[$opening] ?? throw new RuntimeException('Prepared RSI candle is missing from the active M5 series.');
    $mean = $prior = null;
    $trend = false;
    if ($i >= 202) {
        $mean = round(array_sum(array_slice($closes, $i - 199, 200)) / 200, 8);
        $prior = round(array_sum(array_slice($closes, $i - 202, 200)) / 200, 8);
        $trend = $signal['action'] === 'BUY' ? $closes[$i] > $mean && $mean > $prior : $closes[$i] < $mean && $mean < $prior;
    }
    $at = Carbon::parse($decision)->utc();
    $session = ! $at->isWeekend() && $at->hour >= 7 && $at->hour < 17;
    $maps['baseline'][$decision] = $signal;
    if ($trend) {
        $maps['trend'][$decision] = $signal;
    }
    if ($session) {
        $maps['session'][$decision] = $signal;
    }
    if ($trend && $session) {
        $maps['trend-session'][$decision] = $signal;
    }
    $filterRows[] = ['decision_at' => $decision, 'signal_open' => $opening, 'action' => $signal['action'],
        'm5_close' => $closes[$i], 'sma200' => $mean, 'sma200_three_active_bars_earlier' => $prior,
        'trend_pass' => $trend, 'session_pass' => $session, 'history_bars' => $i + 1];
}
file_put_contents($directory.'/'.$experiment['filter_file'], json_encode(['baseline_diagnostics' => $prepared['diagnostics'], 'rows' => $filterRows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

function candidatePeriodMetrics(array $trades): array
{
    $returns = array_column($trades, 'net_r');
    $positive = array_filter($returns, fn ($r) => $r > 0);
    $negative = array_filter($returns, fn ($r) => $r < 0);
    $loss = -array_sum($negative);

    return ['closed_trades' => count($trades), 'wins' => count($positive), 'losses' => count($negative),
        'net_r' => array_sum($returns), 'expectancy_r' => $trades ? array_sum($returns) / count($trades) : null,
        'profit_factor_r' => $loss > 0 ? array_sum($positive) / $loss : null,
        'long_trades' => count(array_filter($trades, fn ($t) => $t['side'] === 1)),
        'short_trades' => count(array_filter($trades, fn ($t) => $t['side'] === -1)),
        'gap_crossing_trades' => count(array_filter($trades, fn ($t) => $t['crossed_nonconsecutive_minutes']))];
}

$rows = [];
foreach ($experiment['cases'] as $case) {
    if (! isset($maps[$case['candidate']]) || ! preg_match('/^[a-z0-9-]+\.json$/D', $case['file'])) {
        throw new InvalidArgumentException('Unknown preregistered candidate or unsafe output filename.');
    }
    $filtered = $prepared;
    $filtered['entries'] = $maps[$case['candidate']];
    $filtered['diagnostics']['candidate_filter'] = $case['candidate'];
    $filtered['diagnostics']['entry_signals_before_filter'] = count($prepared['entries']);
    $filtered['diagnostics']['entry_signals_after_filter'] = count($filtered['entries']);
    $result = $tester->run($data, $filtered, $case['options']);
    $months = $weeks = [];
    $holding = [];
    foreach ($result['trades'] as $trade) {
        $exit = Carbon::parse($trade['exit_minute_open'])->utc();
        $months[$exit->format('Y-m')][] = $trade;
        $weeks[$exit->format('o-\WW')][] = $trade;
        $holding[] = ($exit->timestamp - Carbon::parse($trade['opened_at'])->timestamp) / 60;
    }
    sort($holding);
    $n = count($holding);
    $result['candidate_filter'] = $case['candidate'];
    $result['monthly_exit_metrics'] = array_map('candidatePeriodMetrics', $months);
    $result['iso_week_exit_metrics'] = array_map('candidatePeriodMetrics', $weeks);
    $result['side_metrics'] = ['long' => candidatePeriodMetrics(array_values(array_filter($result['trades'], fn ($t) => $t['side'] === 1))),
        'short' => candidatePeriodMetrics(array_values(array_filter($result['trades'], fn ($t) => $t['side'] === -1)))];
    $result['holding_minutes'] = ['median' => $n ? ($n % 2 ? $holding[intdiv($n, 2)] : ($holding[$n / 2 - 1] + $holding[$n / 2]) / 2) : null,
        'mean' => $n ? array_sum($holding) / $n : null, 'maximum' => $n ? max($holding) : null,
        'above_60_minutes' => count(array_filter($holding, fn ($m) => $m > 60)), 'above_240_minutes' => count(array_filter($holding, fn ($m) => $m > 240))];
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    file_put_contents($directory.'/'.$case['file'], $json);
    unset($result['trades']);
    $rows[] = $case + ['result_sha256' => hash('sha256', $json), 'result' => $result];
}
echo json_encode(['diagnostics' => $prepared['diagnostics'], 'filtered_signal_counts' => array_map('count', $maps), 'rows' => $rows], JSON_THROW_ON_ERROR);
