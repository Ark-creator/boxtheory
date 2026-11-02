<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/** Offline execution diagnostic of the existing RSI evaluator, without rule changes. */
class RsiScalperMinuteBacktester
{
    public const SLUG = 'rsi-gold-scalper';

    public function __construct(private readonly TradingService $strategy = new TradingService, private readonly MarketDataGuard $guard = new MarketDataGuard) {}

    public function prepare(array $data): array
    {
        $minutes = $this->minutes($data);
        $m5 = $this->bars($data['m5'], 300, false);
        $m5 = $this->guard->validateCandles($m5, 300, Carbon::createFromTimestampUTC($minutes[array_key_last($minutes)]['time'] + 60), false);
        $closedCount = count($m5);
        $m5 = array_values(array_filter($m5, function ($bar) {
            $at = Carbon::parse($bar['timestamp']);

            return $this->guard->marketStatus('XAUUSD', $at)['open']
                && $this->guard->marketStatus('XAUUSD', $at->copy()->addSeconds(299))['open'];
        }));
        $entries = $closeLong = $closeShort = [];
        $decisions = $closedDecisions = 0;
        for ($i = 199; $i < count($m5); $i++) {
            $window = array_slice($m5, $i - 199, 200);
            $decision = Carbon::parse($m5[$i]['timestamp'])->addMinutes(5)->toIso8601String();
            $decisions++;
            $closedDecisions += (int) (! $this->guard->marketStatus('XAUUSD', Carbon::parse($decision))['open']);
            $flat = $this->strategy->evaluateRsiMovingAverage($window, null, [], 'MT5 '.$data['symbol']);
            $long = $this->strategy->evaluateRsiMovingAverage($window, 'long', [], 'MT5 '.$data['symbol']);
            $short = $this->strategy->evaluateRsiMovingAverage($window, 'short', [], 'MT5 '.$data['symbol']);
            if (in_array($flat['action'], ['BUY', 'SELL'], true)) {
                $entries[$decision] = $flat;
            }
            if ($long['action'] === 'CLOSE') {
                $closeLong[$decision] = $long;
            }
            if ($short['action'] === 'CLOSE') {
                $closeShort[$decision] = $short;
            }
        }

        return ['entries' => $entries, 'close_long' => $closeLong, 'close_short' => $closeShort,
            'diagnostics' => ['input_sha256' => $this->fingerprint($data), 'm1_bars' => count($minutes),
                'input_m5_bars' => count($data['m5']), 'completed_m5_bars' => $closedCount,
                'active_m5_bars' => count($m5), 'closed_session_m5_removed' => $closedCount - count($m5),
                'rolling_window_bars' => 200, 'warmup_bars_before_first_decision' => min(199, count($m5)),
                'evaluated_decisions' => $decisions, 'closed_market_decision_times' => $closedDecisions,
                'entry_signals' => count($entries), 'buy_signals' => count(array_filter($entries, fn ($s) => $s['action'] === 'BUY')),
                'sell_signals' => count(array_filter($entries, fn ($s) => $s['action'] === 'SELL')),
                'close_long_signals' => count($closeLong), 'close_short_signals' => count($closeShort),
                'evaluator' => TradingService::class.'::evaluateRsiMovingAverage with unchanged defaults']];
    }

