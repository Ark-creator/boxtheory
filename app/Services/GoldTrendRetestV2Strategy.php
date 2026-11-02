<?php

namespace App\Services;

/**
 * Research V2: local four-bar candidates replace V1's indefinitely consumed anchor.
 * Fixed indicator seeds and finite candidate context make rolling and batch inputs agree.
 * No entry thresholds, stop rules or targets were selected from performance results.
 */
class GoldTrendRetestV2Strategy extends GoldTrendRetestStrategy
{
    public const SLUG = 'gold-trend-retest-v2';

    public const INPUT_WINDOW_BARS = 320;

    public const INDICATOR_WINDOW_BARS = 300;

    public const RETEST_MAX_BARS = 4;

    protected function scan(array $m15, array $h1, string $source): array
    {
        $signals = [];
        $latest = $this->emptySignal($source);
        if (count($m15) < self::INPUT_WINDOW_BARS || count($h1) < self::INPUT_WINDOW_BARS) {
            $latest['message'] = 'V2 requires at least 320 completed M15 and H1 candles for reproducible indicator seeds and candidate context.';

            return compact('signals', 'latest');
        }
        $atrs = $this->atrs($m15);
        $hourTrends = $this->trends($h1);
        $trends = $hourIndices = [];
        $hour = -1;
        foreach ($m15 as $i => $bar) {
            $at = $bar['time'] + 900;
            while (isset($h1[$hour + 1]) && $h1[$hour + 1]['time'] + 3600 <= $at) {
                $hour++;
            }
            $hourIndices[$i] = $hour;
            // The EMA50 slope also needs three earlier, fully seeded H1 values.
            $trends[$i] = $hour >= self::INDICATOR_WINDOW_BARS + 2 && $h1[$hour]['time'] + 7200 >= $at ? $hourTrends[$hour] : 0;
        }

        for ($i = self::INPUT_WINDOW_BARS - 1; $i < count($m15); $i++) {
            $bar = $m15[$i];
            $at = $bar['time'] + 900;
            if ($hourIndices[$i] < self::INPUT_WINDOW_BARS - 1) {
                continue;
            }
            $latest = array_replace($this->emptySignal($source), [
                'action' => 'WAIT', 'price' => $bar['close'], 'timestamp' => $this->iso($at),
                'trend' => $trends[$i] === 1 ? 'bullish' : ($trends[$i] === -1 ? 'bearish' : 'flat'),
                'message' => 'Wait for the first qualifying retest of a local H1-aligned M15 breakout.',
                'indicators' => ['data_source' => $source, 'atr_prior' => $atrs[$i - 1],
                    'indicator_seed_bars' => self::INDICATOR_WINDOW_BARS, 'candidate_window_bars' => self::RETEST_MAX_BARS],
            ]);
            if (! $this->session($at)) {
                $latest['message'] = 'New entries are limited to 07:00–17:00 UTC weekdays.';

                continue;
            }
            // Oldest qualifying breakout wins. A rejected first retest is not
            // replaced by another candidate on this candle to chase an entry.
            for ($b = $i - self::RETEST_MAX_BARS; $b < $i; $b++) {
                $episode = $this->breakout($m15, $atrs, $trends, $b, $source);
                if ($episode === null) {
                    continue;
                }
                for ($r = $b + 1; $r <= $i; $r++) {
                    $retest = $m15[$r];
                    $side = $episode['side'];
                    $inside = ($retest['close'] - $episode['boundary']) * $side;
                    if ($retest['time'] - $m15[$r - 1]['time'] !== 900
                        || self::RETEST_MAX_BARS * 900 < $retest['time'] + 900 - $episode['closed_at']
                        || $inside < -0.25 * $episode['atr']) {
                        break;
                    }
                    $episode['swing'] = $side === 1 ? min($episode['swing'], $retest['low']) : max($episode['swing'], $retest['high']);
                    $touch = $side === 1 ? $retest['low'] <= $episode['boundary'] + 0.1 * $episode['atr']
                        : $retest['high'] >= $episode['boundary'] - 0.1 * $episode['atr'];
                    if (! $touch || $inside <= 0 || ($retest['close'] - $retest['open']) * $side <= 0
                        || $trends[$r] !== $side || ! $this->session($retest['time'] + 900)) {
                        continue;
                    }
                    if ($r !== $i) {
                        // This breakout's first qualifying retest already happened.
                        break;
                    }
                    $latest['setup_id'] = $episode['id'];
                    $latest['indicators'] += ['breakout_boundary' => $episode['boundary'], 'breakout_atr' => $episode['atr'],
                        'breakout_at' => $this->iso($episode['closed_at'])];
                    $latest = $this->retest($latest, $bar, $episode, $at);
                    if (in_array($latest['action'], ['BUY', 'SELL'], true)) {
                        $signals[$latest['timestamp']] = $latest;
                    }
                    // Break the candidate and retest loops together, even on rejection.
                    break 2;
                }
            }
        }

        return compact('signals', 'latest');
    }

    private function breakout(array $bars, array $atrs, array $trends, int $i, string $source): ?array
    {
        $bar = $bars[$i];
        $side = $trends[$i];
        $atr = $atrs[$i - 1];
        $closedAt = $bar['time'] + 900;
        if ($side === 0 || ! $this->session($closedAt) || ! $this->continuous($bars, $i - 20, $i) || $atr <= 0) {
            return null;
        }
        $prior = array_slice($bars, $i - 20, 20);
        $boundary = $side === 1 ? max(array_column($prior, 'high')) : min(array_column($prior, 'low'));
        if (($bar['close'] - $boundary) * $side <= 0.1 * $atr || ($bar['close'] - $bar['open']) * $side <= 0
            || 2.5 * $atr < $bar['high'] - $bar['low'] || $atr / $bar['close'] < 0.0005 || $atr / $bar['close'] > 0.008) {
            return null;
        }

        return [
            'id' => hash('sha256', implode('|', [static::SLUG, $source, $bar['time'], $side, sprintf('%.8F', $boundary)])),
            'side' => $side, 'boundary' => $boundary, 'atr' => $atr, 'closed_at' => $closedAt,
            'swing' => $side === 1 ? $bar['low'] : $bar['high'],
        ];
    }

    protected function atrs(array $bars): array
    {
        $result = [];
        for ($i = 14; $i < count($bars); $i++) {
            $slice = array_slice($bars, max(0, $i - self::INDICATOR_WINDOW_BARS + 1), min($i + 1, self::INDICATOR_WINDOW_BARS));
            $seeded = parent::atrs($slice);
            $result[$i] = $seeded[array_key_last($seeded)];
        }

        return $result;
    }

    protected function emas(array $values, int $period): array
    {
        $result = [];
        for ($i = $period - 1; $i < count($values); $i++) {
            $slice = array_slice($values, max(0, $i - self::INDICATOR_WINDOW_BARS + 1), min($i + 1, self::INDICATOR_WINDOW_BARS));
            $seeded = parent::emas($slice, $period);
            $result[$i] = $seeded[array_key_last($seeded)];
        }

        return $result;
    }
}
