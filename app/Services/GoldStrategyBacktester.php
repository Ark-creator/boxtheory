<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/** Bar-based research diagnostic, deliberately separate from broker execution. */
class GoldStrategyBacktester
{
    public function __construct(private readonly GoldBreakoutStrategy $strategy = new GoldBreakoutStrategy) {}

    public function run(array $data, float $spread, float $slippage, float $feePerUnit): array
    {
        foreach ([$spread, $slippage, $feePerUnit] as $cost) {
            if (! is_finite($cost) || $cost < 0) {
                throw new \InvalidArgumentException('Costs must be finite and nonnegative.');
            }
        }
        if (($data['source'] ?? '') !== 'mt5' || empty($data['m15']) || empty($data['h1'])) {
            throw new \InvalidArgumentException('An MT5 export with m15 and h1 candles is required.');
        }
        foreach (['m15', 'h1'] as $frame) {
            $previous = null;
            foreach ($data[$frame] as $bar) {
                $time = Carbon::parse($bar['timestamp'], 'UTC')->utc();
                if ($previous && $time->lte($previous)) {
                    throw new \InvalidArgumentException('Export timestamps must be strictly ascending.');
                }
                foreach (['open', 'high', 'low', 'close'] as $key) {
                    if (! isset($bar[$key]) || ! is_numeric($bar[$key]) || ! is_finite((float) $bar[$key]) || $bar[$key] <= 0) {
                        throw new \InvalidArgumentException('Export contains invalid OHLC prices.');
                    }
                }
                if ($bar['high'] < max($bar['low'], $bar['open'], $bar['close']) || $bar['low'] > min($bar['open'], $bar['close'])) {
                    throw new \InvalidArgumentException('Export contains inconsistent OHLC prices.');
                }
                $previous = $time;
            }
        }
        $engine = $this->strategy;
        $hours = $data['h1'];
        $hourIndex = 0;
        $position = null;
        $trades = [];
        $signals = 0;
        $ambiguous = 0;
        $extensionRejections = 0;
        $m15 = $data['m15'];
        for ($i = 50; $i < count($m15); $i++) {
            $bar = $m15[$i];
            $at = Carbon::parse($bar['timestamp'], 'UTC')->utc();
            while ($hourIndex < count($hours) && Carbon::parse($hours[$hourIndex]['timestamp'], 'UTC')->addHour()->lte($at)) {
                $hourIndex++;
            }
            if ($position === null) {
                $signal = $engine->evaluate(array_slice($m15, max(0, $i - 300), min($i, 300)), array_slice($hours, max(0, $hourIndex - 300), min($hourIndex, 300)), $at);
                if (in_array($signal['action'], ['BUY', 'SELL'], true)) {
                    $signals++;
                    $plan = $signal['trade_plan'];
                    $side = $signal['action'] === 'BUY' ? 1 : -1;
                    $entry = $bar['open'] + ($side === 1 ? $spread : 0) + $side * $slippage;
                    if ($side === 1 ? $entry > $plan['entry_limit_price'] : $entry < $plan['entry_limit_price']) {
                        $extensionRejections++;

                        continue;
                    }
                    $risk = abs($entry - $plan['stop_loss']);
                    if ($risk > 0 && abs($entry - $plan['entry_price']) <= $plan['risk_per_unit'] * 0.1 && $spread <= min(1, $risk * 0.1) && ($plan['take_profit_1'] - $entry) * $side / $risk >= 1.8) {
                        $position = ['side' => $side, 'entry' => $entry, 'sl' => $plan['stop_loss'], 'tp' => $plan['take_profit_1'], 'risk' => $risk, 'opened_at' => $at->toIso8601String()];
                    }
                }
            }
            if ($position === null) {
                continue;
            }
            $p = $position;
            // MT5 FX/CFD bars are bid bars. Short exits buy at ask.
            $offset = $p['side'] === -1 ? $spread : 0;
            $open = $bar['open'] + $offset;
            $high = $bar['high'] + $offset;
            $low = $bar['low'] + $offset;
            $stop = $p['side'] === 1 ? $low <= $p['sl'] : $high >= $p['sl'];
            $target = $p['side'] === 1 ? $high >= $p['tp'] : $low <= $p['tp'];
            $gapStop = ($open - $p['sl']) * $p['side'] <= 0;
            $gapTarget = ($open - $p['tp']) * $p['side'] >= 0;
            if (! $stop && ! $target) {
                continue;
            }
            $ambiguous += $stop && $target && ! $gapStop && ! $gapTarget ? 1 : 0;
            // Gaps use the opening quote; otherwise both-hit bars resolve stop-first.
            $exit = $gapStop ? $open : ($gapTarget ? $p['tp'] : ($stop ? $p['sl'] : $p['tp']));
            $exit -= $p['side'] * $slippage;
            $trades[] = $p + ['closed_at' => $at->copy()->addMinutes(15)->toIso8601String(), 'exit' => $exit, 'net_r' => (($exit - $p['entry']) * $p['side'] - $feePerUnit) / $p['risk']];
            $position = null;
        }
        $returns = array_column($trades, 'net_r');
        $wins = array_filter($returns, fn ($r) => $r > 0);
        $loss = -array_sum(array_filter($returns, fn ($r) => $r < 0));
        $total = $peak = $drawdown = 0;
        $years = [];
        foreach ($trades as $trade) {
            $total += $trade['net_r'];
            $peak = max($peak, $total);
            $drawdown = max($drawdown, $peak - $total);
            $year = substr($trade['closed_at'], 0, 4);
            $years[$year] = ($years[$year] ?? 0) + $trade['net_r'];
        }

        return [
            'strategy' => GoldBreakoutStrategy::SLUG, 'simulation' => 'bar-based signal diagnostic',
            'parameters' => ['max_entry_extension_atr' => $engine->maxEntryExtensionAtr],
            'entry_extension_rejections' => $extensionRejections,
            'limitations' => 'No historical news, swaps, variable spreads, lot rounding, margin, execution latency or account loss/cooldown gates. Not an execution backtest or proof of profitability. Open positions are excluded and reported separately.',
            'costs' => compact('spread', 'slippage', 'feePerUnit'), 'signals' => $signals, 'closed_trades' => count($trades),
            'win_rate' => $trades ? count($wins) / count($trades) : null,
            'expectancy_r' => $trades ? array_sum($returns) / count($trades) : null,
            'profit_factor' => $loss > 0 ? array_sum($wins) / $loss : null,
            'net_r' => $total, 'max_closed_trade_drawdown_r' => $drawdown, 'annual_net_r' => $years,
            'ambiguous_bars_stop_first' => $ambiguous, 'open_position_at_end' => $position, 'trades' => $trades,
        ];
    }
}