    public function run(array $data, array $prepared, array $options): array
    {
        $minutes = $this->minutes($data);
        foreach (['point_size', 'slippage', 'fee', 'spread_multiplier', 'target', 'position_aware'] as $field) {
            if (! array_key_exists($field, $options)) {
                throw new \InvalidArgumentException('Every execution assumption must be explicit: missing '.$field.'.');
            }
        }
        foreach (['point_size', 'slippage', 'fee', 'spread_multiplier'] as $field) {
            if (! is_numeric($options[$field]) || ! is_finite((float) $options[$field]) || $options[$field] < 0) {
                throw new \InvalidArgumentException('Costs and point size must be finite and nonnegative.');
            }
        }
        if ($options['point_size'] <= 0 || ! in_array($options['target'], ['tp1', 'tp2'], true) || ! is_bool($options['position_aware'])) {
            throw new \InvalidArgumentException('Declare positive point size, target tp1/tp2 and boolean position_aware.');
        }
        if (($prepared['diagnostics']['input_sha256'] ?? null) !== $this->fingerprint($data)) {
            throw new \InvalidArgumentException('Prepared RSI snapshots do not belong to this exact input data.');
        }
        foreach (['entries', 'close_long', 'close_short'] as $field) {
            if (! is_array($prepared[$field] ?? null)) {
                throw new \InvalidArgumentException('Prepared RSI snapshot maps are missing.');
            }
            $canonical = [];
            foreach ($prepared[$field] as $at => $signal) {
                $decisionAt = $this->time($at);
                if (! is_array($signal) || $decisionAt % 300 !== 0 || $decisionAt !== $this->time($signal['timestamp'] ?? null) + 300
                    || ($field === 'entries' ? ! in_array($signal['action'] ?? null, ['BUY', 'SELL'], true) : ($signal['action'] ?? null) !== 'CLOSE')) {
                    throw new \InvalidArgumentException('A prepared decision must follow its completed M5 candle and have the expected action.');
                }
                $key = $this->iso($decisionAt);
                if (isset($canonical[$key])) {
                    throw new \InvalidArgumentException('Prepared decision timestamps must be unique after UTC normalization.');
                }
                $canonical[$key] = $signal;
            }
            $prepared[$field] = $canonical;
        }
        $point = (float) $options['point_size'];
        $slippage = (float) $options['slippage'];
        $fee = (float) $options['fee'];
        $spreadMultiplier = (float) $options['spread_multiplier'];
        $start = ($options['from'] ?? null) === null ? $minutes[0]['time'] : $this->time($options['from']);
        $end = ($options['to'] ?? null) === null ? $minutes[array_key_last($minutes)]['time'] + 60 : $this->time($options['to']);
        if ($start >= $end) {
            throw new \InvalidArgumentException('Evaluation start must precede the exclusive end.');
        }
        $entries = array_filter($prepared['entries'], fn ($s, $at) => $this->time($at) >= $start && $this->time($at) < $end, ARRAY_FILTER_USE_BOTH);
        $reject = array_fill_keys(['existing_position', 'market_closed', 'invalid_plan', 'quote_drift', 'quote_bracket', 'fill_bracket', 'no_immediate_next_open'], 0);
        $processed = $trades = [];
        $position = null;
        $previous = null;
        $visited = $gaps = $ambiguous = $manualClosed = $manualBlocked = $manualOverridden = 0;
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
            $key = $this->iso($time);
            $spread = $bar['spread_points'] * $point * $spreadMultiplier;
            $marketOpen = $this->guard->marketStatus('XAUUSD', Carbon::createFromTimestampUTC($time))['open'];
            $hadPosition = $position !== null;
            if (isset($entries[$key])) {
                $processed[$key] = true;
                if ($hadPosition) {
                    $reject['existing_position']++;
                } elseif (! $marketOpen) {
                    $reject['market_closed']++;
                } else {
                    $signal = $entries[$key];
                    $plan = $signal['trade_plan'] ?? [];
                    $targetKey = $options['target'] === 'tp1' ? 'take_profit_1' : 'take_profit_2';
                    $valid = true;
                    foreach (array_unique(['entry_price', 'stop_loss', 'take_profit_1', $targetKey]) as $field) {
                        $valid = $valid && isset($plan[$field]) && is_numeric($plan[$field]) && is_finite((float) $plan[$field]) && $plan[$field] > 0;
                    }
                    if (! $valid) {
                        $reject['invalid_plan']++;
                    } else {
                        $side = $signal['action'] === 'BUY' ? 1 : -1;
                        $signalRisk = ($plan['entry_price'] - $plan['stop_loss']) * $side;
                        $quote = $bar['open'] + ($side === 1 ? $spread : 0);
                        $fill = $quote + $side * $slippage;
                        $risk = ($fill - $plan['stop_loss']) * $side;
                        if ($signalRisk <= 0 || ($quote - $plan['stop_loss']) * $side <= 0 || ($plan['take_profit_1'] - $quote) * $side <= 0) {
                            $reject['quote_bracket']++;
                        } elseif (abs($quote - $plan['entry_price']) > 0.1 * $signalRisk) {
                            $reject['quote_drift']++;
                        } elseif ($risk <= 0 || ($plan[$targetKey] - $fill) * $side <= 0) {
                            $reject['fill_bracket']++;
                        } else {
                            $position = ['side' => $side, 'opened_at' => $key, 'decision_at' => $key,
                                'signal_timestamp_open' => $signal['timestamp'], 'signal_entry_price' => (float) $plan['entry_price'],
                                'signal_risk' => $signalRisk, 'entry_quote_before_slippage' => $quote,
                                'entry_quote_drift_r' => abs($quote - $plan['entry_price']) / $signalRisk,
                                'entry_spread_proxy' => $spread, 'entry' => $fill, 'sl' => (float) $plan['stop_loss'],
                                'tp' => (float) $plan[$targetKey], 'target_policy' => $options['target'], 'risk' => $risk,
                                'crossed_nonconsecutive_minutes' => false];
                        }
                    }
                }
            }
            if ($position === null) {
                continue;
            }
            $p = $position;
            $offset = $p['side'] === -1 ? $spread : 0;
            $open = $bar['open'] + $offset;
            $gapStop = ($open - $p['sl']) * $p['side'] <= 0;
            $gapTarget = ($open - $p['tp']) * $p['side'] >= 0;
            $closeMap = $p['side'] === 1 ? $prepared['close_long'] : $prepared['close_short'];
            $closeRequested = $hadPosition && $options['position_aware'] && isset($closeMap[$key]);
            if ($gapStop || $gapTarget) {
                $trades[] = $this->closeTrade($p, $gapStop ? $open : $p['tp'], $key, $time, $gapStop ? 'gap_stop' : 'gap_target', false, $slippage, $fee);
                $manualOverridden += (int) $closeRequested;
                $position = null;

                continue;
            }
            if ($closeRequested && $marketOpen) {
                $trades[] = $this->closeTrade($p, $open, $key, $time, 'strategy_close', false, $slippage, $fee);
                $manualClosed++;
                $position = null;

                continue;
            }
            $manualBlocked += (int) ($closeRequested && ! $marketOpen);
            $high = $bar['high'] + $offset;
            $low = $bar['low'] + $offset;
            $stop = $p['side'] === 1 ? $low <= $p['sl'] : $high >= $p['sl'];
            $target = $p['side'] === 1 ? $high >= $p['tp'] : $low <= $p['tp'];
            if ($stop || $target) {
                $both = $stop && $target;
                $ambiguous += (int) $both;
                $trades[] = $this->closeTrade($p, $stop ? $p['sl'] : $p['tp'], $key, $time, $both ? 'both_hit_stop_first' : ($stop ? 'stop_loss' : 'take_profit'), $both, $slippage, $fee);
                $position = null;
            }
        }
        if (! $visited) {
            throw new \InvalidArgumentException('No M1 bars exist in the evaluation range.');
        }
        $reject['no_immediate_next_open'] = count(array_diff_key($entries, $processed));

