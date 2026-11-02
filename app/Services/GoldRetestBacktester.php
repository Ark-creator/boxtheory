<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/** Offline diagnostic only. Net R is not an account-currency return. */
class GoldRetestBacktester
{
    public function __construct(private readonly GoldTrendRetestStrategy $strategy = new GoldTrendRetestStrategy) {}

    public function run(array $data, float $spread, float $slippage, float $feePerUnit, ?Carbon $from = null, ?Carbon $to = null): array
    {
        foreach ([$spread, $slippage, $feePerUnit] as $cost) {
            if (! is_finite($cost) || $cost < 0) {
                throw new \InvalidArgumentException('Costs must be finite and nonnegative, in USD per ounce.');
            }
        }
        if (($data['source'] ?? null) !== 'mt5' || ! is_string($data['symbol'] ?? null) || trim($data['symbol']) === ''
            || ! is_array($data['m15'] ?? null) || ! is_array($data['h1'] ?? null)
            || count($data['m15']) < 50 || count($data['h1']) < 220) {
            throw new \InvalidArgumentException('Provide an MT5 gold export with symbol, at least 50 M15 bars and 220 H1 bars.');
        }
        $dollarGold = preg_match('/^XAU[\/_-]?USD(?:[._#-]?[A-Za-z0-9]+)?$/i', $data['symbol'])
            || (strtoupper($data['symbol']) === 'GOLD' && ($data['quote_currency'] ?? null) === 'USD');
        if (! $dollarGold) {
            throw new \InvalidArgumentException('Use an XAUUSD broker symbol, or GOLD with quote_currency=USD. Costs assume USD per ounce.');
        }
        foreach ([$from, $to] as $boundary) {
            if ($boundary && ($boundary->timestamp % 900 !== 0 || $boundary->microsecond !== 0)) {
                throw new \InvalidArgumentException('Evaluation boundaries must align with UTC M15 opens.');
            }
        }
        if ($from && $to && $from->gte($to)) {
            throw new \InvalidArgumentException('The evaluation start must precede the exclusive end.');
        }

        // The engine visits history once. Each snapshot uses only bars closed at its timestamp.
        $snapshots = $this->strategy->evaluateHistory($data['m15'], $data['h1'], 'MT5 '.$data['symbol']);
        $snapshots = array_filter($snapshots, function ($signal) use ($from, $to) {
            $at = Carbon::parse($signal['timestamp'])->utc();

            return (! $from || $at->gte($from)) && (! $to || $at->lt($to));
        });
        $rejections = array_fill_keys(['duplicate_setup', 'existing_position', 'daily_entry_limit', 'cooldown', 'entry_bounds', 'spread_or_reward_risk', 'no_immediate_next_open'], 0);
        $seen = $processed = $dailyEntries = [];
        $lastEntry = null;
        $position = null;
        $trades = [];
        $evaluatedBars = $ambiguous = $gapCount = $positionGaps = 0;
        $gapExamples = [];
        $previousOpen = null;
        $firstAt = $lastAt = null;

        foreach ($data['m15'] as $bar) {
            $at = Carbon::parse($bar['timestamp'])->utc();
            $gap = $previousOpen !== null && $at->timestamp - $previousOpen > 900;
            if ($gap) {
                $gapCount++;
                if (count($gapExamples) < 10) {
                    $gapExamples[] = ['previous_open' => Carbon::createFromTimestampUTC($previousOpen)->toIso8601String(), 'next_open' => $at->toIso8601String()];
                }
            }
            $previousOpen = $at->timestamp;
            if (($from && $at->lt($from)) || ($to && $at->gte($to))) {
                continue;
            }
            $positionGaps += (int) ($gap && $position !== null);
            $evaluatedBars++;
            $firstAt ??= $at->toIso8601String();
            $lastAt = $at->copy()->addMinutes(15)->toIso8601String();
            $key = $at->toIso8601String();
            $signal = $snapshots[$key] ?? null;
            if ($signal) {
                $processed[$key] = true;
                $id = $signal['setup_id'];
                $date = $at->toDateString();
                $reason = match (true) {
                    isset($seen[$id]) => 'duplicate_setup',
                    $position !== null => 'existing_position',
                    ($dailyEntries[$date] ?? 0) >= 2 => 'daily_entry_limit',
                    $lastEntry !== null && $at->timestamp - $lastEntry < 1800 => 'cooldown',
                    default => null,
                };
                // An execution rejection consumes this setup too. No chasing the same retest.
                $seen[$id] = true;
                if ($reason) {
                    $rejections[$reason]++;
                } else {
                    $plan = $signal['trade_plan'];
                    $side = $signal['action'] === 'BUY' ? 1 : -1;
                    // MT5 bars are bid; long entry buys at ask and short entry sells at bid.
                    $entry = (float) $bar['open'] + ($side === 1 ? $spread : 0) + $side * $slippage;
                    $risk = ($entry - $plan['stop_loss']) * $side;
                    $reward = ($plan['take_profit_1'] - $entry) * $side;
                    if ($entry < $plan['entry_min_price'] || $entry > $plan['entry_max_price']) {
                        $rejections['entry_bounds']++;
                    } elseif ($risk <= 0 || $reward <= 0 || $spread > min(1.0, 0.1 * $risk) || $reward / $risk < 1.8) {
                        $rejections['spread_or_reward_risk']++;
                    } else {
                        $position = [
                            'setup_id' => $id, 'side' => $side, 'signal_at' => $signal['timestamp'],
                            'opened_at' => $key, 'entry' => $entry, 'sl' => $plan['stop_loss'],
                            'tp' => $plan['take_profit_1'], 'risk' => $risk,
                        ];
                        $dailyEntries[$date] = ($dailyEntries[$date] ?? 0) + 1;
                        $lastEntry = $at->timestamp;
                    }
                }
            }
            if ($position === null) {
                continue;
            }
            $p = $position;
            // Short exits buy at ask. Long exits sell at bid. Slippage applies to both exits.
            $offset = $p['side'] === -1 ? $spread : 0;
            $open = (float) $bar['open'] + $offset;
            $high = (float) $bar['high'] + $offset;
            $low = (float) $bar['low'] + $offset;
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
            $reason = $gapStop ? 'gap_stop' : ($gapTarget ? 'gap_target' : ($both ? 'both_hit_stop_first' : ($stop ? 'stop_loss' : 'take_profit')));
            $exit -= $p['side'] * $slippage;
            $net = (($exit - $p['entry']) * $p['side'] - $feePerUnit) / $p['risk'];
            $trades[] = $p + [
                'exit_bar_open' => $key, 'exit_bar_close' => $at->copy()->addMinutes(15)->toIso8601String(),
                'exit_reason' => $reason, 'exit' => $exit, 'net_r' => $net,
                'outcome' => $net > 0 ? 'win' : ($net < 0 ? 'loss' : 'breakeven'),
            ];
            $position = null;
        }
        if ($evaluatedBars === 0) {
            throw new \InvalidArgumentException('The evaluation period contains no M15 bars.');
        }
        $rejections['no_immediate_next_open'] = count(array_diff_key($snapshots, $processed));
        $returns = array_column($trades, 'net_r');
        $wins = array_filter($returns, fn ($r) => $r > 0);
        $losses = array_filter($returns, fn ($r) => $r < 0);
        $grossLoss = -array_sum($losses);
        $total = $peak = $drawdown = 0.0;
        $years = [];
        foreach ($trades as $trade) {
            $total += $trade['net_r'];
            $peak = max($peak, $total);
            $drawdown = max($drawdown, $peak - $total);
            $year = substr($trade['exit_bar_open'], 0, 4);
            $years[$year] = ($years[$year] ?? 0) + $trade['net_r'];
        }

        return [
            'strategy' => $this->strategy::SLUG,
            'simulation' => 'hypothetical M15 bid-bar diagnostic; not broker execution results',
            'source' => $data['source'], 'symbol' => $data['symbol'],
            'evaluation' => ['first_bar_open' => $firstAt, 'last_bar_close' => $lastAt, 'bars' => $evaluatedBars],
            'costs_usd_per_ounce' => ['spread' => $spread, 'slippage_each_side' => $slippage, 'round_trip_fee' => $feePerUnit],
            'execution_rules' => ['one_position' => true, 'max_entries_per_utc_day' => 2, 'minimum_minutes_between_entries' => 30, 'one_attempt_per_setup' => true],
            'data_quality' => ['m15_nonconsecutive_intervals_in_export' => $gapCount, 'position_nonconsecutive_intervals' => $positionGaps,
                'first_ten_intervals' => $gapExamples, 'note' => 'Includes scheduled market closures. Verify broker coverage: missing tradable bars can change outcomes.'],
            'signals' => count($snapshots), 'entry_rejections' => $rejections,
            'closed_trades' => count($trades), 'wins' => count($wins), 'losses' => count($losses),
            'breakeven' => count($trades) - count($wins) - count($losses),
            'win_rate' => $trades ? count($wins) / count($trades) : null,
            'expectancy_r' => $trades ? array_sum($returns) / count($trades) : null,
            'profit_factor_r' => $grossLoss > 0 ? array_sum($wins) / $grossLoss : null,
            'profit_factor_undefined_reason' => $grossLoss > 0 ? null : 'No losing closed trades; ratio is undefined.',
            'net_r' => $total, 'max_closed_trade_drawdown_r' => $drawdown, 'annual_net_r' => $years,
            'ambiguous_bars_stop_first' => $ambiguous, 'open_position_at_end' => $position, 'trades' => $trades,
            'limitations' => [
                'Fixed spread and slippage; no historical news, swaps, variable spread, lots, margin, equity sizing, account loss gates or latency.',
                'An SL/TP hit time is only located within its M15 bar. Both-hit bars resolve stop first unless the opening quote has already crossed a level.',
                'A gap stop fills at the adverse opening quote; a gap target receives no price improvement.',
                'Open positions are censored and excluded from win rate, expectancy and closed-trade drawdown. Floating drawdown is not measured.',
                'Missing bars are not reconstructed. A signal without its immediate next M15 open is rejected; missing path data can change other exits.',
                'Synthetic software tests demonstrate rule behavior, not profitability or improvement over the four previous strategies.',
            ],
        ];
    }
}
