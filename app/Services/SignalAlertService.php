<?php

namespace App\Services;

use App\Mail\StrategySignalAlertMail;
use App\Models\Strategy;
use App\Models\User;
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
    public function dispatch(bool $force = false): array
    {
        $this->ensureValidMailerFrom();

        $recipients = $this->resolveRecipients();
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
            $signal = $this->tradingService->getSignalForStrategy($strategy->slug);
            $action = strtoupper((string) ($signal['action'] ?? 'NO_DATA'));

            if (!in_array($action, $allowedActions, true)) {
                $result['skipped']++;
                continue;
            }

            $signature = $this->buildSignature($signal);
            $cacheKey = $this->cacheKey($strategy->id);
            $previous = Cache::get($cacheKey);

            if (!$force && $previous === $signature) {
                $result['skipped']++;
                continue;
            }

            try {
                foreach ($recipients as $email) {
                    Mail::to($email)->send(
                        new StrategySignalAlertMail($strategy, $signal, now())
                    );
                }

                Cache::forever($cacheKey, $signature);
                $result['sent']++;
            } catch (\Throwable $exception) {
                $result['errors'][] = sprintf(
                    'Failed to send signal for %s (%s): %s',
                    $strategy->name,
                    $strategy->slug,
                    $exception->getMessage()
                );
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
        $configured = (string) config('services.signal_alerts.actions', 'BUY,SELL,CLOSE,HOLD');
        $actions = array_values(array_filter(array_map(
            static fn (string $value): string => strtoupper(trim($value)),
            explode(',', $configured)
        )));

        if ($actions === []) {
            return ['BUY', 'SELL', 'CLOSE', 'HOLD'];
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

    private function cacheKey(int $strategyId): string
    {
        return 'signal_alert:last_signature:' . $strategyId;
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
