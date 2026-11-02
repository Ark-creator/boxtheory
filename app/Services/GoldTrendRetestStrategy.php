<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/** Research hypothesis only. OHLC timestamps are UTC bar opens; no broker execution. */
class GoldTrendRetestStrategy
{
    public const SLUG = 'gold-trend-retest';

    public function evaluate(array $m15, array $h1, Carbon $asOf, ?string $position = null, string $dataSource = 'VT Markets MT5'): array
    {
        $empty = $this->emptySignal($dataSource);
        try {
            $m15 = $this->bars($m15, 900, $asOf->getTimestamp());
            $h1 = $this->bars($h1, 3600, $asOf->getTimestamp());
            if (count($m15) < 50 || count($h1) < 220) {
                return $empty;
            }
            $closedAt = $m15[array_key_last($m15)]['time'] + 900;
            $hourClosedAt = $h1[array_key_last($h1)]['time'] + 3600;
            if ($closedAt <= $asOf->getTimestamp() - 180 || $hourClosedAt < $asOf->getTimestamp() - 3600) {
                return array_replace($empty, ['message' => 'Market candles are stale; wait for a fresh M15 close.']);
            }
            if (in_array($position, ['long', 'short'], true)) {
                return array_replace($empty, [
                    'action' => 'HOLD', 'position' => $position,
                    'price' => $m15[array_key_last($m15)]['close'], 'timestamp' => $this->iso($closedAt),
                    'message' => 'Manage the existing position with its original stop and full 2R target.',
                ]);
            }

            return $this->scan($m15, $h1, $dataSource)['latest'];
        } catch (\Throwable $exception) {
            return array_replace($empty, ['message' => $exception->getMessage(), 'error' => $exception->getMessage()]);
        }
    }

    /**
     * Sparse map of actionable snapshots keyed by M15 CLOSE timestamp (ISO 8601).
     * Each snapshot uses only candles closed at that time. Missing key means no entry.
     * The same episode scan powers evaluate(); this path is linear in supplied history.
     */
    public function evaluateHistory(array $m15, array $h1, string $dataSource = 'VT Markets MT5'): array
    {
        return $this->scan($this->bars($m15, 900), $this->bars($h1, 3600), $dataSource)['signals'];
    }

    protected function scan(array $m15, array $h1, string $source): array
    {
        $signals = [];
        $latest = $this->emptySignal($source);
        $atrs = $this->atrs($m15);
        $trends = $this->trends($h1);
        $hour = -1;
        $episode = null;
        $lastIndex = count($m15) - 1;

        for ($i = 49; $i <= $lastIndex; $i++) {
            $bar = $m15[$i];
            $at = $bar['time'] + 900;
            while (isset($h1[$hour + 1]) && $h1[$hour + 1]['time'] + 3600 <= $at) {
                $hour++;
            }
            $trend = $hour >= 219 && $h1[$hour]['time'] + 7200 >= $at ? $trends[$hour] : 0;
            $atr = $atrs[$i - 1] ?? 0.0;
            $row = array_replace($this->emptySignal($source), [
                'action' => 'WAIT', 'price' => $bar['close'], 'timestamp' => $this->iso($at),
                'trend' => $trend === 1 ? 'bullish' : ($trend === -1 ? 'bearish' : 'flat'),
                'message' => 'Wait for an H1-aligned breakout and its first closed M15 retest.',
                'indicators' => ['data_source' => $source, 'atr_prior' => $atr],
            ]);

            if ($episode !== null) {
                $row['setup_id'] = $episode['id'];
                $row['indicators'] += ['breakout_boundary' => $episode['boundary'], 'breakout_atr' => $episode['atr']];
                $side = $episode['side'];
                $inside = ($bar['close'] - $episode['boundary']) * $side;
                $continuous = $bar['time'] - $m15[$i - 1]['time'] === 900;
                if ($episode['status'] === 'waiting' && (! $continuous || $at - $episode['closed_at'] > 3600)) {
                    $episode['status'] = 'expired';
                }
                if (($episode['status'] === 'waiting' && $inside < -0.25 * $episode['atr'])
                    || ($episode['status'] !== 'waiting' && $inside <= 0)) {
                    // Rearm only after returning inside the original frozen channel.
                    $episode = null;
                    $row['message'] = 'Previous breakout invalidated or rearmed after a close inside its frozen boundary.';
                } elseif ($episode['status'] !== 'waiting') {
                    $row['message'] = 'This breakout is consumed or expired; wait for price to return inside its frozen boundary.';
                } else {
                    $episode['swing'] = $side === 1 ? min($episode['swing'], $bar['low']) : max($episode['swing'], $bar['high']);
                    $touch = $side === 1 ? $bar['low'] <= $episode['boundary'] + 0.1 * $episode['atr']
                        : $bar['high'] >= $episode['boundary'] - 0.1 * $episode['atr'];
                    $directional = ($bar['close'] - $bar['open']) * $side > 0;
                    if ($touch && $inside > 0 && $directional && $trend === $side && $this->session($at)) {
                        // Consume even a rejected first retest. Later candles cannot replace it.
                        $episode['status'] = 'consumed';
                        $row = $this->retest($row, $bar, $episode, $at);
                        if (in_array($row['action'], ['BUY', 'SELL'], true)) {
                            $signals[$row['timestamp']] = $row;
                        }
                    }
                }
            } elseif ($trend !== 0 && $atr > 0 && $this->session($at) && $this->continuous($m15, $i - 20, $i)) {
                $prior = array_slice($m15, $i - 20, 20);
                $boundary = $trend === 1 ? max(array_column($prior, 'high')) : min(array_column($prior, 'low'));
                $extension = ($bar['close'] - $boundary) * $trend;
                if ($extension > 0.1 * $atr && ($bar['close'] - $bar['open']) * $trend > 0
                    && 2.5 * $atr >= $bar['high'] - $bar['low'] && $atr / $bar['close'] >= 0.0005 && $atr / $bar['close'] <= 0.008) {
                    $episode = [
                        'id' => hash('sha256', implode('|', [static::SLUG, $source, $bar['time'], $trend, sprintf('%.8F', $boundary)])),
                        'side' => $trend, 'boundary' => $boundary, 'atr' => $atr, 'closed_at' => $at,
                        'swing' => $trend === 1 ? $bar['low'] : $bar['high'], 'status' => 'waiting',
                    ];
                    $row['setup_id'] = $episode['id'];
                    $row['message'] = 'Breakout detected. Wait 1–4 completed M15 bars for the first retest; no immediate entry.';
                    $row['indicators'] += ['breakout_boundary' => $boundary, 'breakout_atr' => $atr];
                }
            }
            $latest = $row;
        }

        return compact('signals', 'latest');
    }

