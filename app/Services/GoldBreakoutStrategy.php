<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/** A testable hypothesis, not a validated return forecast. All timestamps are UTC bar opens. */
class GoldBreakoutStrategy
{
    public const SLUG = 'gold-risk-breakout';

    public function __construct(public readonly float $maxEntryExtensionAtr = 0.75)
    {
        if (! is_finite($maxEntryExtensionAtr) || $maxEntryExtensionAtr <= 0) {
            throw new \InvalidArgumentException('Maximum entry extension must be a positive finite ATR multiple.');
        }
    }

    public function evaluate(array $m15, array $h1, Carbon $asOf, ?string $position = null, string $dataSource = 'VT Markets MT5'): array
    {
        $signal = [
            'strategy' => self::SLUG, 'action' => 'NO_DATA', 'price' => 0,
            'timestamp' => null, 'trend' => 'unknown', 'position' => $position ?? 'flat',
            'message' => 'Insufficient closed market candles.', 'trade_plan' => [],
            'indicators' => ['data_source' => $dataSource], 'error' => null,
        ];
        try {
            $m15 = $this->closedBars($m15, 900, $asOf);
            $h1 = $this->closedBars($h1, 3600, $asOf);
        } catch (\Throwable $e) {
            return array_replace($signal, ['message' => $e->getMessage(), 'error' => $e->getMessage()]);
        }
        if (count($m15) < 50 || count($h1) < 220) {
            return $signal;
        }
        $last = $m15[array_key_last($m15)];
        $closedAt = Carbon::parse($last['timestamp'])->utc()->addMinutes(15);
        $hourClosedAt = Carbon::parse($h1[array_key_last($h1)]['timestamp'])->utc()->addHour();
        if ($closedAt->lte($asOf->copy()->subMinutes(3)) || $hourClosedAt->lt($asOf->copy()->subHour())) {
            return array_replace($signal, ['message' => 'Market candles are stale; wait for a fresh M15 close.']);
        }
        $closes = array_column($h1, 'close');
        $fast = $this->ema($closes, 50);
        $slow = $this->ema($closes, 200);
        $priorFast = $this->ema(array_slice($closes, 0, -3), 50);
        $hourClose = (float) end($closes);
        $up = $hourClose > $fast && $fast > $slow && $fast > $priorFast;
        $down = $hourClose < $fast && $fast < $slow && $fast < $priorFast;
        $ranges = [];
        for ($i = 1; $i < count($m15); $i++) {
            $ranges[] = max($m15[$i]['high'] - $m15[$i]['low'], abs($m15[$i]['high'] - $m15[$i - 1]['close']), abs($m15[$i]['low'] - $m15[$i - 1]['close']));
        }
        // Freeze the entry-cap reference before the signal candle can inflate it.
        $signalRange = array_pop($ranges);
        $priorAtr = array_sum(array_slice($ranges, 0, 14)) / 14;
        foreach (array_slice($ranges, 14) as $range) {
            $priorAtr = ($priorAtr * 13 + $range) / 14;
        }
        // Preserve the existing ATR for breakout minimum, volatility and SL/TP.
        $atr = ($priorAtr * 13 + $signalRange) / 14;
        $channel = array_slice($m15, -21, 20); // Excludes the signal candle.
        $high = max(array_column($channel, 'high'));
        $low = min(array_column($channel, 'low'));
        $price = (float) $last['close'];
        $signal = array_replace($signal, [
            'action' => 'WAIT', 'price' => $price, 'timestamp' => $closedAt->toIso8601String(),
            'trend' => $up ? 'bullish' : ($down ? 'bearish' : 'flat'),
            'message' => 'Wait for an H1 trend and a closed M15 channel breakout.',
            'indicators' => ['data_source' => $dataSource, 'ema_fast' => $fast, 'ema_slow' => $slow, 'atr' => $atr, 'atr_prior' => $priorAtr, 'max_entry_extension_atr' => $this->maxEntryExtensionAtr, 'channel_high' => $high, 'channel_low' => $low],
            'trade_plan' => ['side' => 'wait', 'buy_trigger_above' => $high, 'sell_trigger_below' => $low],
        ]);
        if (in_array($position, ['long', 'short'], true)) {
            $signal['action'] = 'HOLD';
            $signal['message'] = 'Manage the existing position with its broker stop loss and full 2R target.';

            return $signal;
        }
        $utc = $asOf->copy()->utc();
        if ($utc->isWeekend() || $utc->hour < 7 || $utc->hour >= 17) {
            $signal['message'] = 'New entries are limited to 07:00–17:00 UTC weekdays.';

            return $signal;
        }
        if ($atr <= 0 || $atr / $price < 0.0005 || $atr / $price > 0.008 || 2.5 * $atr < $last['high'] - $last['low']) {
            $signal['message'] = 'Volatility or signal-candle size is outside the entry limits.';

            return $signal;
        }
        $buy = $up && $price > $high + 0.1 * $atr && $last['close'] > $last['open'];
        $sell = $down && $price < $low - 0.1 * $atr && $last['close'] < $last['open'];
        if (! $buy && ! $sell) {
            return $signal;
        }
        $entryLimit = $buy ? $high + $this->maxEntryExtensionAtr * $priorAtr : $low - $this->maxEntryExtensionAtr * $priorAtr;
        $signal['indicators']['entry_limit_price'] = $entryLimit;
        if ($priorAtr <= 0 || ! is_finite($entryLimit) || $entryLimit <= 0 || ($buy ? $price > $entryLimit : $price < $entryLimit)) {
            $signal['message'] = sprintf('Breakout entry extension exceeds %.4g ATR measured before the signal candle; skip this entry.', $this->maxEntryExtensionAtr);

            return $signal;
        }
        $swing = array_slice($m15, -5);
        $distance = max(1.5 * $atr, $buy ? $price - min(array_column($swing, 'low')) + 0.2 * $atr : max(array_column($swing, 'high')) - $price + 0.2 * $atr);
        if ($distance > 3 * $atr) {
            $signal['message'] = 'Structural stop exceeds 3 ATR; skip this breakout.';

            return $signal;
        }
        $direction = $buy ? 1 : -1;
        $signal['action'] = $buy ? 'BUY' : 'SELL';
        $signal['message'] = 'Closed M15 breakout aligned with the H1 trend. Entry remains subject to broker risk checks.';
        $signal['expires_at'] = $closedAt->copy()->addMinutes(3)->toIso8601String();
        $signal['trade_plan'] = [
            'side' => $buy ? 'buy' : 'sell', 'entry_price' => round($price, 5),
            'stop_loss' => round($price - $direction * $distance, 5),
            'take_profit_1' => round($price + $direction * 2 * $distance, 5),
            'take_profit_2' => null, 'risk_per_unit' => round($distance, 5), 'rr_tp1' => 2.0, 'rr_tp2' => null,
            'entry_limit_price' => $entryLimit,
            'notes' => sprintf('Full exit at 2R or the initial stop; no averaging down. Suggested risk 0.25%% of equity. Maximum entry extension: %.4g prior-bar ATR (%s %.5f). Execution can be rejected by spread, news, or account limits.', $this->maxEntryExtensionAtr, $buy ? 'buy at or below' : 'sell at or above', $entryLimit),
        ];

        return $signal;
    }

