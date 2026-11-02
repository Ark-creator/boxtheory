<?php

namespace App\Console\Commands;

use App\Models\AutoTraderSetting;
use App\Services\MarketDataGuard;
use App\Services\TradingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Evaluates current data only. No email, trade, catalog update or signal archive replay. */
class CurrentSignal extends Command
{
    private const RSI_STRATEGIES = [
        'rsi-moving-average', 'rsi-moving-averages', 'rsi-ma', 'rsi-ma-method',
        'rsi-scalper', 'rsi-gold-scalper', 'rsi-gold-scalper-v2',
    ];

    private const SUPPORTED_STRATEGIES = [
        'gold-trend-retest-v2', 'gold-risk-breakout', 'box-theory', 'box',
        'conservative-v2', 'conservative-v2-final', 'conservative',
        'rsi-moving-average', 'rsi-moving-averages', 'rsi-ma', 'rsi-ma-method', 'rsi-scalper', 'rsi-gold-scalper', 'rsi-gold-scalper-v2',
    ];

    protected $signature = 'signals:current {strategy=gold-trend-retest-v2 : Strategy slug} {--pair=XAUUSD : Configured market code} {--position= : Optional long or short position context}';

    protected $description = 'Read the current verified strategy decision and expiry without sending email or placing an order';

