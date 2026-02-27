<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TradingService
{
    private const ALPHA_VANTAGE_URL = 'https://www.alphavantage.co/query';

    /**
     * Returns the latest XAU/USD close from intraday candles.
     */
    public function getXauUsdData(): array
    {
        $payload = $this->getXauUsdCandles();
        if ($payload['error'] !== null || empty($payload['candles'])) {
            return [
                'price' => 0.0,
                'timestamp' => null,
                'error' => $payload['error'] ?? 'Unable to load XAU/USD price.',
            ];
        }

        $latest = end($payload['candles']);

        return [
            'price' => $latest['close'],
            'timestamp' => $latest['timestamp'],
            'error' => null,
        ];
    }

    /**
     * Get previous daily high/low levels for XAU/USD.
     */
    public function getDailyLevels(): array
    {
        return Cache::remember('xauusd_daily_levels_v2', 3600, function () {
            $api = $this->requestAlphaVantage([
                'function' => 'FX_DAILY',
                'from_symbol' => 'XAU',
                'to_symbol' => 'USD',
                'outputsize' => 'compact',
            ]);

            if ($api['error'] !== null) {
                return ['high' => 0.0, 'low' => 0.0, 'date' => null, 'error' => $api['error']];
            }

            $series = $api['data']['Time Series FX (Daily)'] ?? null;
            if (!is_array($series) || $series === []) {
                return [
                    'high' => 0.0,
                    'low' => 0.0,
                    'date' => null,
                    'error' => 'No daily XAU/USD levels returned by the API.',
                ];
            }

            $dates = array_keys($series);
            rsort($dates); // latest first, then previous days
            $targetDate = $dates[1] ?? $dates[0] ?? null;

            return [
                'high' => (float) ($series[$targetDate]['2. high'] ?? 0),
                'low' => (float) ($series[$targetDate]['3. low'] ?? 0),
                'date' => $targetDate,
                'error' => null,
            ];
        });
    }

    /**
     * Fetch and normalize XAU/USD intraday candles.
     */
    public function getXauUsdCandles(string $interval = '5min', int $limit = 200): array
    {
        $cacheKey = sprintf('xauusd_intraday_%s_%d_v2', $interval, $limit);

        return Cache::remember($cacheKey, 60, function () use ($interval, $limit) {
            $api = $this->requestAlphaVantage([
                'function' => 'FX_INTRADAY',
                'from_symbol' => 'XAU',
                'to_symbol' => 'USD',
                'interval' => $interval,
                'outputsize' => 'full',
            ]);

            if ($api['error'] !== null) {
                return ['candles' => [], 'error' => $api['error']];
            }

            $seriesKey = sprintf('Time Series FX (%s)', $interval);
            $series = $api['data'][$seriesKey] ?? null;

            if (!is_array($series) || $series === []) {
                return ['candles' => [], 'error' => 'No intraday XAU/USD candles returned by the API.'];
            }

            $candles = [];
            foreach ($series as $timestamp => $row) {
                $candles[] = [
                    'timestamp' => (string) $timestamp,
                    'open' => (float) ($row['1. open'] ?? 0),
                    'high' => (float) ($row['2. high'] ?? 0),
                    'low' => (float) ($row['3. low'] ?? 0),
                    'close' => (float) ($row['4. close'] ?? 0),
                ];
            }

            usort($candles, fn (array $left, array $right) => strcmp($left['timestamp'], $right['timestamp']));

            if ($limit > 0 && count($candles) > $limit) {
                $candles = array_slice($candles, -$limit);
            }

            return ['candles' => $candles, 'error' => null];
        });
    }

    /**
     * Build a signal output by strategy slug.
     */
    public function getSignalForStrategy(string $strategySlug, ?string $currentPosition = null): array
    {
        $slug = strtolower(trim($strategySlug));

        if (in_array($slug, ['box-theory', 'box'], true)) {
            return $this->evaluateBoxTheory($this->getXauUsdData(), $this->getDailyLevels());
        }

        if (
            in_array($slug, ['rsi-moving-average', 'rsi-moving-averages', 'rsi-ma', 'rsi-ma-method'], true) ||
            (str_contains($slug, 'rsi') && (str_contains($slug, 'moving') || str_contains($slug, 'ma')))
        ) {
            $payload = $this->getXauUsdCandles();
            if ($payload['error'] !== null) {
                return $this->makeNoDataSignal($slug, $payload['error'], $currentPosition);
            }

            return $this->evaluateRsiMovingAverage($payload['candles'], $currentPosition);
        }

        return $this->makeNoDataSignal(
            $slug,
            'Unsupported strategy slug. Add a handler in TradingService::getSignalForStrategy().',
            $currentPosition
        );
    }

    /**
     * RSI + Moving Averages strategy:
     * - BUY when short MA crosses above long MA and RSI confirms momentum.
     * - SELL when short MA crosses below long MA and RSI confirms weakness.
     * - CLOSE when current position loses momentum or hits RSI extremes.
     */
    public function evaluateRsiMovingAverage(array $candles, ?string $currentPosition = null, array $settings = []): array
    {
        $settings = array_merge([
            'short_ma_period' => 9,
            'long_ma_period' => 21,
            'rsi_period' => 14,
            'oversold' => 30,
            'overbought' => 70,
        ], $settings);

        if (count($candles) < ($settings['long_ma_period'] + 2)) {
            return $this->makeNoDataSignal('rsi_moving_average', 'Not enough candle data to evaluate strategy.', $currentPosition);
        }

        $closes = array_column($candles, 'close');
        $latest = end($candles);

        $shortNow = $this->sma($closes, (int) $settings['short_ma_period']);
        $shortPrev = $this->sma($closes, (int) $settings['short_ma_period'], 1);
        $longNow = $this->sma($closes, (int) $settings['long_ma_period']);
        $longPrev = $this->sma($closes, (int) $settings['long_ma_period'], 1);
        $rsiNow = $this->rsi($closes, (int) $settings['rsi_period']);

        if ($shortNow === null || $shortPrev === null || $longNow === null || $longPrev === null || $rsiNow === null) {
            return $this->makeNoDataSignal('rsi_moving_average', 'Failed to compute RSI/MA indicators from candles.', $currentPosition);
        }

        $normalizedPosition = $this->normalizePosition($currentPosition);
        $bullishCross = $shortPrev <= $longPrev && $shortNow > $longNow;
        $bearishCross = $shortPrev >= $longPrev && $shortNow < $longNow;

        $action = 'HOLD';
        $message = 'No entry/exit trigger yet. Keep waiting for confirmation.';

        if ($normalizedPosition === 'long' && ($bearishCross || $rsiNow >= $settings['overbought'])) {
            $action = 'CLOSE';
            $message = $bearishCross
                ? 'Close long: short MA crossed below long MA.'
                : 'Close long: RSI reached overbought threshold.';
        } elseif ($normalizedPosition === 'short' && ($bullishCross || $rsiNow <= $settings['oversold'])) {
            $action = 'CLOSE';
            $message = $bullishCross
                ? 'Close short: short MA crossed above long MA.'
                : 'Close short: RSI reached oversold threshold.';
        } elseif ($bullishCross && $rsiNow > 50 && $rsiNow < $settings['overbought']) {
            $action = 'BUY';
            $message = 'Buy setup: bullish MA crossover with RSI momentum confirmation.';
        } elseif ($bearishCross && $rsiNow < 50 && $rsiNow > $settings['oversold']) {
            $action = 'SELL';
            $message = 'Sell setup: bearish MA crossover with RSI momentum confirmation.';
        }

        $trend = $shortNow > $longNow ? 'bullish' : ($shortNow < $longNow ? 'bearish' : 'flat');

        return [
            'strategy' => 'rsi_moving_average',
            'action' => $action,
            'price' => round((float) $latest['close'], 4),
            'timestamp' => $latest['timestamp'],
            'trend' => $trend,
            'position' => $normalizedPosition ?? 'flat',
            'message' => $message,
            'indicators' => [
                'rsi' => round($rsiNow, 2),
                'short_ma' => round($shortNow, 4),
                'long_ma' => round($longNow, 4),
                'oversold' => (float) $settings['oversold'],
                'overbought' => (float) $settings['overbought'],
                'bullish_cross' => $bullishCross,
                'bearish_cross' => $bearishCross,
            ],
            'error' => null,
        ];
    }

    private function evaluateBoxTheory(array $currentPriceData, array $dailyLevels): array
    {
        if (($currentPriceData['error'] ?? null) !== null) {
            return $this->makeNoDataSignal('box_theory', $currentPriceData['error']);
        }

        if (($dailyLevels['error'] ?? null) !== null) {
            return $this->makeNoDataSignal('box_theory', $dailyLevels['error']);
        }

        $price = (float) ($currentPriceData['price'] ?? 0);
        $prevHigh = (float) ($dailyLevels['high'] ?? 0);
        $prevLow = (float) ($dailyLevels['low'] ?? 0);

        if ($price <= 0 || $prevHigh <= 0 || $prevLow <= 0) {
            return $this->makeNoDataSignal('box_theory', 'Invalid Box Theory inputs from API response.');
        }

        $action = 'HOLD';
        $message = 'Price is inside the box. Wait for breakout.';

        if ($price >= $prevHigh) {
            $action = 'SELL';
            $message = 'Price touched/closed above previous daily high. Box Theory sell zone.';
        } elseif ($price <= $prevLow) {
            $action = 'BUY';
            $message = 'Price touched/closed below previous daily low. Box Theory buy zone.';
        }

        return [
            'strategy' => 'box_theory',
            'action' => $action,
            'price' => round($price, 4),
            'timestamp' => $currentPriceData['timestamp'] ?? null,
            'trend' => 'neutral',
            'position' => 'flat',
            'message' => $message,
            'indicators' => [
                'prev_high' => round($prevHigh, 4),
                'prev_low' => round($prevLow, 4),
                'daily_level_date' => $dailyLevels['date'] ?? null,
            ],
            'error' => null,
        ];
    }

    private function requestAlphaVantage(array $query): array
    {
        $apiKey = (string) config('services.alphavantage.key');
        if ($apiKey === '') {
            return ['data' => null, 'error' => 'ALPHA_VANTAGE_KEY is missing from your .env file.'];
        }

        try {
            $response = Http::timeout(15)
                ->retry(2, 300)
                ->get(self::ALPHA_VANTAGE_URL, array_merge($query, ['apikey' => $apiKey]));
        } catch (\Throwable $exception) {
            return ['data' => null, 'error' => 'Alpha Vantage request error: ' . $exception->getMessage()];
        }

        if (!$response->successful()) {
            return ['data' => null, 'error' => 'Alpha Vantage request failed: HTTP ' . $response->status()];
        }

        $data = $response->json();
        if (!is_array($data)) {
            return ['data' => null, 'error' => 'Unexpected API response format.'];
        }

        if (isset($data['Error Message'])) {
            return ['data' => null, 'error' => (string) $data['Error Message']];
        }

        if (isset($data['Note'])) {
            return ['data' => null, 'error' => (string) $data['Note']];
        }

        return ['data' => $data, 'error' => null];
    }

    private function sma(array $values, int $period, int $offset = 0): ?float
    {
        $needed = $period + $offset;
        if ($period <= 0 || count($values) < $needed) {
            return null;
        }

        $start = count($values) - $period - $offset;
        $slice = array_slice($values, $start, $period);
        if (count($slice) < $period) {
            return null;
        }

        return array_sum($slice) / $period;
    }

    private function rsi(array $closes, int $period, int $offset = 0): ?float
    {
        $needed = $period + 1 + $offset;
        if ($period <= 0 || count($closes) < $needed) {
            return null;
        }

        $end = count($closes) - $offset;
        $window = array_slice($closes, $end - ($period + 1), $period + 1);

        $gains = 0.0;
        $losses = 0.0;

        for ($i = 1, $len = count($window); $i < $len; $i++) {
            $delta = $window[$i] - $window[$i - 1];
            if ($delta >= 0) {
                $gains += $delta;
            } else {
                $losses += abs($delta);
            }
        }

        $averageGain = $gains / $period;
        $averageLoss = $losses / $period;

        if ($averageLoss == 0.0) {
            return 100.0;
        }

        $rs = $averageGain / $averageLoss;

        return 100 - (100 / (1 + $rs));
    }

    private function normalizePosition(?string $position): ?string
    {
        if ($position === null) {
            return null;
        }

        $value = strtolower(trim($position));

        if (in_array($value, ['buy', 'long'], true)) {
            return 'long';
        }

        if (in_array($value, ['sell', 'short'], true)) {
            return 'short';
        }

        return null;
    }

    private function makeNoDataSignal(string $strategySlug, string $error, ?string $currentPosition = null): array
    {
        return [
            'strategy' => $strategySlug,
            'action' => 'NO_DATA',
            'price' => 0.0,
            'timestamp' => null,
            'trend' => 'unknown',
            'position' => $this->normalizePosition($currentPosition) ?? 'flat',
            'message' => $error,
            'indicators' => [],
            'error' => $error,
        ];
    }
}