    protected function retest(array $signal, array $bar, array $episode, int $at): array
    {
        $side = $episode['side'];
        $atr = $episode['atr'];
        $price = $bar['close'];
        $extension = ($price - $episode['boundary']) * $side;
        $distance = max(1.5 * $atr, ($price - $episode['swing']) * $side + 0.2 * $atr);
        if ($extension > 0.5 * $atr || 2.5 * $atr < $bar['high'] - $bar['low'] || $distance > 3 * $atr) {
            return array_replace($signal, ['message' => 'First retest rejected: entry extension, candle range, or structural stop exceeds its frozen ATR limit.']);
        }
        $limit = $episode['boundary'] + $side * 0.5 * $atr;
        $minimum = max($price - 0.1 * $distance, $side === 1 ? $episode['boundary'] : $limit);
        $maximum = min($price + 0.1 * $distance, $side === 1 ? $limit : $episode['boundary']);

        return array_replace($signal, [
            'action' => $side === 1 ? 'BUY' : 'SELL',
            'message' => 'First closed M15 breakout retest aligned with the closed H1 trend. Research candidate; execution checks still apply.',
            'setup_id' => $episode['id'], 'expires_at' => $this->iso($at + 180),
            'trade_plan' => [
                'side' => $side === 1 ? 'buy' : 'sell', 'entry_price' => round($price, 5),
                'stop_loss' => round($price - $side * $distance, 5),
                'take_profit_1' => round($price + $side * 2 * $distance, 5), 'take_profit_2' => null,
                'risk_per_unit' => round($distance, 5), 'rr_tp1' => 2.0, 'rr_tp2' => null,
                'entry_min_price' => $minimum, 'entry_max_price' => $maximum,
                'setup_id' => $episode['id'],
                'notes' => 'Research only. Full exit at initial SL or 2R. No averaging down, trailing stop or time exit. Bounds combine 0.5 frozen ATR extension and 0.1R entry drift. One attempt per setup; do not retry a rejected fill. Broker spread, news, position sizing and account limits require separate checks.',
            ],
        ]);
    }

