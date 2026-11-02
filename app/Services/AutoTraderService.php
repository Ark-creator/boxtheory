<?php

namespace App\Services;

use App\Models\AutoTradeOrder;
use App\Models\AutoTraderSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AutoTraderService
{
    private const ACTIONABLE = ['BUY', 'SELL', 'CLOSE'];

    private const ACTIVE_STATUSES = ['dry_run', 'pending', 'unknown', 'sent', 'accepted', 'executed', 'partial'];

    private const RSI_ALIASES = ['rsi-moving-average', 'rsi-moving-averages', 'rsi-ma', 'rsi-ma-method',
        'rsi-scalper', 'rsi-gold-scalper', RsiGoldScalperV2Strategy::SLUG];

    private const RSI_DEMO_CAPABILITIES = ['m5_candles', 'quote_timestamp', 'rsi_scalper_v2_demo_execution'];

    private ?array $brokerSnapshot = null;

    public function __construct(private readonly TradingService $tradingService) {}

    public function settings(): AutoTraderSetting
    {
        return AutoTraderSetting::query()->firstOrCreate(
            ['id' => 1],
            $this->defaultSettings()
        );
    }

    public function updateSettings(array $data): AutoTraderSetting
    {
        $settings = $this->settings();
        $accountType = $this->normalizeAccountType($data['account_type'] ?? $settings->account_type);
        $symbol = trim((string) ($data['mt5_symbol'] ?? ''));

        if ($symbol === '') {
            $symbol = $this->symbolForAccountType($accountType);
        }

        $settings->fill([
            'enabled' => (bool) ($data['enabled'] ?? false),
            'execution_mode' => $this->normalizeExecutionMode($data['execution_mode'] ?? $settings->execution_mode),
            'account_type' => $accountType,
            'pair_code' => $this->tradingService->normalizePairCode($data['pair_code'] ?? 'XAUUSD'),
            'mt5_symbol' => $symbol,
            'strategy_slug' => trim((string) ($data['strategy_slug'] ?? $settings->strategy_slug)),
            'lot_size' => max(0.01, (float) ($data['lot_size'] ?? $settings->lot_size)),
            'risk_percent_per_trade' => $this->nullableFloat($data['risk_percent_per_trade'] ?? null) ?? 0.25,
            'max_trades_per_day' => max(1, (int) ($data['max_trades_per_day'] ?? $settings->max_trades_per_day)),
            'max_open_trades' => max(1, (int) ($data['max_open_trades'] ?? $settings->max_open_trades)),
            'cooldown_minutes' => max(0, (int) ($data['cooldown_minutes'] ?? $settings->cooldown_minutes)),
            'max_daily_loss' => $this->nullableFloat($data['max_daily_loss'] ?? null),
            'allow_buy' => (bool) ($data['allow_buy'] ?? false),
            'allow_sell' => (bool) ($data['allow_sell'] ?? false),
            'news_filter_enabled' => (bool) ($data['news_filter_enabled'] ?? false),
            'bridge_url' => $this->nullableString($data['bridge_url'] ?? null),
        ]);

        if (array_key_exists('bridge_token', $data) && trim((string) $data['bridge_token']) !== '') {
            $settings->bridge_token = trim((string) $data['bridge_token']);
        }

        $settings->save();

        return $settings;
    }

    public function dashboard(): array
    {
        $settings = $this->settings();
        $positionKnown = $settings->execution_mode !== 'bridge';
        if ($settings->execution_mode === 'bridge') {
            try {
                $this->brokerSnapshot = app(Mt5BridgeClient::class)->snapshot($settings, $this->snapshotCapabilities($settings));
                $positionKnown = true;
            } catch (\Throwable) {
                $this->brokerSnapshot = null;
            }
        }
        $position = $this->currentPositionContext($settings);

        return [
            'position_known' => $positionKnown,
            'settings' => $settings,
            'position' => $position,
            'latest_signal' => $this->latestSignal($settings, $position),
            'orders' => AutoTradeOrder::query()->latest()->limit(25)->get(),
            'today_count' => $this->todaysActionableCount($settings),
            'last_order' => AutoTradeOrder::query()->latest()->first(),
        ];
    }

    public function latestSignal(AutoTraderSetting $settings, ?string $position = null): array
    {
        if ($settings->execution_mode === 'bridge' && $this->isRsiStrategy($settings)) {
            $reason = $this->rsiConfigurationRejection($settings) ?? $this->rsiSnapshotRejection($settings);
            if ($reason !== null) {
                return $this->unavailableRsiSignal($reason);
            }
            try {
                // Broker SL/full TP1 manage an existing position. Never request the
                // legacy position-aware RSI momentum CLOSE or fetch another feed.
                return $this->tradingService->evaluateRsiMt5Snapshot($this->brokerSnapshot, null, 'trend-session-v2');
            } catch (\Throwable $exception) {
                return $this->unavailableRsiSignal($exception->getMessage());
            }
        }
        if ($settings->strategy_slug === GoldBreakoutStrategy::SLUG && $this->brokerSnapshot !== null) {
            $signal = app(GoldBreakoutStrategy::class)->evaluate($this->brokerSnapshot['m15'], $this->brokerSnapshot['h1'], now()->utc(), $position);
            $signal['broker'] = ['account_key' => $this->brokerSnapshot['account_key'], 'symbol' => $this->brokerSnapshot['symbol']];

            return $signal + ['symbol' => 'XAU/USD', 'symbol_code' => 'XAUUSD'];
        }

        return $this->tradingService->getSignalForStrategy(
            (string) $settings->strategy_slug,
            $position ?? $this->currentPositionContext($settings),
            (string) $settings->pair_code
        );
    }

    public function tick(bool $manual = false): array
    {
        $lock = Cache::lock('auto-trader:execution', 120);
        if (! $lock->get()) {
            return ['status' => 'skipped', 'message' => 'Another auto-trader run is active.', 'order' => null, 'signal' => null];
        }
        try {
            $this->brokerSnapshot = null;

            return $this->runTick();
        } finally {
            $this->brokerSnapshot = null;
            $lock->release();
        }
    }

    private function runTick(): array
    {
        $settings = $this->settings();
        $settings->forceFill(['last_run_at' => now()])->save();

        if (! $settings->enabled) {
            return [
                'status' => 'disabled',
                'message' => 'Auto trader is disabled.',
                'order' => null,
                'signal' => null,
            ];
        }

        if ($settings->execution_mode === 'bridge') {
            $rsi = $this->isRsiStrategy($settings);
            if (($settings->strategy_slug !== GoldBreakoutStrategy::SLUG && ! $rsi) || $settings->pair_code !== 'XAUUSD') {
                return ['status' => 'rejected', 'message' => 'MT5 execution requires an allowed XAUUSD strategy.', 'order' => null, 'signal' => null];
            }
            if ($rsi && ($reason = $this->rsiConfigurationRejection($settings)) !== null) {
                return ['status' => 'rejected', 'message' => $reason, 'order' => null, 'signal' => null];
            }
            if ($rsi && ! app(MarketDataGuard::class)->marketStatus('XAUUSD', now()->utc())['open']) {
                return ['status' => 'rejected', 'message' => 'Gold market is closed; no demo entry is allowed.', 'order' => null, 'signal' => null];
            }
            try {
                $this->brokerSnapshot = app(Mt5BridgeClient::class)->snapshot($settings, $this->snapshotCapabilities($settings));
            } catch (\Throwable $e) {
                return ['status' => 'rejected', 'message' => $e->getMessage(), 'order' => null, 'signal' => null];
            }
            if ($rsi && ($reason = $this->rsiSnapshotRejection($settings)) !== null) {
                return ['status' => 'rejected', 'message' => $reason, 'order' => null, 'signal' => null];
            }
            $this->reconcile($settings);
            if (AutoTradeOrder::query()->where('execution_mode', 'bridge')->whereIn('status', ['pending', 'unknown', 'sent', 'accepted', 'partial'])->exists()) {
                return ['status' => 'rejected', 'message' => 'An unresolved bridge order requires reconciliation before further trading.', 'order' => null, 'signal' => null];
            }
            if ($rsi && (! empty($this->brokerSnapshot['positions']) || ! empty($this->brokerSnapshot['pending_orders']))) {
                return ['status' => 'rejected', 'message' => 'An existing broker position or pending order blocks RSI entry; its fixed SL/TP remains in control.', 'order' => null, 'signal' => null];
            }
        }

        $position = $this->currentPositionContext($settings);
        $signal = $this->latestSignal($settings, $position);
        $action = strtoupper((string) ($signal['action'] ?? 'NO_DATA'));
        if ($settings->execution_mode === 'bridge' && $this->isRsiStrategy($settings)
            && ($reason = $this->rsiSignalRejection($settings, $signal)) !== null) {
            return ['status' => 'rejected', 'message' => $reason, 'order' => null, 'signal' => $signal];
        }

        if (! in_array($action, self::ACTIONABLE, true)) {
            return [
                'status' => 'skipped',
                'message' => sprintf('Signal action is %s, so no order was prepared.', $action),
                'order' => null,
                'signal' => $signal,
            ];
        }

        $riskCheck = $this->riskCheck($settings, $action);
        if ($riskCheck !== null) {
            $order = $this->recordOrder($settings, $signal, $position, $action, 'rejected', $riskCheck);

            return [
                'status' => 'rejected',
                'message' => $riskCheck,
                'order' => $order,
                'signal' => $signal,
            ];
        }

        $signature = $this->buildSignalSignature($settings, $signal, $position);
        $duplicate = AutoTradeOrder::query()
            ->where('signal_signature', $signature)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->latest()
            ->first();

        if ($duplicate !== null) {
            return [
                'status' => 'duplicate',
                'message' => 'This exact signal has already been handled.',
                'order' => $duplicate,
                'signal' => $signal,
            ];
        }

        $order = $this->recordOrder($settings, $signal, $position, $action, 'pending', null, $signature);

        if ($settings->execution_mode === 'bridge') {
            return $this->sendToBridge($settings, $order, $signal);
        }

        $order->forceFill([
            'status' => 'dry_run',
            'placed_at' => now(),
            'bridge_response' => [
                'mode' => 'dry_run',
                'message' => 'Trade intent logged only. No MT5 order was sent.',
            ],
        ])->save();

        return [
            'status' => 'dry_run',
            'message' => 'Trade intent logged in dry-run mode.',
            'order' => $order->fresh(),
            'signal' => $signal,
        ];
    }

    public function symbolForAccountType(string $accountType): string
    {
        $symbols = config('trading.auto_trader.symbols', []);
        $accountType = $this->normalizeAccountType($accountType);

        return (string) ($symbols[$accountType] ?? 'XAUUSD');
    }

    private function isRsiStrategy(AutoTraderSetting $settings): bool
    {
        return in_array(strtolower(trim((string) $settings->strategy_slug)), self::RSI_ALIASES, true);
    }

    private function snapshotCapabilities(AutoTraderSetting $settings): array
    {
        return $this->isRsiStrategy($settings) ? self::RSI_DEMO_CAPABILITIES : ['entry_extension_limit'];
    }

    private function rsiConfigurationRejection(AutoTraderSetting $settings): ?string
    {
        if ($settings->pair_code !== 'XAUUSD') {
            return 'RSI demo execution supports XAUUSD only.';
        }
        if (strtolower(trim((string) config('scalper.gold.source', 'twelvedata'))) !== 'mt5') {
            return 'RSI demo execution requires the MT5 data source; API signals cannot place orders.';
        }
        if (strtolower(trim((string) config('scalper.gold.version', 'trend-session-v2'))) !== 'trend-session-v2') {
            return 'RSI demo execution requires the trend-session-v2 version; legacy and trend-only execution are disabled.';
        }

        return null;
    }

    private function rsiSnapshotRejection(AutoTraderSetting $settings): ?string
    {
        $snapshot = $this->brokerSnapshot;
        if ($snapshot === null || ($snapshot['source'] ?? null) !== 'mt5') {
            return 'A verified MT5 snapshot is required for RSI demo execution.';
        }
        if (($snapshot['account']['demo'] ?? null) !== true) {
            return 'RSI execution is DEMO ONLY; live or unverified accounts are never allowed.';
        }
        if (! is_string($snapshot['account_key'] ?? null) || $snapshot['account_key'] === ''
            || ($snapshot['symbol'] ?? null) !== $settings->mt5_symbol) {
            return 'RSI snapshot account or symbol does not match the configured broker identity.';
        }
        if (! is_array($snapshot['positions'] ?? null) || ! is_array($snapshot['pending_orders'] ?? null)) {
            return 'RSI demo execution requires verified broker positions and pending orders.';
        }
        $capabilities = is_array($snapshot['capabilities'] ?? null) ? $snapshot['capabilities'] : [];
        foreach (self::RSI_DEMO_CAPABILITIES as $capability) {
            if (! in_array($capability, $capabilities, true)) {
                return 'Update the MT5 bridge: RSI demo execution requires capability '.$capability.'.';
            }
        }

        return null;
    }

    private function rsiSignalRejection(AutoTraderSetting $settings, array $signal): ?string
    {
        $reason = $this->rsiConfigurationRejection($settings) ?? $this->rsiSnapshotRejection($settings);
        if ($reason !== null) {
            return $reason;
        }
        if (($signal['action'] ?? 'NO_DATA') === 'NO_DATA') {
            return (string) ($signal['error'] ?? $signal['message'] ?? 'No verified RSI demo signal is available.');
        }
        if (($signal['strategy'] ?? null) !== RsiGoldScalperV2Strategy::SLUG || ($signal['strategy_version'] ?? null) !== 2
            || ($signal['indicators']['candidate_filter'] ?? null) !== 'trend-session') {
            return 'The evaluated signal must be the actual RSI Gold Scalper V2 trend-session strategy.';
        }
        if (($signal['freshness']['source'] ?? null) !== 'mt5' || ($signal['freshness']['interval_seconds'] ?? null) !== 300
            || ($signal['symbol_code'] ?? null) !== 'XAUUSD'
            || ($signal['broker']['account_key'] ?? null) !== $this->brokerSnapshot['account_key']
            || ($signal['broker']['symbol'] ?? null) !== $this->brokerSnapshot['symbol']) {
            return 'RSI signal provenance or broker identity differs from its execution snapshot.';
        }
        if (strtoupper((string) ($signal['action'] ?? '')) === 'CLOSE') {
            return 'RSI demo positions use their original broker stop and full TP1; automatic momentum CLOSE is disabled.';
        }
        $reason = app(MarketDataGuard::class)->validateSignal($signal, now()->utc());
        if ($reason !== null) {
            return $reason;
        }
        $action = strtoupper((string) ($signal['action'] ?? 'NO_DATA'));
        if (! in_array($action, ['BUY', 'SELL'], true)) {
            return null;
        }
        $plan = $signal['trade_plan'] ?? [];
        if (($plan['exit_policy'] ?? null) !== 'full_tp1' || ($plan['rr_tp1'] ?? null) !== 1.5
            || ! array_key_exists('take_profit_2', $plan) || $plan['take_profit_2'] !== null
            || ! array_key_exists('rr_tp2', $plan) || $plan['rr_tp2'] !== null
            || ($signal['indicators']['entry_session_allowed'] ?? null) !== true) {
            return 'RSI demo execution requires the original full TP1=1.5R plan and its UTC entry session.';
        }
        foreach (['entry_price', 'stop_loss', 'take_profit_1'] as $field) {
            if (! isset($plan[$field]) || ! is_numeric($plan[$field]) || ! is_finite((float) $plan[$field]) || $plan[$field] <= 0) {
                return 'RSI demo plan has invalid entry, stop or target prices.';
            }
        }
        $quote = $this->brokerSnapshot['quote'] ?? [];
        $price = $quote[$action === 'BUY' ? 'ask' : 'bid'] ?? null;
        if (! is_numeric($price) || ! is_finite((float) $price) || $price <= 0
            || ($signal['freshness']['observed_at'] ?? null) !== ($quote['timestamp'] ?? null)
            || ! is_numeric($signal['quote_price'] ?? null) || abs((float) $signal['quote_price'] - (float) $price) > 1e-8) {
            return 'RSI demo plan must use the same snapshot tick and source timestamp.';
        }
        $side = $action === 'BUY' ? 1 : -1;
        $risk = ((float) $plan['entry_price'] - (float) $plan['stop_loss']) * $side;
        $reward = ((float) $plan['take_profit_1'] - (float) $plan['entry_price']) * $side;
        // Four-place entry/stop/target rounding can create up to0.00025 price error.
        if ($risk <= 0 || $reward <= 0 || abs($reward - 1.5 * $risk) > 0.0003
            || ($price - $plan['stop_loss']) * $side <= 0 || ($plan['take_profit_1'] - $price) * $side <= 0
            || abs($price - $plan['entry_price']) > 0.1 * $risk) {
            return 'RSI demo plan violates the fixed1.5R bracket or current0.1R entry-drift limit.';
        }

        return null;
    }

    private function unavailableRsiSignal(string $reason): array
    {
        return ['strategy' => RsiGoldScalperV2Strategy::SLUG, 'action' => 'NO_DATA', 'symbol_code' => 'XAUUSD',
            'price' => 0.0, 'timestamp' => null, 'trade_plan' => [], 'research_only' => true,
            'message' => $reason, 'error' => $reason];
    }

    private function executionStrategy(AutoTraderSetting $settings, array $signal): string
    {
        return $settings->execution_mode === 'bridge' && $this->isRsiStrategy($settings)
            && ($signal['strategy'] ?? null) === RsiGoldScalperV2Strategy::SLUG
            ? RsiGoldScalperV2Strategy::SLUG : (string) $settings->strategy_slug;
    }

    private function defaultSettings(): array
    {
        $accountType = $this->normalizeAccountType((string) config('trading.auto_trader.default_account_type', 'cent'));

        return [
            'enabled' => false,
            'execution_mode' => (string) config('trading.auto_trader.execution_mode', 'dry_run'),
            'account_type' => $accountType,
            'pair_code' => 'XAUUSD',
            'mt5_symbol' => $this->symbolForAccountType($accountType),
            'strategy_slug' => (string) config('trading.auto_trader.strategy_slug', GoldBreakoutStrategy::SLUG),
            'lot_size' => (float) config('trading.auto_trader.lot_size', 0.01),
            'risk_percent_per_trade' => config('trading.auto_trader.risk_percent_per_trade', 0.25),
            'max_trades_per_day' => (int) config('trading.auto_trader.max_trades_per_day', 3),
            'max_open_trades' => (int) config('trading.auto_trader.max_open_trades', 1),
            'cooldown_minutes' => (int) config('trading.auto_trader.cooldown_minutes', 30),
            'max_daily_loss' => config('trading.auto_trader.max_daily_loss'),
            'allow_buy' => true,
            'allow_sell' => true,
            'news_filter_enabled' => false,
            'bridge_url' => config('trading.auto_trader.bridge_url'),
            'bridge_token' => config('trading.auto_trader.bridge_token'),
        ];
    }

    private function riskCheck(AutoTraderSetting $settings, string $action): ?string
    {
        // Entry limits must never prevent reducing an existing position.
        if ($action === 'CLOSE') {
            return null;
        }
        if ($settings->execution_mode === 'bridge') {
            if ((float) $settings->risk_percent_per_trade <= 0 || (float) $settings->risk_percent_per_trade > 0.5) {
                return 'Risk per trade must be greater than zero and at most 0.5%.';
            }
            if (! empty($this->brokerSnapshot['positions']) || ! empty($this->brokerSnapshot['pending_orders'])) {
                return 'An existing broker position or pending order blocks a new entry.';
            }
        }
        if ($action === 'BUY' && ! $settings->allow_buy) {
            return 'Buy orders are disabled in auto-trader settings.';
        }

        if ($action === 'SELL' && ! $settings->allow_sell) {
            return 'Sell orders are disabled in auto-trader settings.';
        }

        if ($this->todaysActionableCount($settings) >= (int) $settings->max_trades_per_day) {
            return 'Max trades per day has already been reached.';
        }

        if ($settings->execution_mode !== 'bridge' && $this->openTradeCount($settings) >= (int) $settings->max_open_trades && in_array($action, ['BUY', 'SELL'], true)) {
            return 'Max open trades has already been reached.';
        }

        $cooldownMinutes = (int) $settings->cooldown_minutes;
        if ($cooldownMinutes <= 0) {
            return null;
        }

        $lastAction = AutoTradeOrder::query()
            ->where('mt5_symbol', $settings->mt5_symbol)
            ->where('execution_mode', $settings->execution_mode)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->latest()
            ->first();

        if ($lastAction === null) {
            return null;
        }

        $nextAllowed = $lastAction->created_at?->copy()->addMinutes($cooldownMinutes);
        if ($nextAllowed instanceof Carbon && $nextAllowed->isFuture()) {
            return 'Cooldown is active until '.$nextAllowed->format('Y-m-d H:i:s').'.';
        }

        return null;
    }

    private function recordOrder(
        AutoTraderSetting $settings,
        array $signal,
        ?string $position,
        string $action,
        string $status,
        ?string $errorMessage = null,
        ?string $signature = null
    ): AutoTradeOrder {
        $plan = $signal['trade_plan'] ?? [];
        $positionBefore = $position ?? 'flat';

        return AutoTradeOrder::query()->create([
            'uuid' => (string) Str::uuid(),
            'strategy_slug' => $this->executionStrategy($settings, $signal),
            'pair_code' => (string) $settings->pair_code,
            'mt5_symbol' => (string) $settings->mt5_symbol,
            'account_type' => (string) $settings->account_type,
            'execution_mode' => (string) $settings->execution_mode,
            'action' => $action,
            'position_before' => $positionBefore,
            'position_after' => $this->positionAfter($positionBefore, $action),
            'status' => $status,
            'volume' => (float) $settings->lot_size,
            'entry_price' => $this->nullableFloat($plan['entry_price'] ?? ($signal['price'] ?? null)),
            'stop_loss' => $this->nullableFloat($plan['stop_loss'] ?? null),
            'take_profit_1' => $this->nullableFloat($plan['take_profit_1'] ?? null),
            'take_profit_2' => $this->nullableFloat($plan['take_profit_2'] ?? null),
            'risk_per_unit' => $this->nullableFloat($plan['risk_per_unit'] ?? null),
            'signal_timestamp' => $this->parseSignalTimestamp($signal['timestamp'] ?? null),
            'signal_signature' => $signature ?? $this->buildSignalSignature($settings, $signal, $position),
            'decision_payload' => [
                'signal' => $signal,
                'settings' => [
                    'strategy_slug' => $settings->strategy_slug,
                    'pair_code' => $settings->pair_code,
                    'mt5_symbol' => $settings->mt5_symbol,
                    'account_type' => $settings->account_type,
                    'lot_size' => (float) $settings->lot_size,
                ],
            ],
            'error_message' => $errorMessage,
        ]);
    }

    private function sendToBridge(AutoTraderSetting $settings, AutoTradeOrder $order, array $signal): array
    {
        if (! $settings->bridge_url) {
            $order->forceFill([
                'status' => 'failed',
                'error_message' => 'Bridge mode is enabled but no bridge URL is configured.',
            ])->save();

            return [
                'status' => 'failed',
                'message' => 'Bridge URL is missing.',
                'order' => $order->fresh(),
                'signal' => $signal,
            ];
        }

        if (! $settings->bridge_token) {
            $order->update(['status' => 'rejected', 'error_message' => 'A bridge token is required.']);

            return ['status' => 'rejected', 'message' => 'A bridge token is required.', 'order' => $order, 'signal' => $signal];
        }
        $payload = $this->bridgePayload($order) + [
            'signal_key' => $order->signal_signature,
            'signal_timestamp' => $signal['timestamp'] ?? null,
            'expires_at' => $signal['expires_at'] ?? null,
            'entry_limit_price' => $order->decision_payload['signal']['trade_plan']['entry_limit_price'] ?? null,
            'account_key' => $signal['broker']['account_key'] ?? null,
            'risk_percent' => (float) $settings->risk_percent_per_trade,
            'max_trades_per_day' => (int) $settings->max_trades_per_day,
            'cooldown_minutes' => (int) $settings->cooldown_minutes,
            'max_daily_loss' => $settings->max_daily_loss === null ? null : (float) $settings->max_daily_loss,
        ];
        if ($this->isRsiStrategy($settings)) {
            $payload += ['strategy_slug' => RsiGoldScalperV2Strategy::SLUG, 'strategy_version' => 2,
                'exit_policy' => 'full_tp1', 'demo_only' => true, 'freshness' => $signal['freshness'] ?? []];
            // The broker bridge sizes from account-currency stop risk. A local
            // reference lot setting is never an executable RSI volume request.
            unset($payload['volume']);
        }

        try {
            $request = Http::timeout(20)->acceptJson();
            if ($settings->bridge_token) {
                $request = $request->withToken((string) $settings->bridge_token);
            }

            // Order persistence, reconciliation or other work may consume the
            // source candle lifetime. Recheck immediately before submission.
            if ($this->isRsiStrategy($settings) && ($reason = $this->rsiSignalRejection($settings, $signal)) !== null) {
                $order->update(['status' => 'rejected', 'error_message' => $reason]);

                return ['status' => 'rejected', 'message' => $reason, 'order' => $order->fresh(), 'signal' => $signal];
            }
            $response = $request->post((string) $settings->bridge_url, $payload);
            $body = $response->json();
            $status = is_array($body) ? (string) ($body['status'] ?? 'unknown') : 'unknown';
            if (! in_array($status, ['executed', 'partial', 'rejected', 'failed', 'unknown'], true)) {
                $status = 'unknown';
            }
            if (! $response->successful() && ! in_array($status, ['rejected', 'failed'], true)) {
                $status = 'unknown';
            }
            $success = $status === 'executed';

            $order->forceFill([
                'status' => $status,
                'placed_at' => now(),
                'bridge_response' => is_array($body) ? $body : ['raw' => $response->body()],
                'error_message' => $success ? null : (string) ($body['message'] ?? 'Uncertain bridge outcome; reconcile before retrying.'),
                'volume' => isset($body['volume']) && is_numeric($body['volume']) ? $body['volume'] : $order->volume,
            ])->save();

            return [
                'status' => $status,
                'message' => $success ? 'MT5 confirmed execution.' : ($order->error_message ?? 'Bridge request failed.'),
                'order' => $order->fresh(),
                'signal' => $signal,
            ];
        } catch (\Throwable $exception) {
            $order->forceFill([
                'status' => 'unknown',
                'error_message' => $exception->getMessage(),
            ])->save();

            return [
                'status' => 'unknown',
                'message' => $exception->getMessage(),
                'order' => $order->fresh(),
                'signal' => $signal,
            ];
        }
    }

    public function reconcile(?AutoTraderSetting $settings = null): int
    {
        $settings ??= $this->settings();
        if (! $settings->bridge_url || ! $settings->bridge_token) {
            return 0;
        }
        $base = preg_replace('~/trade/?$~', '', rtrim((string) $settings->bridge_url, '/'));
        $updated = 0;
        $orders = AutoTradeOrder::query()->where('execution_mode', 'bridge')->whereIn('status', ['pending', 'unknown', 'sent', 'accepted', 'partial'])->oldest()->limit(5)->get();
        foreach ($orders as $order) {
            try {
                $response = Http::withToken((string) $settings->bridge_token)->acceptJson()->timeout(5)->get($base.'/orders/'.$order->uuid);
                $body = $response->json();
                if (! $response->successful() || ! is_array($body) || ! in_array($body['status'] ?? '', ['executed', 'failed', 'rejected'], true)) {
                    continue;
                }
                $order->update(['status' => $body['status'], 'bridge_response' => $body, 'error_message' => $body['status'] === 'executed' ? null : ($body['message'] ?? 'Bridge rejection')]);
                $updated++;
            } catch (\Throwable) {
                // A failed lookup cannot establish that an order did not execute.
            }
        }

        return $updated;
    }

    private function bridgePayload(AutoTradeOrder $order): array
    {
        return [
            'id' => $order->uuid,
            'symbol' => $order->mt5_symbol,
            'account_type' => $order->account_type,
            'action' => strtolower($order->action),
            'position_before' => $order->position_before,
            'volume' => (float) $order->volume,
            'entry_price' => $order->entry_price !== null ? (float) $order->entry_price : null,
            'stop_loss' => $order->stop_loss !== null ? (float) $order->stop_loss : null,
            'take_profit_1' => $order->take_profit_1 !== null ? (float) $order->take_profit_1 : null,
            'take_profit_2' => $order->take_profit_2 !== null ? (float) $order->take_profit_2 : null,
            'strategy' => $order->strategy_slug,
            'comment' => 'GoldLogic '.$order->strategy_slug.' '.$order->uuid,
            'magic' => (int) config('trading.auto_trader.magic', 240523),
        ];
    }

    private function currentPositionContext(AutoTraderSetting $settings): ?string
    {
        if ($settings->execution_mode === 'bridge') {
            $positions = $this->brokerSnapshot['positions'] ?? [];
            foreach ($positions as $position) {
                if (($position['symbol'] ?? '') === $settings->mt5_symbol && ($position['magic'] ?? 0) === (int) config('trading.auto_trader.magic')) {
                    return ($position['type'] ?? 0) === 0 ? 'long' : 'short';
                }
            }

            return null;
        }
        // Dry run records entry intents; it is not a fill/position simulator.
        if ($settings->strategy_slug === GoldBreakoutStrategy::SLUG) {
            return null;
        }
        $lastAction = AutoTradeOrder::query()
            ->where('mt5_symbol', $settings->mt5_symbol)
            ->where('execution_mode', $settings->execution_mode)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->latest()
            ->first();

        return match ($lastAction?->position_after) {
            'long' => 'long',
            'short' => 'short',
            default => null,
        };
    }

    private function todaysActionableCount(AutoTraderSetting $settings): int
    {
        return AutoTradeOrder::query()
            ->where('mt5_symbol', $settings->mt5_symbol)
            ->where('execution_mode', $settings->execution_mode)
            ->whereIn('action', ['BUY', 'SELL'])
            ->whereDate('created_at', now()->toDateString())
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->count();
    }

    private function openTradeCount(AutoTraderSetting $settings): int
    {
        if ($settings->strategy_slug === GoldBreakoutStrategy::SLUG) {
            return count($this->brokerSnapshot['positions'] ?? []);
        }
        $lastAction = AutoTradeOrder::query()
            ->where('mt5_symbol', $settings->mt5_symbol)
            ->where('execution_mode', $settings->execution_mode)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->latest()
            ->first();

        return in_array($lastAction?->position_after, ['long', 'short'], true) ? 1 : 0;
    }

    private function buildSignalSignature(AutoTraderSetting $settings, array $signal, ?string $position): string
    {
        $plan = $signal['trade_plan'] ?? [];

        return hash('sha256', json_encode([
            'strategy' => $this->executionStrategy($settings, $signal),
            'execution_mode' => $settings->execution_mode,
            'account_key' => $signal['broker']['account_key'] ?? null,
            'symbol' => $settings->mt5_symbol,
            'position' => $position ?? 'flat',
            'action' => strtoupper((string) ($signal['action'] ?? '')),
            'timestamp' => (string) ($signal['timestamp'] ?? ''),
            'entry' => $plan['entry_price'] ?? null,
            'sl' => $plan['stop_loss'] ?? null,
            'tp1' => $plan['take_profit_1'] ?? null,
            'tp2' => $plan['take_profit_2'] ?? null,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function positionAfter(string $positionBefore, string $action): string
    {
        return match ($action) {
            'BUY' => 'long',
            'SELL' => 'short',
            'CLOSE' => 'flat',
            default => $positionBefore,
        };
    }

    private function normalizeExecutionMode(string $mode): string
    {
        return in_array($mode, ['dry_run', 'bridge'], true) ? $mode : 'dry_run';
    }

    private function normalizeAccountType(string $accountType): string
    {
        return in_array($accountType, ['cent', 'dollar'], true) ? $accountType : 'cent';
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function parseSignalTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
