<?php

namespace App\Services;

use App\Mail\StrategySignalAlertMail;
use App\Models\SignalDelivery;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class SignalAlertService
{
    public function __construct(private readonly TradingService $tradingService)
    {
    }

    /**
     * Dispatch signal emails for strategies whose signal snapshot changed.
     *
     * @return array{
     *   sent:int,
     *   skipped:int,
     *   strategies:int,
     *   recipients:int,
     *   errors:array<int,string>
     * }
     */
    public function dispatch(bool $force = false, ?string $recipient = null): array
    {
        $this->ensureValidMailerFrom();

        $recipients = $recipient === null ? $this->resolveRecipients() : $this->filterValidEmails([trim($recipient)]);
        $strategies = Strategy::query()->orderBy('id')->get();
        $allowedActions = $this->resolveAllowedActions();

        $result = [
            'sent' => 0,
            'skipped' => 0,
            'strategies' => $strategies->count(),
            'recipients' => count($recipients),
            'errors' => [],
        ];

        if ($recipients === []) {
            $result['errors'][] = 'No admin email recipients found. Set ADMIN_SIGNAL_EMAILS or create admin users with email.';
            return $result;
        }

        foreach ($strategies as $strategy) {
            try {
                $signal = $this->tradingService->getSignalForStrategy($strategy->slug);
                if (!empty($signal['expires_at']) && Carbon::parse($signal['expires_at'])->lte(now())) {
                    $result['skipped']++;
                    continue;
                }
            } catch (\Throwable $exception) {
                $result['errors'][] = sprintf('Failed to evaluate %s: %s', $strategy->name, $exception->getMessage());
                continue;
            }
            $action = strtoupper((string) ($signal['action'] ?? 'NO_DATA'));

            if ($action === 'NO_DATA') {
                $result['errors'][] = sprintf('No signal data for %s: %s', $strategy->name, $signal['error'] ?? $signal['message'] ?? 'Market data unavailable.');
                $result['skipped']++;
                continue;
            }

            if (!in_array($action, $allowedActions, true)) {
                $result['skipped']++;
                continue;
            }

            $signature = $this->buildSignature($signal);
            $sent = false;
            $attempted = false;

            foreach ($recipients as $email) {
                $cacheKey = $this->cacheKey($strategy->id, $signal, $email);
                try {
                    Cache::lock($cacheKey . ':lock', 120)->get(function () use ($cacheKey, $signature, $force, $email, $strategy, $signal, &$attempted, &$sent) {
                        if (!$force) {
                            if (Cache::get($cacheKey) === $signature) {
                                return;
                            }

                            // The history also preserves duplicate protection if the cache is cleared.
                            if (SignalDelivery::query()
                                ->where('strategy_id', $strategy->id)
                                ->where('pair_code', $signal['symbol_code'] ?? config('trading.default_pair', 'XAUUSD'))
                                ->where('recipient', $email)
                                ->where('signature', $signature)
                                ->where('status', 'sent')
                                ->exists()) {
                                Cache::forever($cacheKey, $signature);

                                return;
                            }
                        }
                        if (!empty($signal['expires_at']) && Carbon::parse($signal['expires_at'])->lte(now())) {
                            return;
                        }

                        $attempted = true;
                        $attemptedAt = now()->utc();
                        // Persist the original snapshot before sending, so an interrupted run
                        // leaves an unconfirmed attempt rather than losing the signal entirely.
                        $delivery = SignalDelivery::create([
                            'strategy_id' => $strategy->id,
                            'strategy_name' => $strategy->name,
                            'strategy_slug' => $strategy->slug,
                            'pair_code' => $signal['symbol_code'] ?? config('trading.default_pair', 'XAUUSD'),
                            'action' => strtoupper((string) $signal['action']),
                            'recipient' => $email,
                            'signature' => $signature,
                            'signal_payload' => $signal,
                            'status' => 'pending',
                            'mailer' => config('mail.default'),
                            'forced' => $force,
                            'attempted_at' => $attemptedAt,
                        ]);

                        try {
                            Mail::to($email)->send(
                                new StrategySignalAlertMail($strategy, $signal, $attemptedAt)
                            );
                        } catch (\Throwable $exception) {
                            $delivery->update([
                                'status' => 'failed',
                                'error_message' => $exception->getMessage(),
                            ]);

                            throw $exception;
                        }

                        $sent = true;
                        try {
                            $delivery->update(['status' => 'sent', 'sent_at' => now()->utc()]);
                        } finally {
                            // An audit update failure must not cause the next poll to resend
                            // a message already accepted by the mailer.
                            Cache::forever($cacheKey, $signature);
                        }
                    });
                } catch (\Throwable $exception) {
                    $result['errors'][] = sprintf(
                        'Failed to process signal email for %s (%s) to %s: %s',
                        $strategy->name,
                        $strategy->slug,
                        $email,
                        $exception->getMessage()
                    );
                }
            }

            if ($sent) {
                $result['sent']++;
            } elseif (!$attempted) {
                $result['skipped']++;
            }
        }

        return $result;
    }

    private function resolveRecipients(): array
    {
        $configured = (string) config('services.signal_alerts.admin_emails', '');
        $configuredEmails = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $configured)
        )));

        if ($configuredEmails !== []) {
            return $this->filterValidEmails($configuredEmails);
        }

        $adminEmails = User::query()
            ->where('role', 'admin')
            ->whereNotNull('email')
            ->pluck('email')
            ->filter()
            ->map(static fn (string $email): string => trim($email))
            ->unique()
            ->values()
            ->all();

        return $this->filterValidEmails($adminEmails);
    }

    /**
     * @return array<int,string>
     */
    private function resolveAllowedActions(): array
    {
        $configured = (string) config('services.signal_alerts.actions', 'BUY,SELL');
        $actions = array_values(array_filter(array_map(
            static fn (string $value): string => strtoupper(trim($value)),
            explode(',', $configured)
        )));

        if ($actions === []) {
            return ['BUY', 'SELL'];
        }

        return array_values(array_unique($actions));
    }

    private function buildSignature(array $signal): string
    {
        $payload = [
            'action' => strtoupper((string) ($signal['action'] ?? '')),
            'timestamp' => (string) ($signal['timestamp'] ?? ''),
            'message' => (string) ($signal['message'] ?? ''),
            'trade_plan' => $signal['trade_plan'] ?? [],
            'position' => (string) ($signal['position'] ?? ''),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function cacheKey(int $strategyId, array $signal, string $email): string
    {
        $pair = (string) ($signal['symbol_code'] ?? config('trading.default_pair', 'XAUUSD'));

        return 'signal_alert:last_signature:' . $strategyId . ':' . $pair . ':' . hash('sha256', strtolower($email));
    }

    /**
     * @param array<int,string> $emails
     * @return array<int,string>
     */
    private function filterValidEmails(array $emails): array
    {
        $valid = array_filter($emails, static function (string $email): bool {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        });

        return array_values(array_unique($valid));
    }

    private function ensureValidMailerFrom(): void
    {
        $fromAddress = (string) config('mail.from.address', '');
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            config([
                'mail.from.address' => 'hello@example.com',
                'mail.from.name' => (string) config('app.name', 'GoldLogic'),
            ]);
        }
    }
}
