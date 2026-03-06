<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TradingService
{
    private const ALPHA_VANTAGE_URL = 'https://www.alphavantage.co/query';
    private const TWELVEDATA_URL = 'https://api.twelvedata.com';
    private const FALLBACK_PAIR_CODE = 'XAUUSD';
    private const FALLBACK_PAIR = [
        'display' => 'XAU/USD',
        'name' => 'Gold Spot',
        'asset_class' => 'Metals',
        'twelvedata_symbol' => 'XAU/USD',
        'alpha_from_symbol' => 'XAU',
        'alpha_to_symbol' => 'USD',
        'yahoo_symbol' => 'GC=F',
        'pip_size' => 0.1,
    ];

    /**
     * Returns the latest XAU/USD close from intraday candles.
     */
    public function getXauUsdData(): array
    {
        return $this->getMarketData(self::FALLBACK_PAIR_CODE);
    }

    public function getMarketData(?string $pairCode = null): array
    {
        $pair = $this->resolvePair($pairCode);
        $payload = $this->getCandles($pair['code']);
        if ($payload['error'] !== null || empty($payload['candles'])) {
            return [
                'price' => 0.0,
                'timestamp' => null,
                'source' => null,
                'error' => $payload['error'] ?? sprintf('Unable to load %s price.', $pair['display']),
                'symbol' => $pair['display'],
                'symbol_code' => $pair['code'],
            ];
        }

        $latest = end($payload['candles']);

        return [
            'price' => $latest['close'],
            'timestamp' => $latest['timestamp'],
            'source' => $payload['source'] ?? null,
            'error' => null,
            'symbol' => $pair['display'],
            'symbol_code' => $pair['code'],
        ];
    }

    /**
     * Get previous daily high/low levels for a market pair.
     */
    public function getDailyLevels(?string $pairCode = null): array
    {
        $pair = $this->resolvePair($pairCode);
        $cacheKey = sprintf('%s_daily_levels_v5', strtolower($pair['code']));

        return Cache::remember($cacheKey, 3600, function () use ($pair) {
            $twelveData = $this->getTwelveDataDailyLevels($pair);
            if ($twelveData['error'] === null) {
                return $twelveData;
            }

            $alpha = $this->getAlphaVantageDailyLevels($pair);
            if ($alpha['error'] === null) {
                return $alpha;
            }

            $yahoo = $this->getYahooDailyLevels($pair);
            if ($yahoo['error'] === null) {
                return $yahoo;
            }

            return [
                'high' => 0.0,
                'low' => 0.0,
                'date' => null,
                'source' => null,
                'error' => $twelveData['error'] . ' | ' . $alpha['error'] . ' | ' . $yahoo['error'],
                'symbol' => $pair['display'],
                'symbol_code' => $pair['code'],
            ];
        });
    }

    /**
     * Fetch and normalize XAU/USD intraday candles.
     */
    public function getXauUsdCandles(string $interval = '5min', int $limit = 200): array
    {
        return $this->getCandles(self::FALLBACK_PAIR_CODE, $interval, $limit);
    }

    public function getCandles(?string $pairCode = null, string $interval = '5min', int $limit = 200): array
    {
        $pair = $this->resolvePair($pairCode);
        $cacheKey = sprintf('%s_intraday_%s_%d_v5', strtolower($pair['code']), $interval, $limit);
        $cacheSeconds = max(30, (int) config('services.twelvedata.cache_seconds', 120));

        return Cache::remember($cacheKey, $cacheSeconds, function () use ($pair, $interval, $limit) {
            $twelveData = $this->getTwelveDataIntradayCandles($pair, $interval, $limit);
            if ($twelveData['error'] === null) {
                return $twelveData;
            }

            $alpha = $this->getAlphaVantageIntradayCandles($pair, $interval, $limit);
            if ($alpha['error'] === null) {
                return $alpha;
            }

            $yahoo = $this->getYahooIntradayCandles($pair, $interval, $limit);
            if ($yahoo['error'] === null) {
                return $yahoo;
            }

            return [
                'candles' => [],
                'source' => null,
                'error' => $twelveData['error'] . ' | ' . $alpha['error'] . ' | ' . $yahoo['error'],
                'symbol' => $pair['display'],
                'symbol_code' => $pair['code'],
            ];
        });
    }

    /**
     * Build a signal output by strategy slug.
     */
    public function getSignalForStrategy(string $strategySlug, ?string $currentPosition = null, ?string $pairCode = null): array
    {
        $slug = strtolower(trim($strategySlug));
        $pair = $this->resolvePair($pairCode);

        if (in_array($slug, ['box-theory', 'box'], true)) {
            return $this->attachPairContext(
                $this->evaluateBoxTheory($this->getMarketData($pair['code']), $this->getDailyLevels($pair['code'])),
                $pair
            );
        }

        if (in_array($slug, ['conservative-v2', 'conservative-v2-final', 'conservative'], true)) {
            $ltf = $this->getCandles($pair['code'], '5min', 500);
            $htf = $this->getCandles($pair['code'], '60min', 300);

            if ($ltf['error'] !== null) {
                return $this->attachPairContext(
                    $this->makeNoDataSignal($slug, $ltf['error'], $currentPosition),
                    $pair
                );
            }

            if ($htf['error'] !== null) {
                return $this->attachPairContext(
                    $this->makeNoDataSignal($slug, $htf['error'], $currentPosition),
                    $pair
                );
            }

            return $this->attachPairContext($this->evaluateConservativeV2(
                $ltf['candles'],
                $htf['candles'],
                $currentPosition,
                $ltf['source'] ?? null,
                isset($pair['pip_size']) ? (float) $pair['pip_size'] : null
            ), $pair);
        }

        if (
            in_array($slug, ['rsi-moving-average', 'rsi-moving-averages', 'rsi-ma', 'rsi-ma-method', 'rsi-scalper', 'rsi-gold-scalper'], true) ||
            str_contains($slug, 'rsi')
        ) {
            $payload = $this->getCandles($pair['code']);
            if ($payload['error'] !== null) {
                return $this->attachPairContext(
                    $this->makeNoDataSignal($slug, $payload['error'], $currentPosition),
                    $pair
                );
            }

            return $this->attachPairContext(
                $this->evaluateRsiMovingAverage($payload['candles'], $currentPosition, [], $payload['source'] ?? null),
                $pair
            );
        }

        return $this->attachPairContext(
            $this->makeNoDataSignal(
                $slug,
                'Unsupported strategy slug. Add a handler in TradingService::getSignalForStrategy().',
                $currentPosition
            ),
            $pair
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getAvailablePairs(): array
    {
        return $this->pairCatalog();
    }

    /**
     * @return array<int,string>
     */
    public function getMatrixPairCodes(): array
    {
        $pairs = $this->pairCatalog();
        $configured = (string) config('trading.matrix.pair_codes', '');

        if (trim($configured) === '') {
            return array_keys($pairs);
        }

        $codes = array_values(array_filter(array_map(
            fn (string $item): string => $this->normalizePairCode($item),
            explode(',', $configured)
        )));

        if ($codes === []) {
            return array_keys($pairs);
        }

        $unique = array_values(array_unique($codes));

        return array_values(array_filter($unique, static fn (string $code): bool => array_key_exists($code, $pairs)));
    }

    public function normalizePairCode(?string $pairCode): string
    {
        $pairs = $this->pairCatalog();
        $defaultCode = $this->defaultPairCode();

        if ($pairCode === null || trim($pairCode) === '') {
            return $defaultCode;
        }

        $normalized = strtoupper(preg_replace('/[^A-Z]/i', '', $pairCode) ?? '');

        if ($normalized !== '' && array_key_exists($normalized, $pairs)) {
            return $normalized;
        }

        return $defaultCode;
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

    private function evaluateConservativeV2(
        array $ltfCandles,
        array $htfCandles,
        ?string $currentPosition = null,
        ?string $dataSource = null,
        ?float $pipSize = null
    ): array {
        $settings = [
            'fast_ema_period' => 50,
            'slow_ema_period' => 200,
            'rsi_period' => 14,
            'rsi_oversold' => 30,
            'rsi_overbought' => 70,
            'adx_period' => 14,
            'adx_threshold' => 20.0,
            'bb_period' => 20,
            'bb_deviations' => 2.0,
            'atr_period' => 14,
            'atr_multiplier' => 3.0,
            'rr' => 4.0,
            'atr_vs_htf_multiplier' => 0.8,
            'divergence_lookback' => 50,
            'hard_stop_pips' => 30,
            'swing_lookback' => 30,
            'swing_buffer_pips' => 5,
            'break_even_activate_pips' => 40,
            'break_even_extra_pips' => 2,
            'trailing_activate_pips' => 80,
            'trailing_stop_pips' => 40,
            'stop_mode' => 'atr',
        ];

        $minNeeded = max($settings['slow_ema_period'] + 5, 220);
        if (count($ltfCandles) < $minNeeded || count($htfCandles) < $minNeeded) {
            return $this->makeNoDataSignal('conservative_v2', 'Not enough candle data for Conservative V2 filters.', $currentPosition);
        }

        $ltfCloses = array_column($ltfCandles, 'close');
        $htfCloses = array_column($htfCandles, 'close');
        $latest = end($ltfCandles);
        $entryPrice = (float) ($latest['close'] ?? 0);

        $fastEma = $this->ema($ltfCloses, $settings['fast_ema_period']);
        $slowEma = $this->ema($ltfCloses, $settings['slow_ema_period']);
        $htfFastEma = $this->ema($htfCloses, $settings['fast_ema_period']);
        $htfSlowEma = $this->ema($htfCloses, $settings['slow_ema_period']);
        $rsiSeries = $this->rsiSeries($ltfCloses, $settings['rsi_period']);
        $rsiNow = $rsiSeries[count($rsiSeries) - 1] ?? null;
        $adxNow = $this->adx($ltfCandles, $settings['adx_period']);
        $atrLtf = $this->atr($ltfCandles, $settings['atr_period']);
        $atrHtf = $this->atr($htfCandles, $settings['atr_period']);
        $atrUse = max((float) ($atrLtf ?? 0), (float) ($atrHtf ?? 0));

        if (
            $fastEma === null ||
            $slowEma === null ||
            $htfFastEma === null ||
            $htfSlowEma === null ||
            $rsiNow === null ||
            $adxNow === null ||
            $atrUse <= 0
        ) {
            return $this->makeNoDataSignal('conservative_v2', 'Failed to compute one or more Conservative V2 indicators.', $currentPosition);
        }

        $ltfUp = $fastEma > $slowEma;
        $ltfDown = $fastEma < $slowEma;
        $htfDiff = $htfFastEma - $htfSlowEma;
        $htfThreshold = max($htfFastEma * 0.0001, 0.0);
        $trendUp = $ltfUp && $htfFastEma > $htfSlowEma && $htfDiff > $htfThreshold;
        $trendDown = $ltfDown && $htfFastEma < $htfSlowEma && $htfDiff < -$htfThreshold;

        $bollinger = $this->bollingerBands($ltfCloses, $settings['bb_period'], $settings['bb_deviations']);
        $n = count($ltfCloses);
        $prevClose = $ltfCloses[$n - 2] ?? null;
        $currClose = $ltfCloses[$n - 1] ?? null;
        $prevUpper = $bollinger['upper'][$n - 2] ?? null;
        $currUpper = $bollinger['upper'][$n - 1] ?? null;
        $prevLower = $bollinger['lower'][$n - 2] ?? null;
        $currLower = $bollinger['lower'][$n - 1] ?? null;

        $bbBuyOk = $prevClose !== null && $currClose !== null && $prevLower !== null && $currLower !== null
            ? ($prevClose < $prevLower && $currClose > $currLower)
            : false;
        $bbSellOk = $prevClose !== null && $currClose !== null && $prevUpper !== null && $currUpper !== null
            ? ($prevClose > $prevUpper && $currClose < $currUpper)
            : false;

        $bullishDiv = $this->detectBullishDivergence(
            $ltfCloses,
            $rsiSeries,
            $settings['divergence_lookback'],
            $settings['rsi_overbought']
        );
        $bearishDiv = $this->detectBearishDivergence(
            $ltfCloses,
            $rsiSeries,
            $settings['divergence_lookback'],
            $settings['rsi_oversold']
        );

        $adxPass = $adxNow >= $settings['adx_threshold'];
        $volatilityPass = $atrHtf !== null ? ($atrUse >= ($settings['atr_vs_htf_multiplier'] * $atrHtf)) : true;

        $buySignal = $trendUp && $bullishDiv && $bbBuyOk && $adxPass && $volatilityPass;
        $sellSignal = $trendDown && $bearishDiv && $bbSellOk && $adxPass && $volatilityPass;

        $normalizedPosition = $this->normalizePosition($currentPosition);
        $action = 'HOLD';
        $message = 'No entry yet: waiting for trend + divergence + Bollinger confirmation.';

        if ($normalizedPosition === 'long' && $sellSignal) {
            $action = 'CLOSE';
            $message = 'Close long: bearish Conservative V2 setup detected.';
        } elseif ($normalizedPosition === 'short' && $buySignal) {
            $action = 'CLOSE';
            $message = 'Close short: bullish Conservative V2 setup detected.';
        } elseif ($buySignal) {
            $action = 'BUY';
            $message = 'Conservative V2 buy setup confirmed (trend, divergence, BB re-entry, ADX, ATR filters).';
        } elseif ($sellSignal) {
            $action = 'SELL';
            $message = 'Conservative V2 sell setup confirmed (trend, divergence, BB re-entry, ADX, ATR filters).';
        }

        $tradePlan = $this->buildConservativeTradePlan(
            $action,
            $entryPrice,
            $ltfCandles,
            $atrUse,
            $settings,
            $normalizedPosition,
            $pipSize
        );

        return [
            'strategy' => 'conservative_v2',
            'action' => $action,
            'price' => round($entryPrice, 4),
            'timestamp' => $latest['timestamp'] ?? null,
            'trend' => $trendUp ? 'bullish' : ($trendDown ? 'bearish' : 'flat'),
            'position' => $normalizedPosition ?? 'flat',
            'message' => $message,
            'trade_plan' => $tradePlan,
            'indicators' => [
                'ema_fast' => round($fastEma, 4),
                'ema_slow' => round($slowEma, 4),
                'htf_ema_fast' => round($htfFastEma, 4),
                'htf_ema_slow' => round($htfSlowEma, 4),
                'rsi' => round($rsiNow, 2),
                'adx' => round($adxNow, 2),
                'atr_ltf' => round((float) $atrLtf, 4),
                'atr_htf' => round((float) $atrHtf, 4),
                'atr_used' => round($atrUse, 4),
                'bb_buy_ok' => $bbBuyOk,
                'bb_sell_ok' => $bbSellOk,
                'bullish_divergence' => $bullishDiv,
                'bearish_divergence' => $bearishDiv,
                'adx_pass' => $adxPass,
                'volatility_pass' => $volatilityPass,
                'data_source' => $dataSource,
            ],
            'error' => null,
        ];
    }

    private function buildConservativeTradePlan(
        string $action,
        float $entryPrice,
        array $candles,
        float $atrUse,
        array $settings,
        ?string $normalizedPosition,
        ?float $pipSize = null
    ): array {
        $pip = $this->inferPipSize($entryPrice, $pipSize);
        $hardStopDistance = max(0.0, (float) $settings['hard_stop_pips'] * $pip);
        $rr = (float) $settings['rr'];
        $tp1Rr = 2.0;

        if (in_array($action, ['BUY', 'SELL'], true)) {
            $riskDistance = $atrUse * (float) $settings['atr_multiplier'];

            if (($settings['stop_mode'] ?? 'atr') === 'swing') {
                $lookback = max(5, (int) $settings['swing_lookback']);
                $slice = array_slice($candles, -$lookback);
                $recentLow = (float) min(array_column($slice, 'low'));
                $recentHigh = (float) max(array_column($slice, 'high'));
                $swingBuffer = (float) $settings['swing_buffer_pips'] * $pip;

                if ($action === 'BUY') {
                    $riskDistance = max($entryPrice - ($recentLow - $swingBuffer), $pip);
                } else {
                    $riskDistance = max(($recentHigh + $swingBuffer) - $entryPrice, $pip);
                }
            }

            if ($hardStopDistance > 0) {
                $riskDistance = min($riskDistance, $hardStopDistance);
            }

            $riskDistance = max($riskDistance, $pip);

            $isBuy = $action === 'BUY';
            $stopLoss = $isBuy ? ($entryPrice - $riskDistance) : ($entryPrice + $riskDistance);
            $tp1 = $isBuy ? ($entryPrice + ($riskDistance * $tp1Rr)) : ($entryPrice - ($riskDistance * $tp1Rr));
            $tp2 = $isBuy ? ($entryPrice + ($riskDistance * $rr)) : ($entryPrice - ($riskDistance * $rr));
            $beActivatePrice = $isBuy
                ? ($entryPrice + ((float) $settings['break_even_activate_pips'] * $pip))
                : ($entryPrice - ((float) $settings['break_even_activate_pips'] * $pip));
            $beMoveTo = $isBuy
                ? ($entryPrice + ((float) $settings['break_even_extra_pips'] * $pip))
                : ($entryPrice - ((float) $settings['break_even_extra_pips'] * $pip));
            $trailActivatePrice = $isBuy
                ? ($entryPrice + ((float) $settings['trailing_activate_pips'] * $pip))
                : ($entryPrice - ((float) $settings['trailing_activate_pips'] * $pip));

            return [
                'side' => $isBuy ? 'buy' : 'sell',
                'entry_price' => round($entryPrice, 4),
                'stop_loss' => round($stopLoss, 4),
                'take_profit_1' => round($tp1, 4),
                'take_profit_2' => round($tp2, 4),
                'risk_per_unit' => round($riskDistance, 4),
                'rr_tp1' => $tp1Rr,
                'rr_tp2' => round($rr, 2),
                'break_even_activate_price' => round($beActivatePrice, 4),
                'break_even_move_to' => round($beMoveTo, 4),
                'trailing_activate_price' => round($trailActivatePrice, 4),
                'trailing_distance' => round((float) $settings['trailing_stop_pips'] * $pip, 4),
                'notes' => 'SL/TP mirrors Conservative V2 EA profile (ATR-based risk + hard-stop cap + BE/trailing levels).',
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

        $slice = array_slice($candles, -20);
        $recentLow = (float) min(array_column($slice, 'low'));
        $recentHigh = (float) max(array_column($slice, 'high'));

        return [
            'side' => 'wait',
            'entry_price' => null,
            'stop_loss' => null,
            'take_profit_1' => null,
            'take_profit_2' => null,
            'risk_per_unit' => null,
            'rr_tp1' => null,
            'rr_tp2' => null,
            'buy_trigger_above' => round($recentHigh, 4),
            'sell_trigger_below' => round($recentLow, 4),
            'notes' => 'Wait for divergence + BB re-entry + trend confirmation.',
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

    /**
     * @param array<string,mixed> $pair
     */
    private function getTwelveDataIntradayCandles(array $pair, string $interval, int $limit): array
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
            'symbol' => (string) ($pair['twelvedata_symbol'] ?? self::FALLBACK_PAIR['twelvedata_symbol']),
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
            return [
                'candles' => [],
                'source' => null,
                'error' => sprintf('No intraday %s candles from TwelveData.', $pair['display']),
            ];
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
            return [
                'candles' => [],
                'source' => null,
                'error' => sprintf('TwelveData returned empty intraday values for %s.', $pair['display']),
            ];
        }

        usort($candles, fn (array $left, array $right) => strcmp($left['timestamp'], $right['timestamp']));

        if ($limit > 0 && count($candles) > $limit) {
            $candles = array_slice($candles, -$limit);
        }

        return [
            'candles' => $candles,
            'source' => 'twelvedata_' . strtolower((string) $pair['code']),
            'error' => null,
        ];
    }

    /**
     * @param array<string,mixed> $pair
     */
    private function getTwelveDataDailyLevels(array $pair): array
    {
        $api = $this->requestTwelveData('/time_series', [
            'symbol' => (string) ($pair['twelvedata_symbol'] ?? self::FALLBACK_PAIR['twelvedata_symbol']),
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
            return [
                'high' => 0.0,
                'low' => 0.0,
                'date' => null,
                'source' => null,
                'error' => sprintf('No daily %s levels from TwelveData.', $pair['display']),
            ];
        }

        $target = $values[count($values) - 2] ?? end($values);

        return [
            'high' => (float) ($target['high'] ?? 0),
            'low' => (float) ($target['low'] ?? 0),
            'date' => (string) ($target['datetime'] ?? null),
            'source' => 'twelvedata_' . strtolower((string) $pair['code']),
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

    /**
     * @param array<string,mixed> $pair
     */
    private function getAlphaVantageIntradayCandles(array $pair, string $interval, int $limit): array
    {
        $api = $this->requestAlphaVantage([
            'function' => 'FX_INTRADAY',
            'from_symbol' => (string) ($pair['alpha_from_symbol'] ?? self::FALLBACK_PAIR['alpha_from_symbol']),
            'to_symbol' => (string) ($pair['alpha_to_symbol'] ?? self::FALLBACK_PAIR['alpha_to_symbol']),
            'interval' => $interval,
            'outputsize' => 'full',
        ]);

        if ($api['error'] !== null) {
            return ['candles' => [], 'source' => null, 'error' => $api['error']];
        }

        $seriesKey = sprintf('Time Series FX (%s)', $interval);
        $series = $api['data'][$seriesKey] ?? null;

        if (!is_array($series) || $series === []) {
            return [
                'candles' => [],
                'source' => null,
                'error' => sprintf('No intraday %s candles from Alpha Vantage.', $pair['display']),
            ];
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

        return [
            'candles' => $candles,
            'source' => 'alphavantage_fx_intraday_' . strtolower((string) $pair['code']),
            'error' => null,
        ];
    }

    /**
     * @param array<string,mixed> $pair
     */
    private function getAlphaVantageDailyLevels(array $pair): array
    {
        $api = $this->requestAlphaVantage([
            'function' => 'FX_DAILY',
            'from_symbol' => (string) ($pair['alpha_from_symbol'] ?? self::FALLBACK_PAIR['alpha_from_symbol']),
            'to_symbol' => (string) ($pair['alpha_to_symbol'] ?? self::FALLBACK_PAIR['alpha_to_symbol']),
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
                'error' => sprintf('No daily %s levels from Alpha Vantage.', $pair['display']),
            ];
        }

        $dates = array_keys($series);
        rsort($dates);
        $targetDate = $dates[1] ?? $dates[0] ?? null;

        return [
            'high' => (float) ($series[$targetDate]['2. high'] ?? 0),
            'low' => (float) ($series[$targetDate]['3. low'] ?? 0),
            'date' => $targetDate,
            'source' => 'alphavantage_fx_daily_' . strtolower((string) $pair['code']),
            'error' => null,
        ];
    }

    /**
     * @param array<string,mixed> $pair
     */
    private function getYahooIntradayCandles(array $pair, string $interval, int $limit): array
    {
        $mappedInterval = match ($interval) {
            '1min' => '1m',
            '5min' => '5m',
            '15min' => '15m',
            '30min' => '30m',
            '60min' => '60m',
            default => '5m',
        };

        $api = $this->requestYahooChart(
            (string) ($pair['yahoo_symbol'] ?? self::FALLBACK_PAIR['yahoo_symbol']),
            $mappedInterval,
            '5d'
        );
        if ($api['error'] !== null) {
            return ['candles' => [], 'source' => null, 'error' => $api['error']];
        }

        $result = $api['data']['chart']['result'][0] ?? null;
        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];

        if (!is_array($timestamps) || $timestamps === [] || !is_array($quote)) {
            return [
                'candles' => [],
                'source' => null,
                'error' => sprintf('No intraday %s candles from Yahoo fallback.', $pair['display']),
            ];
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
            return [
                'candles' => [],
                'source' => null,
                'error' => sprintf('Yahoo fallback returned empty candle values for %s.', $pair['display']),
            ];
        }

        if ($limit > 0 && count($candles) > $limit) {
            $candles = array_slice($candles, -$limit);
        }

        return [
            'candles' => $candles,
            'source' => 'yahoo_fallback_' . strtolower((string) $pair['code']),
            'error' => null,
        ];
    }

    /**
     * @param array<string,mixed> $pair
     */
    private function getYahooDailyLevels(array $pair): array
    {
        $api = $this->requestYahooChart(
            (string) ($pair['yahoo_symbol'] ?? self::FALLBACK_PAIR['yahoo_symbol']),
            '1d',
            '1mo'
        );
        if ($api['error'] !== null) {
            return ['high' => 0.0, 'low' => 0.0, 'date' => null, 'source' => null, 'error' => $api['error']];
        }

        $result = $api['data']['chart']['result'][0] ?? null;
        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];

        if (!is_array($timestamps) || count($timestamps) < 1 || !is_array($quote)) {
            return [
                'high' => 0.0,
                'low' => 0.0,
                'date' => null,
                'source' => null,
                'error' => sprintf('No daily %s candles from Yahoo fallback.', $pair['display']),
            ];
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
            return [
                'high' => 0.0,
                'low' => 0.0,
                'date' => null,
                'source' => null,
                'error' => sprintf('Yahoo fallback daily candle values are empty for %s.', $pair['display']),
            ];
        }

        $target = $candles[count($candles) - 2] ?? end($candles);

        return [
            'high' => (float) $target['high'],
            'low' => (float) $target['low'],
            'date' => $target['date'],
            'source' => 'yahoo_fallback_' . strtolower((string) $pair['code']),
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

    private function ema(array $values, int $period): ?float
    {
        $series = $this->emaSeries($values, $period);
        if ($series === []) {
            return null;
        }

        return $series[count($series) - 1];
    }

    private function emaSeries(array $values, int $period): array
    {
        $count = count($values);
        if ($period <= 0 || $count < $period) {
            return [];
        }

        $series = array_fill(0, $count, null);
        $seed = array_sum(array_slice($values, 0, $period)) / $period;
        $series[$period - 1] = $seed;
        $multiplier = 2 / ($period + 1);

        for ($i = $period; $i < $count; $i++) {
            $series[$i] = (($values[$i] - $series[$i - 1]) * $multiplier) + $series[$i - 1];
        }

        return $series;
    }

    private function rsiSeries(array $closes, int $period): array
    {
        $count = count($closes);
        if ($period <= 0 || $count < ($period + 1)) {
            return [];
        }

        $series = array_fill(0, $count, null);

        for ($i = $period; $i < $count; $i++) {
            $window = array_slice($closes, $i - $period, $period + 1);
            $gains = 0.0;
            $losses = 0.0;

            for ($j = 1, $len = count($window); $j < $len; $j++) {
                $delta = $window[$j] - $window[$j - 1];
                if ($delta >= 0) {
                    $gains += $delta;
                } else {
                    $losses += abs($delta);
                }
            }

            $avgGain = $gains / $period;
            $avgLoss = $losses / $period;
            $series[$i] = $avgLoss == 0.0 ? 100.0 : (100 - (100 / (1 + ($avgGain / $avgLoss))));
        }

        return $series;
    }

    private function bollingerBands(array $values, int $period, float $deviations): array
    {
        $count = count($values);
        $upper = array_fill(0, $count, null);
        $middle = array_fill(0, $count, null);
        $lower = array_fill(0, $count, null);

        if ($period <= 1 || $count < $period) {
            return ['upper' => $upper, 'middle' => $middle, 'lower' => $lower];
        }

        for ($i = $period - 1; $i < $count; $i++) {
            $window = array_slice($values, $i - $period + 1, $period);
            $mean = array_sum($window) / $period;
            $std = $this->stddev($window, $mean);

            $middle[$i] = $mean;
            $upper[$i] = $mean + ($deviations * $std);
            $lower[$i] = $mean - ($deviations * $std);
        }

        return ['upper' => $upper, 'middle' => $middle, 'lower' => $lower];
    }

    private function stddev(array $values, float $mean): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($values as $value) {
            $sum += (($value - $mean) ** 2);
        }

        return sqrt($sum / $count);
    }

    private function adx(array $candles, int $period): ?float
    {
        $count = count($candles);
        if ($period <= 0 || $count < (($period * 2) + 1)) {
            return null;
        }

        $trs = [];
        $plusDms = [];
        $minusDms = [];

        for ($i = 1; $i < $count; $i++) {
            $curr = $candles[$i];
            $prev = $candles[$i - 1];

            $highDiff = (float) $curr['high'] - (float) $prev['high'];
            $lowDiff = (float) $prev['low'] - (float) $curr['low'];
            $plusDm = ($highDiff > $lowDiff && $highDiff > 0) ? $highDiff : 0.0;
            $minusDm = ($lowDiff > $highDiff && $lowDiff > 0) ? $lowDiff : 0.0;

            $tr = max(
                (float) $curr['high'] - (float) $curr['low'],
                abs((float) $curr['high'] - (float) $prev['close']),
                abs((float) $curr['low'] - (float) $prev['close'])
            );

            $trs[] = $tr;
            $plusDms[] = $plusDm;
            $minusDms[] = $minusDm;
        }

        if (count($trs) < ($period * 2)) {
            return null;
        }

        $trSmooth = array_sum(array_slice($trs, 0, $period));
        $plusSmooth = array_sum(array_slice($plusDms, 0, $period));
        $minusSmooth = array_sum(array_slice($minusDms, 0, $period));

        $dxs = [];
        for ($i = $period; $i < count($trs); $i++) {
            $trSmooth = $trSmooth - ($trSmooth / $period) + $trs[$i];
            $plusSmooth = $plusSmooth - ($plusSmooth / $period) + $plusDms[$i];
            $minusSmooth = $minusSmooth - ($minusSmooth / $period) + $minusDms[$i];

            if ($trSmooth <= 0) {
                continue;
            }

            $plusDi = 100 * ($plusSmooth / $trSmooth);
            $minusDi = 100 * ($minusSmooth / $trSmooth);
            $sumDi = $plusDi + $minusDi;
            $dx = $sumDi == 0.0 ? 0.0 : (100 * abs($plusDi - $minusDi) / $sumDi);
            $dxs[] = $dx;
        }

        if (count($dxs) < $period) {
            return null;
        }

        $adx = array_sum(array_slice($dxs, 0, $period)) / $period;
        for ($i = $period; $i < count($dxs); $i++) {
            $adx = (($adx * ($period - 1)) + $dxs[$i]) / $period;
        }

        return $adx;
    }

    private function detectBullishDivergence(array $closes, array $rsiSeries, int $lookback, int $rsiOverbought): bool
    {
        [$recentIdx, $olderIdx] = $this->findTwoSwingIndices($closes, $lookback, true);
        if ($recentIdx === null || $olderIdx === null) {
            return false;
        }

        $priceRecent = $closes[$recentIdx] ?? null;
        $priceOlder = $closes[$olderIdx] ?? null;
        $rsiRecent = $rsiSeries[$recentIdx] ?? null;
        $rsiOlder = $rsiSeries[$olderIdx] ?? null;

        if ($priceRecent === null || $priceOlder === null || $rsiRecent === null || $rsiOlder === null) {
            return false;
        }

        return ($priceOlder < $priceRecent) && ($rsiOlder > $rsiRecent) && ($rsiOlder < $rsiOverbought);
    }

    private function detectBearishDivergence(array $closes, array $rsiSeries, int $lookback, int $rsiOversold): bool
    {
        [$recentIdx, $olderIdx] = $this->findTwoSwingIndices($closes, $lookback, false);
        if ($recentIdx === null || $olderIdx === null) {
            return false;
        }

        $priceRecent = $closes[$recentIdx] ?? null;
        $priceOlder = $closes[$olderIdx] ?? null;
        $rsiRecent = $rsiSeries[$recentIdx] ?? null;
        $rsiOlder = $rsiSeries[$olderIdx] ?? null;

        if ($priceRecent === null || $priceOlder === null || $rsiRecent === null || $rsiOlder === null) {
            return false;
        }

        return ($priceOlder > $priceRecent) && ($rsiOlder < $rsiRecent) && ($rsiOlder > $rsiOversold);
    }

    private function findTwoSwingIndices(array $closes, int $lookback, bool $forLows): array
    {
        $count = count($closes);
        if ($count < 5) {
            return [null, null];
        }

        $maxShift = min($lookback, $count - 3);
        $found = [];

        for ($shift = 2; $shift <= $maxShift; $shift++) {
            $index = ($count - 1) - $shift;
            if ($index <= 0 || $index >= ($count - 1)) {
                continue;
            }

            $p0 = $closes[$index];
            $pOlder = $closes[$index - 1];
            $pNewer = $closes[$index + 1];

            $isSwing = $forLows
                ? ($p0 < $pOlder && $p0 < $pNewer)
                : ($p0 > $pOlder && $p0 > $pNewer);

            if ($isSwing) {
                $found[] = $index;
                if (count($found) === 2) {
                    return [$found[0], $found[1]];
                }
            }
        }

        return [null, null];
    }

    private function inferPipSize(float $price, ?float $pipSize = null): float
    {
        if ($pipSize !== null && $pipSize > 0) {
            return $pipSize;
        }

        if ($price >= 1000.0) {
            return 0.1;
        }

        if ($price >= 20.0) {
            return 0.01;
        }

        return 0.0001;
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

    /**
     * @return array<string,array<string,mixed>>
     */
    private function pairCatalog(): array
    {
        $pairs = config('trading.pairs', []);
        if (!is_array($pairs) || $pairs === []) {
            return [self::FALLBACK_PAIR_CODE => self::FALLBACK_PAIR];
        }

        $normalized = [];
        foreach ($pairs as $code => $pair) {
            if (!is_string($code) || !is_array($pair)) {
                continue;
            }

            $pairCode = strtoupper(preg_replace('/[^A-Z]/i', '', $code) ?? '');
            if ($pairCode === '' || strlen($pairCode) !== 6) {
                continue;
            }

            $normalized[$pairCode] = array_merge(self::FALLBACK_PAIR, $pair, ['code' => $pairCode]);
        }

        if ($normalized === []) {
            return [self::FALLBACK_PAIR_CODE => array_merge(self::FALLBACK_PAIR, ['code' => self::FALLBACK_PAIR_CODE])];
        }

        return $normalized;
    }

    private function defaultPairCode(): string
    {
        $pairs = $this->pairCatalog();
        $configuredDefault = strtoupper(preg_replace('/[^A-Z]/i', '', (string) config('trading.default_pair', self::FALLBACK_PAIR_CODE)) ?? '');

        if ($configuredDefault !== '' && array_key_exists($configuredDefault, $pairs)) {
            return $configuredDefault;
        }

        if (array_key_exists(self::FALLBACK_PAIR_CODE, $pairs)) {
            return self::FALLBACK_PAIR_CODE;
        }

        return array_key_first($pairs) ?? self::FALLBACK_PAIR_CODE;
    }

    /**
     * @return array<string,mixed>
     */
    private function resolvePair(?string $pairCode): array
    {
        $pairs = $this->pairCatalog();
        $code = $this->normalizePairCode($pairCode);
        $pair = $pairs[$code] ?? $pairs[$this->defaultPairCode()] ?? array_merge(self::FALLBACK_PAIR, ['code' => self::FALLBACK_PAIR_CODE]);

        return array_merge(self::FALLBACK_PAIR, $pair, ['code' => $code]);
    }

    /**
     * @param array<string,mixed> $pair
     */
    private function attachPairContext(array $signal, array $pair): array
    {
        $signal['symbol'] = (string) ($pair['display'] ?? self::FALLBACK_PAIR['display']);
        $signal['symbol_code'] = (string) ($pair['code'] ?? self::FALLBACK_PAIR_CODE);
        $signal['pair_name'] = (string) ($pair['name'] ?? self::FALLBACK_PAIR['name']);

        return $signal;
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
