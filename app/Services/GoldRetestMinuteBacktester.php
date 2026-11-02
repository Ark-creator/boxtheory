<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/** Offline M1 execution diagnostic. Reported minute spreads are lower-bound proxies. */
class GoldRetestMinuteBacktester
{
    public function __construct(private readonly GoldTrendRetestStrategy $strategy = new GoldTrendRetestStrategy) {}

    public function run(array $data, float $pointSize, float $slippage, float $feePerUnit, ?Carbon $from = null, ?Carbon $to = null, float $spreadMultiplier = 1.0): array
    {
        if (! is_finite($pointSize) || $pointSize <= 0) {
            throw new \InvalidArgumentException('Declare a positive finite broker point size.');
        }
        foreach ([$slippage, $feePerUnit, $spreadMultiplier] as $cost) {
            if (! is_finite($cost) || $cost < 0) {
                throw new \InvalidArgumentException('Cost assumptions must be finite and nonnegative.');
            }
        }
        if (($data['source'] ?? null) !== 'mt5' || ! preg_match('/^XAU[\/_-]?USD(?:[._#-]?[A-Za-z0-9]+)?$/i', $data['symbol'] ?? '')
            || ! is_array($data['m1'] ?? null) || ! $data['m1'] || ! is_array($data['m15'] ?? null) || ! is_array($data['h1'] ?? null)) {
            throw new \InvalidArgumentException('An MT5 XAUUSD M1 export with complete M15/H1 aggregates is required.');
        }
        foreach ([$from, $to] as $date) {
            if ($date && ($date->timestamp % 900 !== 0 || $date->microsecond !== 0)) {
                throw new \InvalidArgumentException('Evaluation boundaries must align with UTC M15 opens.');
            }
        }
        if ($from && $to && $from->gte($to)) {
            throw new \InvalidArgumentException('Evaluation start must precede its exclusive end.');
        }
        $minutes = [];
        $previous = null;
        foreach ($data['m1'] as $bar) {
            if (! is_string($bar['timestamp'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00(?:Z|\+00:00)$/D', $bar['timestamp'])) {
                throw new \InvalidArgumentException('M1 timestamps must be explicit UTC minute opens.');
            }
            $stamp = str_replace('Z', '+00:00', $bar['timestamp']);
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $stamp);
            $errors = \DateTimeImmutable::getLastErrors();
            if (! $date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d\TH:i:sP') !== $stamp) {
                throw new \InvalidArgumentException('M1 timestamp is not a valid calendar date.');
            }
            $time = $date->getTimestamp();
            if ($previous !== null && $time <= $previous) {
                throw new \InvalidArgumentException('M1 bars must be strictly ascending and unique.');
            }
            foreach (['open', 'high', 'low', 'close', 'spread_points'] as $field) {
                if (! isset($bar[$field]) || ! is_numeric($bar[$field]) || ! is_finite((float) $bar[$field]) || $bar[$field] < 0 || ($field !== 'spread_points' && $bar[$field] == 0)) {
                    throw new \InvalidArgumentException('M1 prices and spread points must be finite and valid.');
                }
                $bar[$field] = (float) $bar[$field];
            }
            if ($bar['high'] < max($bar['low'], $bar['open'], $bar['close']) || $bar['low'] > min($bar['open'], $bar['close'])) {
                throw new \InvalidArgumentException('Inconsistent M1 OHLC prices.');
            }
            $bar['time'] = $time;
            $minutes[] = $bar;
            $previous = $time;
        }
        $snapshots = $this->strategy->evaluateHistory($data['m15'], $data['h1'], 'MT5 '.$data['symbol']);
        $start = $from?->timestamp ?? $minutes[0]['time'];
        $end = $to?->timestamp ?? $minutes[array_key_last($minutes)]['time'] + 60;
        $snapshots = array_filter($snapshots, fn ($s) => ($at = Carbon::parse($s['timestamp'])->timestamp) >= $start && $at < $end);
        $rejections = array_fill_keys(['duplicate_setup', 'existing_position', 'daily_entry_limit', 'cooldown', 'entry_bounds', 'spread_or_reward_risk', 'no_immediate_next_open'], 0);
        $seen = $processed = $entries = [];
        $position = null;
        $lastEntry = $previous = null;
        $trades = [];
        $ambiguous = $gaps = $visited = 0;

        foreach ($minutes as $bar) {
            $time = $bar['time'];
            $gap = $previous !== null && $time - $previous > 60;
            $previous = $time;
            if ($time < $start || $time >= $end) {
                continue;
            }
            $visited++;
            $gaps += (int) $gap;
            if ($gap && $position !== null) {
                $position['crossed_nonconsecutive_minutes'] = true;
            }
            $key = gmdate('Y-m-d\TH:i:s', $time).'+00:00';
            $spread = $bar['spread_points'] * $pointSize * $spreadMultiplier;
            if (isset($snapshots[$key])) {
                $signal = $snapshots[$key];
                $processed[$key] = true;
                $id = $signal['setup_id'];
                $day = substr($key, 0, 10);
                $reason = match (true) {
                    isset($seen[$id]) => 'duplicate_setup',
                    $position !== null => 'existing_position',
                    ($entries[$day] ?? 0) >= 2 => 'daily_entry_limit',
                    $lastEntry !== null && $time - $lastEntry < 1800 => 'cooldown',
                    default => null,
                };
                $seen[$id] = true;
                if ($reason) {
                    $rejections[$reason]++;
                } else {
                    $plan = $signal['trade_plan'];
                    $side = $signal['action'] === 'BUY' ? 1 : -1;
                    $entry = $bar['open'] + ($side === 1 ? $spread : 0) + $side * $slippage;
                    $risk = ($entry - $plan['stop_loss']) * $side;
                    $reward = ($plan['take_profit_1'] - $entry) * $side;
                    if ($entry < $plan['entry_min_price'] || $entry > $plan['entry_max_price']) {
                        $rejections['entry_bounds']++;
                    } elseif ($risk <= 0 || $reward <= 0 || $spread > min(1.0, 0.1 * $risk) || $reward / $risk < 1.8) {
                        $rejections['spread_or_reward_risk']++;
                    } else {
                        $position = ['setup_id' => $id, 'side' => $side, 'opened_at' => $key,
                            'entry' => $entry, 'sl' => $plan['stop_loss'], 'tp' => $plan['take_profit_1'], 'risk' => $risk,
                            'entry_spread_proxy' => $spread, 'crossed_nonconsecutive_minutes' => false];
                        $entries[$day] = ($entries[$day] ?? 0) + 1;
                        $lastEntry = $time;
                    }
                }
            }
            if ($position === null) {
                continue;
            }
            $p = $position;
            $offset = $p['side'] === -1 ? $spread : 0;
            $open = $bar['open'] + $offset;
            $high = $bar['high'] + $offset;
            $low = $bar['low'] + $offset;
            $stop = $p['side'] === 1 ? $low <= $p['sl'] : $high >= $p['sl'];
            $target = $p['side'] === 1 ? $high >= $p['tp'] : $low <= $p['tp'];
            if (! $stop && ! $target) {
                continue;
            }
            $gapStop = ($open - $p['sl']) * $p['side'] <= 0;
            $gapTarget = ($open - $p['tp']) * $p['side'] >= 0;
            $both = $stop && $target && ! $gapStop && ! $gapTarget;
            $ambiguous += (int) $both;
            $exit = $gapStop ? $open : ($gapTarget ? $p['tp'] : ($stop ? $p['sl'] : $p['tp']));
            $exit -= $p['side'] * $slippage;
            $net = (($exit - $p['entry']) * $p['side'] - $feePerUnit) / $p['risk'];
            $trades[] = $p + ['exit_minute_open' => $key, 'exit_minute_close' => gmdate('Y-m-d\TH:i:s', $time + 60).'+00:00',
                'exit_reason' => $gapStop ? 'gap_stop' : ($gapTarget ? 'gap_target' : ($both ? 'both_hit_stop_first' : ($stop ? 'stop_loss' : 'take_profit'))),
                'both_hit_minute' => $both, 'exit' => $exit, 'net_r' => $net,
                'outcome' => $net > 0 ? 'win' : ($net < 0 ? 'loss' : 'breakeven')];
            $position = null;
        }
        if (! $visited) {
            throw new \InvalidArgumentException('No M1 bars in evaluation range.');
        }
        $rejections['no_immediate_next_open'] = count(array_diff_key($snapshots, $processed));

        return [
            'strategy' => $this->strategy::SLUG, 'simulation' => 'conditional M1 bid-bar diagnostic; minute spread minimum is a lower-bound proxy',
            'timestamp_assumption' => $data['timestamp_assumption'] ?? 'not supplied',
            'symbol' => $data['symbol'], 'from_inclusive' => gmdate('c', $start), 'to_exclusive' => gmdate('c', $end),
            'cost_assumptions' => ['point_size_unverified' => $pointSize, 'reported_minute_minimum_spread_multiplier' => $spreadMultiplier,
                'slippage_usd_per_ounce_each_fill' => $slippage, 'round_trip_fee_usd_per_ounce' => $feePerUnit],
            'signals' => count($snapshots), 'entry_rejections' => $rejections,
            'metrics' => $this->metrics($trades),
            'contiguous_path_metrics' => $this->metrics(array_values(array_filter($trades, fn ($t) => ! $t['crossed_nonconsecutive_minutes']))),
            'trades_crossing_nonconsecutive_minutes' => count(array_filter($trades, fn ($t) => $t['crossed_nonconsecutive_minutes'])),
            'nonconsecutive_intervals_in_evaluation' => $gaps, 'ambiguous_minutes_stop_first' => $ambiguous,
            'open_position_at_end' => $position, 'trades' => $trades,
            'limitations' => [
                'Timestamp conversion and broker point size are explicit assumptions, not verified terminal metadata.',
                'MT5 minute SPREAD is the minimum spread within a minute; treating it as constant can understate costs and distort short exits.',
                'Both SL/TP within one minute resolve stop first; no tick order is available.',
                'Quotes during missing intervals are unknown. All-trade metrics assume no unseen execution; contiguous-path metrics exclude trades spanning gaps.',
                'No historical news, swaps, variable intraminute spread, volume rounding, margin, equity sizing, account loss gates or execution latency.',
                'Chronological segments are preliminary diagnostics; this is not proof of superiority or a genuine untouched holdout.',
            ],
        ];
    }

    private function metrics(array $trades): array
    {
        $returns = array_column($trades, 'net_r');
        $wins = array_filter($returns, fn ($r) => $r > 0);
        $losses = array_filter($returns, fn ($r) => $r < 0);
        $grossLoss = -array_sum($losses);
        $total = $peak = $drawdown = 0.0;
        foreach ($returns as $r) {
            $total += $r;
            $peak = max($peak, $total);
            $drawdown = max($drawdown, $peak - $total);
        }

        return ['closed_trades' => count($trades), 'wins' => count($wins), 'losses' => count($losses),
            'breakeven' => count($trades) - count($wins) - count($losses),
            'win_rate' => $trades ? count($wins) / count($trades) : null,
            'net_r' => $total, 'expectancy_r' => $trades ? $total / count($trades) : null,
            'profit_factor_r' => $grossLoss > 0 ? array_sum($wins) / $grossLoss : null,
            'max_closed_trade_drawdown_r' => $drawdown];
    }
}
