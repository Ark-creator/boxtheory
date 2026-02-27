<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TradingService
{
    private const ALPHA_VANTAGE_URL = 'https://www.alphavantage.co/query';
    private const TWELVEDATA_URL = 'https://api.twelvedata.com';

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
                'source' => null,
                'error' => $payload['error'] ?? 'Unable to load XAU/USD price.',
            ];
        }

        $latest = end($payload['candles']);

        return [
            'price' => $latest['close'],
            'timestamp' => $latest['timestamp'],
            'source' => $payload['source'] ?? null,
            'error' => null,
        ];
    }

    /**
     * Get previous daily high/low levels for XAU/USD.
     */
    public function getDailyLevels(): array
    {
        return Cache::remember('xauusd_daily_levels_v4', 3600, function () {
            $twelveData = $this->getTwelveDataDailyLevels();
            if ($twelveData['error'] === null) {
                return $twelveData;
            }

            $alpha = $this->getAlphaVantageDailyLevels();
            if ($alpha['error'] === null) {
                return $alpha;
            }

            $yahoo = $this->getYahooDailyLevels();
            if ($yahoo['error'] === null) {
                return $yahoo;
            }

            return [
                'high' => 0.0,
                'low' => 0.0,
                'date' => null,
                'source' => null,
                'error' => $twelveData['error'] . ' | ' . $alpha['error'] . ' | ' . $yahoo['error'],
            ];
        });
    }

    /**
     * Fetch and normalize XAU/USD intraday candles.
     */
    public function getXauUsdCandles(string $interval = '5min', int $limit = 200): array
    {
        $cacheKey = sprintf('xauusd_intraday_%s_%d_v4', $interval, $limit);
        $cacheSeconds = max(30, (int) config('services.twelvedata.cache_seconds', 120));

        return Cache::remember($cacheKey, $cacheSeconds, function () use ($interval, $limit) {
            $twelveData = $this->getTwelveDataIntradayCandles($interval, $limit);
            if ($twelveData['error'] === null) {
                return $twelveData;
            }

            $alpha = $this->getAlphaVantageIntradayCandles($interval, $limit);
            if ($alpha['error'] === null) {
                return $alpha;
            }

            $yahoo = $this->getYahooIntradayCandles($interval, $limit);
            if ($yahoo['error'] === null) {
                return $yahoo;
            }

            return [
                'candles' => [],
                'source' => null,
                'error' => $twelveData['error'] . ' | ' . $alpha['error'] . ' | ' . $yahoo['error'],
            ];
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
            in_array($slug, ['rsi-moving-average', 'rsi-moving-averages', 'rsi-ma', 'rsi-ma-method', 'rsi-scalper', 'rsi-gold-scalper'], true) ||
            str_contains($slug, 'rsi')
        ) {
            $payload = $this->getXauUsdCandles();
            if ($payload['error'] !== null) {
                return $this->makeNoDataSignal($slug, $payload['error'], $currentPosition);
            }

            return $this->evaluateRsiMovingAverage($payload['candles'], $currentPosition, [], $payload['source'] ?? null);
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
    public function evaluateRsiMovingAverage(
        array $candles,
        ?string $currentPosition = null,
        array $settings = [],
        ?string $dataSource = null
    ): array
    {
        $settings = array_merge([
            'short_ma_period' => 9,
            'long_ma_period' => 21,
            'rsi_period' => 14,
            'oversold' => 30,
            'overbought' => 70,
            'atr_period' => 14,
            'atr_sl_multiplier' => 1.2,
            'rr_tp1' => 1.5,
            'rr_tp2' => 2.5,
            'swing_lookback' => 12,
        ], $settings);

        if (count($candles) < ($settings['long_ma_period'] + 2)) {
            return $this->makeNoDataSignal('rsi_moving_average', 'Not enough candle data to evaluate strategy.', $currentPosition);
        }

        $closes = array_column($candles, 'close');
        $latest = end($candles);
        $entryPrice = (float) ($latest['close'] ?? 0);

        $shortNow = $this->sma($closes, (int) $settings['short_ma_period']);
        $shortPrev = $this->sma($closes, (int) $settings['short_ma_period'], 1);
        $longNow = $this->sma($closes, (int) $settings['long_ma_period']);
        $longPrev = $this->sma($closes, (int) $settings['long_ma_period'], 1);
        $rsiNow = $this->rsi($closes, (int) $settings['rsi_period']);
        $atrNow = $this->atr($candles, (int) $settings['atr_period']);

        if ($atrNow === null || $atrNow <= 0) {
            $atrNow = max(0.5, ((float) ($latest['high'] ?? $entryPrice) - (float) ($latest['low'] ?? $entryPrice)));
        }

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
        $tradePlan = $this->buildRsiTradePlan(
            $action,
            $entryPrice,
            $candles,
            $atrNow,
            $settings,
            $normalizedPosition
        );

        return [
            'strategy' => 'rsi_moving_average',
            'action' => $action,
            'price' => round($entryPrice, 4),
            'timestamp' => $latest['timestamp'],
            'trend' => $trend,
            'position' => $normalizedPosition ?? 'flat',
            'message' => $message,
            'trade_plan' => $tradePlan,
            'indicators' => [
                'rsi' => round($rsiNow, 2),
                'short_ma' => round($shortNow, 4),
                'long_ma' => round($longNow, 4),
                'atr' => round($atrNow, 4),
                'oversold' => (float) $settings['oversold'],
                'overbought' => (float) $settings['overbought'],
                'bullish_cross' => $bullishCross,
                'bearish_cross' => $bearishCross,
                'data_source' => $dataSource,
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
            'trade_plan' => $this->buildBoxTradePlan($action, $price, $prevHigh, $prevLow),
            'indicators' => [
                'prev_high' => round($prevHigh, 4),
                'prev_low' => round($prevLow, 4),
                'daily_level_date' => $dailyLevels['date'] ?? null,
                'data_source' => $currentPriceData['source'] ?? ($dailyLevels['source'] ?? null),
            ],
            'error' => null,
        ];
    }

    private function buildRsiTradePlan(
        string $action,
        float $entryPrice,
        array $candles,
        float $atr,
        array $settings,
        ?string $normalizedPosition
    ): array {
        $lookback = max(3, (int) ($settings['swing_lookback'] ?? 12));
        $slice = array_slice($candles, -$lookback);
        $recentLow = (float) min(array_column($slice, 'low'));
        $recentHigh = (float) max(array_column($slice, 'high'));

        if (in_array($action, ['BUY', 'SELL'], true)) {
            $slBuffer = $atr * (float) $settings['atr_sl_multiplier'];
            $rrTp1 = (float) $settings['rr_tp1'];
            $rrTp2 = (float) $settings['rr_tp2'];

            if ($action === 'BUY') {
                $stopLoss = min($entryPrice - $slBuffer, $recentLow - ($atr * 0.20));
                $risk = max($entryPrice - $stopLoss, $atr * 0.8);
                $stopLoss = $entryPrice - $risk;
                $tp1 = $entryPrice + ($risk * $rrTp1);
                $tp2 = $entryPrice + ($risk * $rrTp2);

                return [
                    'side' => 'buy',
                    'entry_price' => round($entryPrice, 4),
                    'stop_loss' => round($stopLoss, 4),
                    'take_profit_1' => round($tp1, 4),
                    'take_profit_2' => round($tp2, 4),
                    'risk_per_unit' => round($risk, 4),
                    'rr_tp1' => round($rrTp1, 2),
                    'rr_tp2' => round($rrTp2, 2),
                    'notes' => 'Place buy near entry, SL below recent swing low/ATR buffer.',
                ];
            }

            $stopLoss = max($entryPrice + $slBuffer, $recentHigh + ($atr * 0.20));
            $risk = max($stopLoss - $entryPrice, $atr * 0.8);
            $stopLoss = $entryPrice + $risk;
            $tp1 = $entryPrice - ($risk * $rrTp1);
            $tp2 = $entryPrice - ($risk * $rrTp2);

            return [
                'side' => 'sell',
                'entry_price' => round($entryPrice, 4),
                'stop_loss' => round($stopLoss, 4),
                'take_profit_1' => round($tp1, 4),
                'take_profit_2' => round($tp2, 4),
                'risk_per_unit' => round($risk, 4),
                'rr_tp1' => round($rrTp1, 2),
                'rr_tp2' => round($rrTp2, 2),
                'notes' => 'Place sell near entry, SL above recent swing high/ATR buffer.',
            ];
        }

        if ($action === 'CLOSE') {
            return [
                'side' => $normalizedPosition === 'short' ? 'close_short' : 'close_long',
                'entry_price' => round($entryPrice, 4),
                'stop_loss' => null,
                'take_profit_1' => null,
                'take_profit_2' => null,
                'risk_per_unit' => null,
                'rr_tp1' => null,
                'rr_tp2' => null,
                'notes' => 'Close current position at market.',
            ];
        }

        return [
            'side' => 'wait',
            'entry_price' => null,
            'stop_loss' => null,
            'take_profit_1' => null,
            'take_profit_2' => null,
            'risk_per_unit' => null,
            'rr_tp1' => null,
            'rr_tp2' => null,
            'buy_trigger_above' => round($recentHigh + ($atr * 0.10), 4),
            'sell_trigger_below' => round($recentLow - ($atr * 0.10), 4),
            'notes' => 'No confirmed entry yet. Wait for valid crossover + RSI confirmation.',
        ];
    }

    private function buildBoxTradePlan(string $action, float $price, float $prevHigh, float $prevLow): array
    {
        $boxRange = max($prevHigh - $prevLow, max($price * 0.0015, 1.0));
        $risk = $boxRange * 0.35;

        if ($action === 'BUY') {
            $entry = $price;
            $stop = $entry - $risk;
            $tp1 = $entry + ($risk * 1.5);
            $tp2 = $entry + ($risk * 2.5);

            return [
                'side' => 'buy',
                'entry_price' => round($entry, 4),
                'stop_loss' => round($stop, 4),
                'take_profit_1' => round($tp1, 4),
                'take_profit_2' => round($tp2, 4),
                'risk_per_unit' => round($risk, 4),
                'rr_tp1' => 1.5,
                'rr_tp2' => 2.5,
                'notes' => 'Box Theory long from previous-day demand zone.',
            ];
        }

        if ($action === 'SELL') {
            $entry = $price;
            $stop = $entry + $risk;
            $tp1 = $entry - ($risk * 1.5);
            $tp2 = $entry - ($risk * 2.5);

            return [
                'side' => 'sell',
                'entry_price' => round($entry, 4),
                'stop_loss' => round($stop, 4),
                'take_profit_1' => round($tp1, 4),
                'take_profit_2' => round($tp2, 4),
                'risk_per_unit' => round($risk, 4),
                'rr_tp1' => 1.5,
                'rr_tp2' => 2.5,
                'notes' => 'Box Theory short from previous-day supply zone.',
            ];
        }

        return [
            'side' => 'wait',
            'entry_price' => null,
            'stop_loss' => null,
            'take_profit_1' => null,
            'take_profit_2' => null,
            'risk_per_unit' => null,
            'rr_tp1' => null,
            'rr_tp2' => null,
            'buy_trigger_below' => round($prevLow, 4),
            'sell_trigger_above' => round($prevHigh, 4),
            'notes' => 'Wait for price to reach either box boundary.',
        ];
    }

    private function getTwelveDataIntradayCandles(string $interval, int $limit): array
    {
        $mappedInterval = match ($interval) {
            '1min' => '1min',
            '5min' => '5min',
            '15min' => '15min',
            '30min' => '30min',
            '60min' => '1h',
            default => '5min',
        };

        $api = $this->requestTwelveData('/time_series', [
            'symbol' => 'XAU/USD',
            'interval' => $mappedInterval,
            'outputsize' => max(40, min(5000, $limit)),
            'timezone' => 'UTC',
            'order' => 'ASC',
        ]);

        if ($api['error'] !== null) {
            return ['candles' => [], 'source' => null, 'error' => $api['error']];
        }

        $values = $api['data']['values'] ?? null;
        if (!is_array($values) || $values === []) {
            return ['candles' => [], 'source' => null, 'error' => 'No intraday candles from TwelveData.'];
        }

        $candles = [];
        foreach ($values as $row) {
            $close = isset($row['close']) ? (float) $row['close'] : null;
            if ($close === null) {
                continue;
            }

            $candles[] = [
                'timestamp' => (string) ($row['datetime'] ?? ''),
                'open' => (float) ($row['open'] ?? $close),
                'high' => (float) ($row['high'] ?? $close),
                'low' => (float) ($row['low'] ?? $close),
                'close' => $close,
            ];
        }

        if ($candles === []) {
            return ['candles' => [], 'source' => null, 'error' => 'TwelveData returned empty intraday values.'];
        }

        usort($candles, fn (array $left, array $right) => strcmp($left['timestamp'], $right['timestamp']));

        if ($limit > 0 && count($candles) > $limit) {
            $candles = array_slice($candles, -$limit);
        }

        return [
            'candles' => $candles,
            'source' => 'twelvedata_xauusd',
            'error' => null,
        ];
    }

    private function getTwelveDataDailyLevels(): array
    {
        $api = $this->requestTwelveData('/time_series', [
            'symbol' => 'XAU/USD',
            'interval' => '1day',
            'outputsize' => 5,
            'timezone' => 'UTC',
            'order' => 'ASC',
        ]);

        if ($api['error'] !== null) {
            return ['high' => 0.0, 'low' => 0.0, 'date' => null, 'source' => null, 'error' => $api['error']];
        }

        $values = $api['data']['values'] ?? null;
        if (!is_array($values) || $values === []) {
            return ['high' => 0.0, 'low' => 0.0, 'date' => null, 'source' => null, 'error' => 'No daily levels from TwelveData.'];
        }

        $target = $values[count($values) - 2] ?? end($values);

        return [
            'high' => (float) ($target['high'] ?? 0),
            'low' => (float) ($target['low'] ?? 0),
            'date' => (string) ($target['datetime'] ?? null),
            'source' => 'twelvedata_xauusd',
            'error' => null,
        ];
    }

    private function requestTwelveData(string $path, array $query): array
    {
        $apiKey = (string) config('services.twelvedata.key');
        if ($apiKey === '') {
            return ['data' => null, 'error' => 'TWELVEDATA_API_KEY is missing from your .env file.'];
        }

        $url = rtrim(self::TWELVEDATA_URL, '/') . '/' . ltrim($path, '/');

        try {
            $response = Http::timeout(15)
                ->retry(2, 300)
                ->get($url, array_merge($query, ['apikey' => $apiKey]));
        } catch (\Throwable $exception) {
            return ['data' => null, 'error' => 'TwelveData request error: ' . $exception->getMessage()];
        }

        if (!$response->successful()) {
            return ['data' => null, 'error' => 'TwelveData request failed: HTTP ' . $response->status()];
        }

        $data = $response->json();
        if (!is_array($data)) {
            return ['data' => null, 'error' => 'Unexpected TwelveData response format.'];
        }

        if (($data['status'] ?? null) === 'error') {
            return ['data' => null, 'error' => (string) ($data['message'] ?? 'Unknown TwelveData error.')];
        }

        return ['data' => $data, 'error' => null];
    }

    private function getAlphaVantageIntradayCandles(string $interval, int $limit): array
    {
        $api = $this->requestAlphaVantage([
            'function' => 'FX_INTRADAY',
            'from_symbol' => 'XAU',
            'to_symbol' => 'USD',
            'interval' => $interval,
            'outputsize' => 'full',
        ]);

        if ($api['error'] !== null) {
            return ['candles' => [], 'source' => null, 'error' => $api['error']];
        }

        $seriesKey = sprintf('Time Series FX (%s)', $interval);
        $series = $api['data'][$seriesKey] ?? null;

        if (!is_array($series) || $series === []) {
            return ['candles' => [], 'source' => null, 'error' => 'No intraday XAU/USD candles from Alpha Vantage.'];
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

        return ['candles' => $candles, 'source' => 'alphavantage_fx_intraday', 'error' => null];
    }

    private function getAlphaVantageDailyLevels(): array
    {
        $api = $this->requestAlphaVantage([
            'function' => 'FX_DAILY',
            'from_symbol' => 'XAU',
            'to_symbol' => 'USD',
            'outputsize' => 'compact',
        ]);

        if ($api['error'] !== null) {
            return ['high' => 0.0, 'low' => 0.0, 'date' => null, 'source' => null, 'error' => $api['error']];
        }

        $series = $api['data']['Time Series FX (Daily)'] ?? null;
        if (!is_array($series) || $series === []) {
            return [
                'high' => 0.0,
                'low' => 0.0,
                'date' => null,
                'source' => null,
                'error' => 'No daily XAU/USD levels from Alpha Vantage.',
            ];
        }

        $dates = array_keys($series);
        rsort($dates);
        $targetDate = $dates[1] ?? $dates[0] ?? null;

        return [
            'high' => (float) ($series[$targetDate]['2. high'] ?? 0),
            'low' => (float) ($series[$targetDate]['3. low'] ?? 0),
            'date' => $targetDate,
            'source' => 'alphavantage_fx_daily',
            'error' => null,
        ];
    }

    private function getYahooIntradayCandles(string $interval, int $limit): array
    {
        $mappedInterval = match ($interval) {
            '1min' => '1m',
            '5min' => '5m',
            '15min' => '15m',
            '30min' => '30m',
            '60min' => '60m',
            default => '5m',
        };

        $api = $this->requestYahooChart('GC=F', $mappedInterval, '5d');
        if ($api['error'] !== null) {
            return ['candles' => [], 'source' => null, 'error' => $api['error']];
        }

        $result = $api['data']['chart']['result'][0] ?? null;
        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];

        if (!is_array($timestamps) || $timestamps === [] || !is_array($quote)) {
            return ['candles' => [], 'source' => null, 'error' => 'No intraday gold candles from Yahoo fallback.'];
        }

        $candles = [];
        foreach ($timestamps as $index => $epoch) {
            $close = $quote['close'][$index] ?? null;
            if ($close === null) {
                continue;
            }

            $open = $quote['open'][$index] ?? $close;
            $high = $quote['high'][$index] ?? $close;
            $low = $quote['low'][$index] ?? $close;

            $candles[] = [
                'timestamp' => gmdate('Y-m-d H:i:s', (int) $epoch),
                'open' => (float) $open,
                'high' => (float) $high,
                'low' => (float) $low,
                'close' => (float) $close,
            ];
        }

        if ($candles === []) {
            return ['candles' => [], 'source' => null, 'error' => 'Yahoo fallback returned empty candle values.'];
        }

        if ($limit > 0 && count($candles) > $limit) {
            $candles = array_slice($candles, -$limit);
        }

        return [
            'candles' => $candles,
            'source' => 'yahoo_gc_futures_fallback',
            'error' => null,
        ];
    }

    private function getYahooDailyLevels(): array
    {
        $api = $this->requestYahooChart('GC=F', '1d', '1mo');
        if ($api['error'] !== null) {
            return ['high' => 0.0, 'low' => 0.0, 'date' => null, 'source' => null, 'error' => $api['error']];
        }

        $result = $api['data']['chart']['result'][0] ?? null;
        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];

        if (!is_array($timestamps) || count($timestamps) < 1 || !is_array($quote)) {
            return ['high' => 0.0, 'low' => 0.0, 'date' => null, 'source' => null, 'error' => 'No daily candles from Yahoo fallback.'];
        }

        $candles = [];
        foreach ($timestamps as $index => $epoch) {
            $high = $quote['high'][$index] ?? null;
            $low = $quote['low'][$index] ?? null;
            if ($high === null || $low === null) {
                continue;
            }

            $candles[] = [
                'date' => gmdate('Y-m-d', (int) $epoch),
                'high' => (float) $high,
                'low' => (float) $low,
            ];
        }

        if ($candles === []) {
            return ['high' => 0.0, 'low' => 0.0, 'date' => null, 'source' => null, 'error' => 'Yahoo fallback daily candle values are empty.'];
        }

        $target = $candles[count($candles) - 2] ?? end($candles);

        return [
            'high' => (float) $target['high'],
            'low' => (float) $target['low'],
            'date' => $target['date'],
            'source' => 'yahoo_gc_futures_fallback',
            'error' => null,
        ];
    }

    private function requestYahooChart(string $symbol, string $interval, string $range): array
    {
        $url = sprintf('https://query1.finance.yahoo.com/v8/finance/chart/%s', rawurlencode($symbol));

        try {
            $response = Http::timeout(15)
                ->retry(2, 300)
                ->get($url, [
                    'interval' => $interval,
                    'range' => $range,
                ]);
        } catch (\Throwable $exception) {
            return ['data' => null, 'error' => 'Yahoo request error: ' . $exception->getMessage()];
        }

        if (!$response->successful()) {
            return ['data' => null, 'error' => 'Yahoo request failed: HTTP ' . $response->status()];
        }

        $data = $response->json();
        if (!is_array($data)) {
            return ['data' => null, 'error' => 'Unexpected Yahoo response format.'];
        }

        $chartError = $data['chart']['error']['description'] ?? null;
        if (is_string($chartError) && $chartError !== '') {
            return ['data' => null, 'error' => 'Yahoo chart error: ' . $chartError];
        }

        return ['data' => $data, 'error' => null];
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

        if (isset($data['Information'])) {
            return ['data' => null, 'error' => (string) $data['Information']];
        }

        if (isset($data['Note'])) {
            return ['data' => null, 'error' => (string) $data['Note']];
        }

        return ['data' => $data, 'error' => null];
    }

    private function atr(array $candles, int $period = 14): ?float
    {
        if ($period <= 0 || count($candles) < ($period + 1)) {
            return null;
        }

        $slice = array_slice($candles, -($period + 1));
        $trs = [];

        for ($i = 1, $len = count($slice); $i < $len; $i++) {
            $current = $slice[$i];
            $previous = $slice[$i - 1];

            $high = (float) ($current['high'] ?? 0);
            $low = (float) ($current['low'] ?? 0);
            $prevClose = (float) ($previous['close'] ?? 0);

            $trs[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
        }

        if ($trs === []) {
            return null;
        }

        return array_sum($trs) / count($trs);
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
            'trade_plan' => [
                'side' => 'none',
                'entry_price' => null,
                'stop_loss' => null,
                'take_profit_1' => null,
                'take_profit_2' => null,
                'risk_per_unit' => null,
                'rr_tp1' => null,
                'rr_tp2' => null,
                'notes' => 'No actionable plan because data is unavailable.',
            ],
            'indicators' => [],
            'error' => $error,
        ];
    }
}