    public function handle(TradingService $trading, MarketDataGuard $guard): int
    {
        $position = $this->option('position');
        if ($position !== null && ! in_array($position, ['', 'long', 'short'], true)) {
            $this->error('Position must be empty, long, or short.');

            return self::FAILURE;
        }
        $requested = strtoupper(preg_replace('/[^A-Z]/i', '', (string) $this->option('pair')) ?? '');
        $pair = $trading->normalizePairCode($requested);
        if ($pair !== $requested) {
            $this->error('Unknown market code; no default market is silently substituted.');

            return self::FAILURE;
        }
        $strategy = strtolower(trim((string) $this->argument('strategy')));
        if (! in_array($strategy, self::SUPPORTED_STRATEGIES, true)) {
            $this->error('Unsupported strategy slug; select a known strategy explicitly.');

            return self::FAILURE;
        }
        $signal = $trading->getSignalForStrategy($strategy, $position ?: null, $pair);
        $now = now()->utc();
        $market = $guard->marketStatus($pair, $now);
        $reason = $guard->validateSignal($signal, $now);
        $researchOnly = in_array($strategy, ['gold-trend-retest-v2', 'rsi-gold-scalper-v2'], true) || ($signal['research_only'] ?? false) === true;
        $freshness = $signal['freshness'] ?? [];
        $expires = $signal['expires_at'] ?? null;
        $requiresMt5 = $pair === 'XAUUSD' && str_starts_with($strategy, 'rsi')
            && strtolower(trim((string) config('scalper.gold.source', 'twelvedata'))) === 'mt5';
        $quoteError = $requiresMt5 ? ($signal['error'] ?? $reason) : null;
        $sourceQuote = $reason === null ? ['source' => $freshness['source'] ?? null, 'price' => $signal['quote_price'] ?? null,
            'observed_at_utc' => $freshness['observed_at'] ?? null,
            'age_seconds' => isset($freshness['observed_at']) ? $now->timestamp - Carbon::parse($freshness['observed_at'])->timestamp : null] : null;
        if ($sourceQuote === null && $market['open'] && ! $requiresMt5) {
            // A fresh price is useful even when there is no valid strategy entry.
            // It never supplies or revives the rejected trade plan.
            $quote = $trading->getMarketData($pair);
            $quoteError = $quote['error'] ?? null;
            if (array_key_exists('error', $quote) && $quote['error'] === null && ! empty($quote['timestamp'])) {
                $age = now()->timestamp - Carbon::parse($quote['timestamp'])->timestamp;
                if ($age >= 0 && $age <= MarketDataGuard::PROVIDER_GRACE_SECONDS) {
                    $sourceQuote = ['source' => $quote['source'], 'price' => $quote['price'], 'observed_at_utc' => $quote['timestamp'], 'age_seconds' => $age];
                }
            }
        }
        // HTTP calls can cross a session boundary or the setup expiry. Report
        // the final check time and recheck both sources before exposing a plan.
        $now = now()->utc();
        $market = $guard->marketStatus($pair, $now);
        $reason = $guard->validateSignal($signal, $now);
        $action = strtoupper((string) ($signal['action'] ?? 'NO_DATA'));
        $canEnter = $reason === null && in_array($action, ['BUY', 'SELL'], true);
        $verified = $reason === null && $action !== 'NO_DATA';
        $state = ! $market['open'] ? 'MARKET_CLOSED' : (! $verified ? 'NO_VERIFIED_SETUP' : ($canEnter ? ($researchOnly ? 'RESEARCH_SETUP' : 'CURRENT_SETUP') : 'WAIT'));
        if ($sourceQuote !== null) {
            $sourceQuote['age_seconds'] = $now->timestamp - Carbon::parse($sourceQuote['observed_at_utc'])->timestamp;
            if (! $market['open'] || $sourceQuote['age_seconds'] < 0 || $sourceQuote['age_seconds'] > MarketDataGuard::PROVIDER_GRACE_SECONDS) {
                $sourceQuote = null;
            }
        }
        $demoConfigured = false;
        if ($requiresMt5 && in_array($strategy, self::RSI_STRATEGIES, true)
            && strtolower(trim((string) config('scalper.gold.version'))) === 'trend-session-v2') {
            // Read existing configuration only. A status command must never
            // create trader settings or infer that a polling process is alive.
            $settings = AutoTraderSetting::query()->find(1);
            $demoConfigured = $settings !== null && $settings->enabled && $settings->execution_mode === 'bridge'
                && $settings->pair_code === 'XAUUSD'
                && in_array(strtolower(trim((string) $settings->strategy_slug)), self::RSI_STRATEGIES, true);
        }
        $demoExecutionEnabled = $demoConfigured && $verified
            && ($signal['freshness']['source'] ?? null) === 'mt5'
            && ($signal['strategy'] ?? null) === 'rsi-gold-scalper-v2'
            && ($signal['strategy_version'] ?? null) === 2
            && ($signal['indicators']['candidate_filter'] ?? null) === 'trend-session'
            && ($signal['broker']['demo'] ?? null) === true;
        $result = [
            'state' => $state, 'action' => $canEnter ? $action : ($action === 'HOLD' && $reason === null ? 'HOLD' : 'WAIT'),
            'can_enter' => $canEnter && ! $researchOnly, 'paper_setup_available' => $canEnter && $researchOnly,
            'research_only' => $researchOnly, 'automatic_execution_enabled' => $demoExecutionEnabled,
            'demo_auto_trader_configured' => $demoConfigured, 'command_places_orders' => false,
            'live_execution_enabled' => false, 'worker_process_health' => 'not_checked',
            'checked_at_utc' => $now->toIso8601String(),
            'checked_at_manila' => $now->copy()->setTimezone('Asia/Manila')->toIso8601String(),
            'strategy' => $strategy, 'pair' => $pair,
            'message' => $action === 'NO_DATA' ? ($signal['message'] ?? 'Market data unavailable.') : ($reason ?? $signal['message'] ?? 'No confirmed entry.'),
            'source_quote' => $sourceQuote,
            'source_quote_error' => $sourceQuote === null ? $quoteError : null,
            'confirmed_candle_close_utc' => $reason === null ? ($freshness['data_as_of'] ?? null) : null,
            'expires_at_utc' => $canEnter ? $expires : null,
            'expires_at_manila' => $canEnter ? Carbon::parse($expires)->setTimezone('Asia/Manila')->format('Y-m-d H:i:s').' PHT (UTC+8)' : null,
            'trade_plan' => $canEnter ? ($signal['trade_plan'] ?? []) : [],
            'signal_id' => $canEnter ? ($signal['signal_id'] ?? null) : null,
            'emails_sent' => 0, 'orders_sent' => 0,
            'note' => 'Research decision, not a broker fill or a profitability claim. This command places no orders. Demo auto-trader configuration and verified account data do not establish worker process health. Recheck after expiry; unavailable data never becomes a BUY/SELL.',
        ];
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $market['open'] && ! $verified ? self::FAILURE : self::SUCCESS;
    }
}
