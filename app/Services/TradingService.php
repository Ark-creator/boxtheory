<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class TradingService
{
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
        try {
            $quote = app(LiveMarketDataService::class)->quote($pair);

            return [
                'price' => $quote['price'], 'timestamp' => $quote['observed_at'],
                'source' => 'twelvedata_'.strtolower($pair['code']), 'error' => null,
                'symbol' => $pair['display'], 'symbol_code' => $pair['code'],
            ];
        } catch (\Throwable $e) {
            return [
                'price' => 0.0,
                'timestamp' => null,
                'source' => null,
                'error' => $e->getMessage(),
                'symbol' => $pair['display'],
                'symbol_code' => $pair['code'],
            ];
        }
    }

    /**
     * Get previous daily high/low levels for a market pair.
     */
    public function getDailyLevels(?string $pairCode = null): array
    {
        $pair = $this->resolvePair($pairCode);
        try {
            return app(LiveMarketDataService::class)->dailyLevels($pair);
        } catch (\Throwable $e) {
            return [
                'high' => 0.0,
                'low' => 0.0,
                'date' => null,
                'source' => null,
                'error' => $e->getMessage(),
                'symbol' => $pair['display'],
                'symbol_code' => $pair['code'],
            ];
        }
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
        try {
            return app(LiveMarketDataService::class)->candles($pair, $interval, $limit);
        } catch (\Throwable $e) {
            return [
                'candles' => [],
                'source' => null,
                'error' => $e->getMessage(),
                'symbol' => $pair['display'],
                'symbol_code' => $pair['code'],
            ];
        }
    }

    /**
     * Build a signal output by strategy slug.
     */
    public function getSignalForStrategy(string $strategySlug, ?string $currentPosition = null, ?string $pairCode = null): array
    {
        $pair = $this->resolvePair($pairCode);
        $status = app(MarketDataGuard::class)->marketStatus($pair['code'], now()->utc());
        if (! $status['open']) {
            $signal = $this->makeNoDataSignal($strategySlug, $status['reason'], $currentPosition);
            $signal['market_status'] = 'closed';

            return $this->attachPairContext($signal, $pair);
        }
        try {
            return $this->getStrategySnapshot($strategySlug, $currentPosition, $pair['code']);
        } catch (\Throwable $e) {
            return $this->attachPairContext($this->makeNoDataSignal($strategySlug, $e->getMessage(), $currentPosition), $pair);
        }
    }

    private function getStrategySnapshot(string $strategySlug, ?string $currentPosition = null, ?string $pairCode = null): array
    {
        $slug = strtolower(trim($strategySlug));
        $pair = $this->resolvePair($pairCode);

        $isRetestV2 = $slug === 'gold-trend-retest-v2';
        if ($slug === GoldBreakoutStrategy::SLUG || $isRetestV2) {
            try {
                if ($pair['code'] !== 'XAUUSD') {
                    throw new \RuntimeException('This gold strategy supports XAUUSD only.');
                }
                $engine = app($isRetestV2 ? GoldTrendRetestV2Strategy::class : GoldBreakoutStrategy::class);
                // V2 API alerts are deliberately separate from the broker execution allowlist.
                $source = $isRetestV2 ? 'twelvedata' : strtolower(trim((string) config('gold_breakout.signal_source', 'mt5')));
                if ($source === 'twelvedata') {
                    $count = $isRetestV2 ? 320 : 300;
                    $m15 = $this->getGoldApiCandles($pair, '15min', $count);
                    $h1 = $this->getGoldApiCandles($pair, '60min', $count);
                    $signal = $engine->evaluate(
                        $m15, $h1, now()->utc(), $currentPosition, 'Twelve Data XAU/USD'
                    );
                    if ($isRetestV2) {
                        $signal['research_only'] = true;
                    }
                    if (($signal['action'] ?? 'NO_DATA') === 'NO_DATA') {
                        return $this->attachPairContext($signal, $pair);
                    }

                    return $this->finishLiveSignal($signal, $pair, $m15, 900, app(LiveMarketDataService::class)->quote($pair));
                }
                if ($source !== 'mt5') {
                    throw new \RuntimeException('GOLD_SIGNAL_SOURCE must be mt5 or twelvedata.');
                }
                $snapshot = app(Mt5BridgeClient::class)->snapshot();
                if (! preg_match('/^XAU[\/_-]?USD(?:[._#-]?[A-Za-z0-9]+)?$/i', $snapshot['symbol'] ?? '')) {
                    throw new \RuntimeException('The MT5 snapshot is not an XAUUSD gold instrument.');
                }
                $guard = app(MarketDataGuard::class);
                $m15 = $guard->validateCandles($snapshot['m15'], 900, now()->utc());
                $h1 = $guard->validateCandles($snapshot['h1'], 3600, now()->utc());
                $signal = $engine->evaluate(
                    $m15, $h1, now()->utc(), $currentPosition
                );
                $signal['broker'] = ['account_key' => $snapshot['account_key'], 'symbol' => $snapshot['symbol']];

                return $this->finishLiveSignal($signal, $pair, $m15, 900, $this->mt5Quote($snapshot));
            } catch (\Throwable $e) {
                return $this->attachPairContext($this->makeNoDataSignal($slug, $e->getMessage(), $currentPosition), $pair);
            }
        }

        if (in_array($slug, ['box-theory', 'box'], true)) {
            $data = $this->getCandles($pair['code']);
            if ($data['error'] !== null) {
                throw new \RuntimeException($data['error']);
            }
            $quote = app(LiveMarketDataService::class)->quote($pair);
            $levels = $this->getDailyLevels($pair['code']);
            $signal = $this->evaluateBoxTheory(['price' => $quote['price'],
                'timestamp' => $data['candles'][array_key_last($data['candles'])]['timestamp'],
                'source' => $data['source'], 'error' => null], $levels);

            return $this->finishLiveSignal($signal, $pair, $data['candles'], 300, $quote);
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

            $signal = $this->evaluateConservativeV2(
                $ltf['candles'],
                $htf['candles'],
                $currentPosition,
                $ltf['source'] ?? null,
                isset($pair['pip_size']) ? (float) $pair['pip_size'] : null
            );

            return $this->finishLiveSignal($signal, $pair, $ltf['candles'], 300, app(LiveMarketDataService::class)->quote($pair));
        }

        if (
            in_array($slug, ['rsi-moving-average', 'rsi-moving-averages', 'rsi-ma', 'rsi-ma-method', 'rsi-scalper', 'rsi-gold-scalper', RsiGoldScalperV2Strategy::SLUG], true) ||
            str_contains($slug, 'rsi')
        ) {
            $version = strtolower(trim((string) config('scalper.gold.version', 'trend-session-v2')));
            if ($pair['code'] === 'XAUUSD' && ! in_array($version, ['legacy', 'trend-v2', 'trend-session-v2'], true)) {
                throw new \RuntimeException('RSI_GOLD_SCALPER_VERSION must be legacy, trend-v2 or trend-session-v2.');
            }
            if ($slug === RsiGoldScalperV2Strategy::SLUG && $pair['code'] !== 'XAUUSD') {
                throw new \RuntimeException('RSI Gold Scalper V2 supports XAUUSD only.');
            }
            $useGoldV2 = $pair['code'] === 'XAUUSD' && ($version !== 'legacy' || $slug === RsiGoldScalperV2Strategy::SLUG);
            $source = $pair['code'] === 'XAUUSD' ? strtolower(trim((string) config('scalper.gold.source', 'twelvedata'))) : 'twelvedata';
            if (! in_array($source, ['mt5', 'twelvedata'], true)) {
                throw new \RuntimeException('RSI_GOLD_SCALPER_SOURCE must be mt5 or twelvedata.');
            }
            if ($source === 'mt5') {
                $snapshot = app(Mt5BridgeClient::class)->snapshot(null, ['m5_candles', 'quote_timestamp']);

                return $this->evaluateRsiMt5Snapshot($snapshot, $currentPosition,
                    $useGoldV2 && $version === 'legacy' ? 'trend-v2' : $version);
            }
            $payload = $this->getCandles($pair['code'], '5min', $useGoldV2 ? 250 : 200);
            if ($payload['error'] !== null) {
                return $this->attachPairContext(
                    $this->makeNoDataSignal($slug, $payload['error'], $currentPosition),
                    $pair
                );
            }

            $signal = $useGoldV2
                ? (new RsiGoldScalperV2Strategy($this, app(MarketDataGuard::class)))->evaluate(
                    $payload['candles'], $currentPosition, $payload['source'] ?? null, now()->utc(), $version === 'trend-session-v2'
                )
                : $this->evaluateRsiMovingAverage($payload['candles'], $currentPosition, [], $payload['source'] ?? null);
            if (($signal['action'] ?? 'NO_DATA') === 'NO_DATA') {
                return $this->attachPairContext($signal, $pair);
            }

            return $this->finishLiveSignal($signal, $pair, $payload['candles'], 300, app(LiveMarketDataService::class)->quote($pair));
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

    /** Evaluate one broker snapshot without making a second data request. */
    public function evaluateRsiMt5Snapshot(array $snapshot, ?string $position = null, ?string $version = null): array
    {
        $pair = $this->resolvePair('XAUUSD');
        try {
            $version ??= strtolower(trim((string) config('scalper.gold.version', 'trend-session-v2')));
            if (! in_array($version, ['legacy', 'trend-v2', 'trend-session-v2'], true)) {
                throw new \RuntimeException('RSI_GOLD_SCALPER_VERSION must be legacy, trend-v2 or trend-session-v2.');
            }
            if (($snapshot['status'] ?? null) !== 'ok' || ($snapshot['source'] ?? null) !== 'mt5' || empty($snapshot['account_key'])) {
                throw new \RuntimeException('Invalid MT5 snapshot.');
            }
            if (! preg_match('/^XAU[\/_-]?USD(?:[._#-]?[A-Za-z0-9]+)?$/i', $snapshot['symbol'] ?? '')) {
                throw new \RuntimeException('The MT5 snapshot is not an XAUUSD gold instrument.');
            }
            if (! is_array($snapshot['m5'] ?? null)) {
                throw new \RuntimeException('Update the MT5 bridge: completed M5 candles are required for RSI signals.');
            }
            $guard = app(MarketDataGuard::class);
            $market = $guard->marketStatus('XAUUSD', now()->utc());
            if (! $market['open']) {
                throw new \RuntimeException($market['reason']);
            }
            $closed = $guard->validateCandles($snapshot['m5'], 300, now()->utc());
            $closed = array_values(array_filter($closed, static function ($bar) use ($guard): bool {
                $open = Carbon::parse($bar['timestamp'])->utc();

                return $guard->marketStatus('XAUUSD', $open)['open']
                    && $guard->marketStatus('XAUUSD', $open->copy()->addSeconds(299))['open'];
            }));
            $closed = $guard->validateCandles($closed, 300, now()->utc());
            $useV2 = $version !== 'legacy';
            $candles = array_slice($closed, -($useV2 ? 250 : 200));
            $source = 'MT5 '.$snapshot['symbol'];
            $signal = $useV2
                ? (new RsiGoldScalperV2Strategy($this, $guard))->evaluate($candles, $position, $source, now()->utc(), $version === 'trend-session-v2')
                : $this->evaluateRsiMovingAverage($candles, $position, [], $source);
            $signal['broker'] = ['account_key' => $snapshot['account_key'], 'symbol' => $snapshot['symbol']];
            if (is_bool($snapshot['account']['demo'] ?? null)) {
                $signal['broker']['demo'] = $snapshot['account']['demo'];
            }
            if (is_array($snapshot['clock'] ?? null)) {
                $signal['broker']['clock'] = $snapshot['clock'];
            }

            return $this->finishLiveSignal($signal, $pair, $candles, 300, $this->mt5Quote($snapshot));
        } catch (\Throwable $exception) {
            return $this->attachPairContext($this->makeNoDataSignal('rsi-gold-scalper-v2', $exception->getMessage(), $position), $pair);
        }
    }

    /** A snapshot generation timestamp never replaces the broker's actual tick time. */
    private function mt5Quote(array $snapshot): array
    {
        $tick = $snapshot['quote'] ?? [];
        foreach (['bid', 'ask'] as $field) {
            if (! isset($tick[$field]) || ! is_numeric($tick[$field]) || ! is_finite((float) $tick[$field]) || $tick[$field] <= 0) {
                throw new \RuntimeException('Update the MT5 bridge: a current, timestamped bid/ask quote is required for signals.');
            }
        }
        if ($tick['ask'] < $tick['bid'] || empty($tick['timestamp'])) {
            throw new \RuntimeException('MT5 quote is invalid or has no source timestamp.');
        }

        return ['price' => (float) $tick['bid'], 'bid' => (float) $tick['bid'], 'ask' => (float) $tick['ask'],
            'observed_at' => $tick['timestamp'], 'market_open' => true, 'source' => 'mt5'];
    }

    /**
     * @return array<string,mixed>
     */
    private function finishLiveSignal(array $signal, array $pair, array $candles, int $seconds, array $quote): array
    {
        if (($signal['action'] ?? 'NO_DATA') === 'NO_DATA') {
            return $this->attachPairContext($signal, $pair);
        }
        $last = $candles[array_key_last($candles)] ?? throw new \RuntimeException('No confirmed candle available.');
        $opened = Carbon::parse($last['timestamp'], 'UTC')->utc();
        $closed = $opened->copy()->addSeconds($seconds);
        if (! is_string($signal['timestamp'] ?? null)
            || ! in_array($signal['timestamp'], [$opened->toIso8601String(), $closed->toIso8601String()], true)) {
            throw new \RuntimeException('The evaluated signal does not belong to its confirmed candle; no timestamp is relabeled.');
        }
        $generated = now()->utc();
        $expiry = $closed->copy()->addSeconds(180);
        if (! empty($signal['expires_at'])) {
            $expiry = $expiry->min(Carbon::parse($signal['expires_at'], 'UTC')->utc());
        }
        $action = strtoupper((string) $signal['action']);
        $quotePrice = $action === 'BUY' ? ($quote['ask'] ?? $quote['price']) : ($quote['bid'] ?? $quote['price']);
        $signal['timestamp'] = $closed->toIso8601String();
        $signal['generated_at'] = $generated->toIso8601String();
        $signal['expires_at'] = $expiry->toIso8601String();
        $signal['quote_price'] = (float) $quotePrice;
        $signal['freshness'] = [
            'verified' => true, 'source' => $quote['source'], 'interval_seconds' => $seconds,
            'candle_opened_at' => $opened->toIso8601String(), 'data_as_of' => $closed->toIso8601String(),
            'observed_at' => $quote['observed_at'], 'generated_at' => $generated->toIso8601String(),
            'market_open' => $quote['market_open'], 'quote_price' => (float) $quotePrice,
        ];
        $signal['signal_id'] = hash('sha256', implode('|', [$signal['strategy'], $pair['code'], $quote['source'], $signal['timestamp'], $action]));
        $signal = $this->attachPairContext($signal, $pair);
        $reason = app(MarketDataGuard::class)->validateSignal($signal, now()->utc());
        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }
        if (in_array($action, ['BUY', 'SELL'], true)) {
            $plan = $signal['trade_plan'] ?? [];
            foreach (['entry_price', 'stop_loss', 'take_profit_1'] as $field) {
                if (! isset($plan[$field]) || ! is_numeric($plan[$field]) || ! is_finite((float) $plan[$field]) || $plan[$field] <= 0) {
                    throw new \RuntimeException('Signal has no valid current entry, stop and target.');
                }
            }
            $side = $action === 'BUY' ? 1 : -1;
            $risk = ((float) $plan['entry_price'] - (float) $plan['stop_loss']) * $side;
            if ($risk <= 0 || ($quotePrice - $plan['stop_loss']) * $side <= 0 || ($plan['take_profit_1'] - $quotePrice) * $side <= 0
                || abs($quotePrice - $plan['entry_price']) > 0.1 * $risk) {
                throw new \RuntimeException('Current quote has moved beyond the trade plan or more than 0.1R from entry; skip this old setup.');
            }
            if ((isset($plan['entry_min_price']) && $quotePrice < $plan['entry_min_price'])
                || (isset($plan['entry_max_price']) && $quotePrice > $plan['entry_max_price'])) {
                throw new \RuntimeException('Current quote is outside the confirmed retest entry bounds; do not chase this setup.');
            }
            if (isset($plan['entry_limit_price']) && ($quotePrice - $plan['entry_limit_price']) * $side > 0) {
                throw new \RuntimeException('Current quote exceeds the breakout extension limit; do not chase this setup.');
            }
        }

        return $signal;
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
    ): array {
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
     * Cache completed spot-gold bars until the next close. Never substitute futures
     * or combine different providers for the two timeframes used by this strategy.
     */
    private function getGoldApiCandles(array $pair, string $interval, int $count = 300): array
    {
        return app(LiveMarketDataService::class)->candles($pair, $interval, $count)['candles'];
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
        if (! is_array($pairs) || $pairs === []) {
            return [self::FALLBACK_PAIR_CODE => self::FALLBACK_PAIR];
        }

        $normalized = [];
        foreach ($pairs as $code => $pair) {
            if (! is_string($code) || ! is_array($pair)) {
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
     * @param  array<string,mixed>  $pair
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