        return ['strategy' => self::SLUG, 'evaluator' => 'TradingService::evaluateRsiMovingAverage unchanged defaults',
            'simulation' => 'conditional one-position M1 diagnostic of current RSI rules; not executed account P/L',
            'timestamp_assumption' => $data['timestamp_assumption'] ?? 'not supplied', 'symbol' => $data['symbol'],
            'from_inclusive' => $this->iso($start), 'to_exclusive' => $this->iso($end),
            'target_policy' => $options['target'], 'position_aware' => $options['position_aware'],
            'cost_assumptions' => ['point_size_unverified' => $point, 'reported_minute_minimum_spread_multiplier' => $spreadMultiplier,
                'slippage_usd_per_ounce_each_fill' => $slippage, 'round_trip_fee_usd_per_ounce' => $fee],
            'signals' => count($entries), 'entry_rejections' => $reject, 'prepared_diagnostics' => $prepared['diagnostics'],
            'metrics' => $this->metrics($trades),
            'contiguous_path_metrics' => $this->metrics(array_values(array_filter($trades, fn ($t) => ! $t['crossed_nonconsecutive_minutes']))),
            'trades_crossing_nonconsecutive_minutes' => count(array_filter($trades, fn ($t) => $t['crossed_nonconsecutive_minutes'])),
            'nonconsecutive_intervals_in_evaluation' => $gaps, 'ambiguous_minutes_stop_first' => $ambiguous,
            'strategy_close_exits' => $manualClosed, 'strategy_closes_blocked_market_closed' => $manualBlocked,
            'strategy_closes_superseded_by_opening_bracket' => $manualOverridden,
            'open_position_at_end' => $position, 'trades' => $trades,
            'limitations' => [
                'Fixed full TP1/SL is the primary no-position-context email policy; TP2 and position-aware CLOSE are explicit alternatives.',
                'Every entry follows 200 completed active-session M5 bars. The underlying evaluator itself only requires 23 bars.',
                'M1 spread is a minute minimum proxy, not actual entry spread or intraminute ask extrema. Point size, clock, fees and slippage are assumptions.',
                'Gaps are not reconstructed. All-trade metrics assume no unseen execution; contiguous metrics describe only a subset of the same simulated portfolio.',
                'Only new entries and voluntary CLOSE actions require the regular market session. Protective exits use all available M1 quotes; UTC clock sensitivity can therefore contain quotes inside a nominal closure.',
                'No same-minute reversal after any exit; no RSI-specific daily cap, cooldown, minimum reward/risk or spread cap is invented.',
                'No historical news, swaps, margin, volume rounding, equity sizing, execution latency or true untouched holdout is modeled.',
            ]];
    }

    private function minutes(array $data): array
    {
        if (($data['source'] ?? null) !== 'mt5' || ! is_string($data['symbol'] ?? null)
            || ! preg_match('/^XAU[\/_-]?USD(?:[._#-]?[A-Za-z0-9]+)?$/i', $data['symbol'])
            || ! is_array($data['m1'] ?? null) || ! $data['m1'] || ! is_array($data['m5'] ?? null)) {
            throw new \InvalidArgumentException('An MT5 XAUUSD input with explicit UTC m1 and m5 arrays is required.');
        }

        return $this->bars($data['m1'], 60, true);
    }

    private function bars(array $bars, int $seconds, bool $withSpread): array
    {
        $result = [];
        $previous = null;
        foreach ($bars as $bar) {
            $at = $this->time($bar['timestamp'] ?? null);
            if ($at % $seconds !== 0 || ($previous !== null && $at <= $previous)) {
                throw new \InvalidArgumentException('Bar opening times must be aligned, ascending and unique.');
            }
            foreach ($withSpread ? ['open', 'high', 'low', 'close', 'spread_points'] : ['open', 'high', 'low', 'close'] as $field) {
                if (! isset($bar[$field]) || ! is_numeric($bar[$field]) || ! is_finite((float) $bar[$field]) || $bar[$field] < 0 || ($field !== 'spread_points' && $bar[$field] == 0)) {
                    throw new \InvalidArgumentException('OHLC and spread values must be finite and valid.');
                }
                $bar[$field] = (float) $bar[$field];
            }
            if ($bar['high'] < max($bar['open'], $bar['close'], $bar['low']) || $bar['low'] > min($bar['open'], $bar['close'])) {
                throw new \InvalidArgumentException('Inconsistent OHLC bounds.');
            }
            $bar['timestamp'] = $this->iso($at);
            $bar['time'] = $at;
            $result[] = $bar;
            $previous = $at;
        }

        return $result;
    }

    private function time(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00(?:Z|\+00:00)$/D', $value)) {
            throw new \InvalidArgumentException('Expected an explicit UTC ISO minute timestamp.');
        }
        $canonical = str_replace('Z', '+00:00', $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $canonical);
        $errors = \DateTimeImmutable::getLastErrors();
        if (! $date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d\TH:i:sP') !== $canonical) {
            throw new \InvalidArgumentException('Invalid UTC calendar date.');
        }

        return $date->getTimestamp();
    }

    private function fingerprint(array $data): string
    {
        return hash('sha256', json_encode([$data['source'] ?? null, $data['symbol'] ?? null, $data['m1'] ?? null, $data['m5'] ?? null], JSON_THROW_ON_ERROR));
    }

    private function iso(int $time): string
    {
        return gmdate('Y-m-d\TH:i:s', $time).'+00:00';
    }

    private function closeTrade(array $p, float $price, string $key, int $time, string $reason, bool $both, float $slippage, float $fee): array
    {
        $exit = $price - $p['side'] * $slippage;
        $net = (($exit - $p['entry']) * $p['side'] - $fee) / $p['risk'];

        return $p + ['exit_minute_open' => $key, 'exit_minute_close' => $this->iso($time + 60), 'exit_reason' => $reason,
            'both_hit_minute' => $both, 'exit' => $exit, 'net_r' => $net, 'outcome' => $net > 0 ? 'win' : ($net < 0 ? 'loss' : 'breakeven')];
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
            'breakeven' => count($trades) - count($wins) - count($losses), 'win_rate' => $trades ? count($wins) / count($trades) : null,
            'net_r' => $total, 'expectancy_r' => $trades ? $total / count($trades) : null,
            'profit_factor_r' => $grossLoss > 0 ? array_sum($wins) / $grossLoss : null, 'max_closed_trade_drawdown_r' => $drawdown];
    }
}