    private function closedBars(array $bars, int $seconds, Carbon $asOf): array
    {
        $result = [];
        $previous = null;
        foreach ($bars as $bar) {
            $time = Carbon::parse($bar['timestamp'], 'UTC')->utc();
            if ($previous !== null && $time->lte($previous)) {
                throw new \InvalidArgumentException('Candles must have unique ascending timestamps.');
            }
            $previous = $time;
            foreach (['open', 'high', 'low', 'close'] as $key) {
                if (! isset($bar[$key]) || ! is_numeric($bar[$key]) || ! is_finite((float) $bar[$key]) || $bar[$key] <= 0) {
                    throw new \InvalidArgumentException('Invalid broker OHLC data.');
                }
            }
            if ($bar['high'] < max($bar['open'], $bar['close'], $bar['low']) || $bar['low'] > min($bar['open'], $bar['close'])) {
                throw new \InvalidArgumentException('Inconsistent broker OHLC data.');
            }
            if ($time->copy()->addSeconds($seconds)->lte($asOf)) {
                $result[] = $bar;
            }
        }

        return $result;
    }

    private function ema(array $values, int $period): float
    {
        $ema = array_sum(array_slice($values, 0, $period)) / $period;
        foreach (array_slice($values, $period) as $value) {
            $ema += 2 / ($period + 1) * ($value - $ema);
        }

        return $ema;
    }
}
