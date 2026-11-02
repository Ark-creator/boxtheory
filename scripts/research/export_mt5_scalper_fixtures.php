<?php

// Offline reference export only. Does not boot Laravel, read .env, call an API,
// send mail, connect to a database, or interact with an MT5 account.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Services\MarketDataGuard;
use App\Services\RsiGoldScalperV2Strategy;
use Illuminate\Support\Carbon;

$root = dirname(__DIR__, 2);
$guard = new MarketDataGuard;
$engine = new RsiGoldScalperV2Strategy;
$read = static fn (string $path): array => json_decode(file_get_contents($root.'/'.$path), true, 512, JSON_THROW_ON_ERROR);
$rows = [];
$inputs = [];
$addCases = static function (string $name, array $bars, array $decisions) use (&$rows, $engine): void {
    $byOpen = array_flip(array_column($bars, 'timestamp'));
    foreach ($decisions as $row) {
        $index = $byOpen[$row['signal_open']] ?? throw new RuntimeException('Missing source bar.');
        if ($index < 202) {
            throw new RuntimeException('Insufficient causal history.');
        }
        $window = array_slice($bars, $index - 202, 203);
        $decision = Carbon::parse($row['decision_at'])->utc();
        $actual = $engine->evaluate($window, null, 'offline MT5 port reference', $decision->copy()->addSeconds(20), true);
        if (! in_array($actual['action'], ['BUY', 'SELL', 'HOLD'], true)) {
            throw new RuntimeException('Unexpected reference action: '.$actual['action']);
        }
        $side = $actual['action'] === 'BUY' ? 1 : ($actual['action'] === 'SELL' ? -1 : 0);
        $indicators = $actual['indicators'];
        $plan = $actual['trade_plan'];
        $rows[] = ['name' => $name.':'.$row['decision_at'], 'bars' => array_map(static fn ($bar) => [
            'time' => strtotime($bar['timestamp']), 'open' => $bar['open'], 'high' => $bar['high'],
            'low' => $bar['low'], 'close' => $bar['close'],
        ], $window), 'expected' => [
            'side' => $side, 'entry' => $side ? $plan['entry_price'] : 0,
            'stop' => $side ? $plan['stop_loss'] : 0, 'target' => $side ? $plan['take_profit_1'] : 0,
            'risk' => $side ? $plan['risk_per_unit'] : 0,
            'atr' => $indicators['atr'], 'rsi' => $indicators['rsi'],
            'fast' => $indicators['short_ma'], 'slow' => $indicators['long_ma'],
            'trend_now' => $indicators['trend_sma_200'], 'trend_prior' => $indicators['trend_sma_200_prior'],
            'decision_utc' => $decision->getTimestamp(),
        ]];
    }
};

foreach ([3, 0] as $clock) {
    $path = 'storage/app/private/signal-research/scalper-upgrade-2026-11-02/input-utcplus'.$clock.'.json';
    $data = $read($path);
    $inputs[$path] = hash_file('sha256', $root.'/'.$path);
    $end = Carbon::parse($data['m1'][array_key_last($data['m1'])]['timestamp'])->utc()->addMinute();
    $bars = $guard->validateCandles($data['m5'], 300, $end, false);
    $bars = array_values(array_filter($bars, static function ($bar) use ($guard) {
        $time = Carbon::parse($bar['timestamp'])->utc();

        return $guard->marketStatus('XAUUSD', $time)['open'] && $guard->marketStatus('XAUUSD', $time->copy()->addSeconds(299))['open'];
    }));
    $locked = $read('docs/research/scalper-upgrade-2026-11-02/filter-decisions-utcplus'.$clock.'.json');
    $addCases('mt5-source-utcplus'.$clock, $bars, $locked['rows']);
}

$path = 'storage/app/private/signal-research/external-validation/twelvedata-jun-aug-2026-m1.json';
$data = $read($path);
$inputs[$path] = hash_file('sha256', $root.'/'.$path);
$bars = [];
$bucket = [];
$bucketTime = null;
$flush = static function () use (&$bars, &$bucket, &$bucketTime, $guard): void {
    if (count($bucket) !== 5) {
        return;
    }
    foreach ($bucket as $offset => $row) {
        if (strtotime($row['timestamp']) !== $bucketTime + $offset * 60) {
            return;
        }
    }
    $at = Carbon::createFromTimestampUTC($bucketTime);
    if (! $guard->marketStatus('XAUUSD', $at)['open'] || ! $guard->marketStatus('XAUUSD', $at->copy()->addSeconds(299))['open']) {
        return;
    }
    $bars[] = ['timestamp' => gmdate('Y-m-d\TH:i:s+00:00', $bucketTime), 'open' => $bucket[0]['open'],
        'high' => max(array_column($bucket, 'high')), 'low' => min(array_column($bucket, 'low')),
        'close' => $bucket[4]['close']];
};
foreach ($data['m1'] as $row) {
    $time = strtotime($row['timestamp']);
    $group = intdiv($time, 300) * 300;
    if ($bucketTime !== $group) {
        $flush();
        $bucketTime = $group;
        $bucket = [];
    }
    $bucket[] = $row;
}
$flush();
$snapshots = $read('docs/research/scalper-upgrade-2026-11-02/external-entry-snapshots.json');
$addCases('external-five-decimal-source', $bars, $snapshots['snapshots']);
if (count($rows) !== 1760) {
    throw new RuntimeException('Expected 1,000 development and 760 external crossover cases.');
}
$rounding = [];
foreach ([4124.36155, -4124.36155, 1.005, -1.005, 2.675, 3875.41985, 0.00005, -0.00005, 100.0, 0.0] as $value) {
    foreach ([2, 4, 8] as $places) {
        $rounding[] = ['value' => $value, 'places' => $places, 'expected' => round($value, $places)];
    }
}
$output = $root.'/storage/app/private/signal-research/mt5-port-2026-11-02/verification-fixtures.json';
if (! is_dir(dirname($output))) {
    mkdir(dirname($output), 0777, true);
}
$payload = ['method' => 'Actual PHP strategy evaluated on causal last203 active-session M5 bars; no application boot or trading.',
    'php_version' => PHP_VERSION, 'input_sha256' => $inputs, 'rows' => $rows, 'rounding' => $rounding];
file_put_contents($output, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
echo json_encode(['fixture_rows' => count($rows), 'rounding_cases' => count($rounding),
    'sha256' => hash_file('sha256', $output), 'output' => $output], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