    private function bars(array $bars, int $seconds, ?int $asOf = null): array
    {
        $result = [];
        $previous = null;
        foreach ($bars as $bar) {
            if (! is_string($bar['timestamp'] ?? null) || trim($bar['timestamp']) === '') {
                throw new \InvalidArgumentException('Each candle needs a UTC opening timestamp.');
            }
            $time = $this->openingTimestamp($bar['timestamp']);
            if ($time % $seconds !== 0 || ($previous !== null && $time <= $previous)) {
                throw new \InvalidArgumentException('Candle timestamps must be aligned to their timeframe, unique and ascending.');
            }
            $previous = $time;
            if ($asOf !== null && $time + $seconds > $asOf) {
                continue;
            }
            foreach (['open', 'high', 'low', 'close'] as $key) {
                if (! isset($bar[$key]) || ! is_numeric($bar[$key]) || ! is_finite((float) $bar[$key]) || $bar[$key] <= 0) {
                    throw new \InvalidArgumentException('Candles require positive finite OHLC prices.');
                }
                $bar[$key] = (float) $bar[$key];
            }
            if ($bar['high'] < max($bar['open'], $bar['close'], $bar['low']) || $bar['low'] > min($bar['open'], $bar['close'])) {
                throw new \InvalidArgumentException('Inconsistent candle OHLC prices.');
            }
            $bar['time'] = $time;
            $result[] = $bar;
        }

        return $result;
    }

    private function openingTimestamp(string $timestamp): int
    {
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(Z|[+-]\d{2}:\d{2})?$/D', $timestamp, $parts)) {
            throw new \InvalidArgumentException('Candle timestamps must be ISO calendar dates with opening hours, minutes and seconds.');
        }
        $offset = $parts[3] ?? '+00:00';
        if ($offset === 'Z') {
            $offset = '+00:00';
        } elseif ((int) substr($offset, 1, 2) > 23 || (int) substr($offset, 4, 2) > 59) {
            throw new \InvalidArgumentException('Candle timestamp contains an invalid UTC offset.');
        }
        $canonical = $parts[1].'T'.$parts[2].$offset;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $canonical, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i:sP') !== $canonical) {
            throw new \InvalidArgumentException('Candle timestamp contains an invalid calendar date or time.');
        }

        return $date->getTimestamp();
    }

    protected function atrs(array $bars): array
    {
        $ranges = [];
        $result = [];
        for ($i = 1; $i < count($bars); $i++) {
            $ranges[] = max($bars[$i]['high'] - $bars[$i]['low'], abs($bars[$i]['high'] - $bars[$i - 1]['close']), abs($bars[$i]['low'] - $bars[$i - 1]['close']));
            if ($i === 14) {
                $result[$i] = array_sum($ranges) / 14;
            } elseif ($i > 14) {
                $result[$i] = ($result[$i - 1] * 13 + $ranges[$i - 1]) / 14;
            }
        }

        return $result;
    }

    protected function trends(array $hours): array
    {
        $closes = array_column($hours, 'close');
        $fast = $this->emas($closes, 50);
        $slow = $this->emas($closes, 200);
        $result = [];
        foreach ($closes as $i => $close) {
            $result[$i] = $i < 219 ? 0 : ($close > $fast[$i] && $fast[$i] > $slow[$i] && $fast[$i] > $fast[$i - 3] ? 1
                : ($close < $fast[$i] && $fast[$i] < $slow[$i] && $fast[$i] < $fast[$i - 3] ? -1 : 0));
        }

        return $result;
    }

    protected function emas(array $values, int $period): array
    {
        if (count($values) < $period) {
            return [];
        }
        $result = [$period - 1 => array_sum(array_slice($values, 0, $period)) / $period];
        for ($i = $period; $i < count($values); $i++) {
            $result[$i] = $result[$i - 1] + 2 / ($period + 1) * ($values[$i] - $result[$i - 1]);
        }

        return $result;
    }

    protected function continuous(array $bars, int $start, int $end): bool
    {
        return $bars[$end]['time'] - $bars[$start]['time'] === ($end - $start) * 900;
    }

    protected function session(int $at): bool
    {
        $weekday = (int) gmdate('N', $at);
        $hour = (int) gmdate('G', $at);

        return $weekday <= 5 && $hour >= 7 && $hour < 17;
    }

    protected function iso(int $at): string
    {
        return gmdate('Y-m-d\TH:i:s', $at).'+00:00';
    }

    protected function emptySignal(string $source): array
    {
        return [
            'strategy' => static::SLUG, 'action' => 'NO_DATA', 'price' => 0, 'timestamp' => null,
            'trend' => 'unknown', 'position' => 'flat', 'setup_id' => null,
            'message' => 'Insufficient closed market candles.', 'trade_plan' => [],
            'indicators' => ['data_source' => $source], 'error' => null,
        ];
    }
}
