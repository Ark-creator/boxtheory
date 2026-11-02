<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/** Symmetric trend qualification of the existing RSI/SMA crossover; research, not a return guarantee. */
class RsiGoldScalperV2Strategy
{
    public const SLUG = 'rsi-gold-scalper-v2';

    public const REQUIRED_BARS = 203;

    public const TREND_PERIOD = 200;

    public const SLOPE_BARS = 3;

    public function __construct(
        private readonly TradingService $legacy = new TradingService,
        private readonly MarketDataGuard $guard = new MarketDataGuard,
    ) {}

    public function evaluate(array $candles, ?string $position = null, ?string $source = null, ?Carbon $asOf = null, bool $restrictEntrySession = false): array
    {
        $asOf ??= now()->utc();
        try {
            $closed = $this->guard->validateCandles($candles, 300, $asOf);
            $closed = array_values(array_filter($closed, function ($bar) {
                $at = Carbon::parse($bar['timestamp'])->utc();

                return $this->guard->marketStatus('XAUUSD', $at)['open']
                    && $this->guard->marketStatus('XAUUSD', $at->copy()->addSeconds(299))['open'];
            }));
            $closed = $this->guard->validateCandles($closed, 300, $asOf);
            $window = array_slice($closed, -self::REQUIRED_BARS);
            $signal = $this->legacy->evaluateRsiMovingAverage($window, $position, [], $source);
        } catch (\Throwable $exception) {
            return $this->unavailable($exception->getMessage(), $source);
        }
        $signal['strategy'] = self::SLUG;
        $signal['research_only'] = true;
        $signal['strategy_version'] = 2;
        $signal['indicators']['trend_filter'] = 'SMA200 price alignment and three-bar SMA200 slope';
        $signal['indicators']['trend_period'] = self::TREND_PERIOD;
        $signal['indicators']['trend_slope_bars'] = self::SLOPE_BARS;
        $signal['indicators']['entry_session'] = $restrictEntrySession ? '07:00-17:00 UTC weekdays' : 'Regular gold market session';
        $signal['indicators']['candidate_filter'] = $restrictEntrySession ? 'trend-session' : 'trend';
        $signal['indicators']['exit_policy'] = 'full_tp1';
        $signal['indicators']['performance_status'] = 'Development-tested; forward profitability is unverified';

        // Known-position exits do not wait for the entry trend filter or its extra history.
        if (in_array($signal['action'], ['NO_DATA', 'CLOSE'], true)) {
            return $signal;
        }
        if (count($window) < self::REQUIRED_BARS) {
            return $this->unavailable('RSI Gold Scalper V2 requires 203 completed trading-session M5 bars for its trend filter.', $source);
        }
        $prices = array_column($window, 'close');
        // Canonical precision is fixed in the research protocol, before candidate returns.
        $trendNow = round(array_sum(array_slice($prices, -self::TREND_PERIOD)) / self::TREND_PERIOD, 8);
        $trendPrior = round(array_sum(array_slice($prices, -self::TREND_PERIOD - self::SLOPE_BARS, self::TREND_PERIOD)) / self::TREND_PERIOD, 8);
        $price = (float) $prices[array_key_last($prices)];
        $up = $price > $trendNow && $trendNow > $trendPrior;
        $down = $price < $trendNow && $trendNow < $trendPrior;
        $signal['indicators'] += ['trend_sma_200' => $trendNow, 'trend_sma_200_prior' => $trendPrior,
            'trend_slope' => round($trendNow - $trendPrior, 8), 'trend_up_confirmed' => $up, 'trend_down_confirmed' => $down];
        $signal['trend'] = $up ? 'bullish' : ($down ? 'bearish' : 'flat');
        $decision = Carbon::parse($window[array_key_last($window)]['timestamp'])->utc()->addMinutes(5);
        $sessionAllowed = ! $decision->isWeekend() && $decision->hour >= 7 && $decision->hour < 17;
        $signal['indicators']['entry_session_allowed'] = ! $restrictEntrySession || $sessionAllowed;

        if (in_array($signal['action'], ['BUY', 'SELL'], true)) {
            $action = $signal['action'];
            if ($restrictEntrySession && ! $sessionAllowed) {
                return $this->wait($signal, 'Wait for the configured 07:00-17:00 UTC weekday entry window.');
            }
            if (in_array(strtolower(trim((string) $position)), ['long', 'short', 'buy', 'sell'], true)) {
                return $this->wait($signal, 'A position is already open; manage its original stop/targets or wait for a position-aware CLOSE.');
            }
            if (($action === 'BUY' && ! $up) || ($action === 'SELL' && ! $down)) {
                $signal['indicators']['rejected_crossover'] = $action;

                return $this->wait($signal, 'RSI crossover rejected: price and the SMA200 slope do not agree with its direction.');
            }
            $signal['message'] = $action === 'BUY'
                ? 'Confirmed M5 bullish SMA9/21 crossover and RSI momentum, with price above a rising SMA200.'
                : 'Confirmed M5 bearish SMA9/21 crossover and RSI momentum, with price below a falling SMA200.';
            $signal['trade_plan']['take_profit_2'] = null;
            $signal['trade_plan']['rr_tp2'] = null;
            $signal['trade_plan']['exit_policy'] = 'full_tp1';
            $signal['trade_plan']['notes'] .= ' V2 requires the symmetric SMA200 trend filter and uses a full exit at TP1 (1.5R) or the original stop. TP2 is not part of this revised plan. No fixed holding-time exit is automated.';
        }

        return $signal;
    }

    private function wait(array $signal, string $reason): array
    {
        $signal['action'] = 'HOLD';
        $signal['message'] = $reason;
        $signal['trade_plan'] = ['side' => 'wait', 'entry_price' => null, 'stop_loss' => null,
            'take_profit_1' => null, 'take_profit_2' => null, 'risk_per_unit' => null,
            'rr_tp1' => null, 'rr_tp2' => null, 'notes' => $reason];

        return $signal;
    }

    private function unavailable(string $reason, ?string $source): array
    {
        return ['strategy' => self::SLUG, 'action' => 'NO_DATA', 'price' => 0.0, 'timestamp' => null,
            'trend' => 'unknown', 'message' => $reason, 'error' => $reason, 'trade_plan' => [],
            'research_only' => true, 'strategy_version' => 2, 'indicators' => ['data_source' => $source]];
    }
}
