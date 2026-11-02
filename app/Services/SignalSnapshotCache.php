<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** View caches never extend a provider observation or turn failed data into a signal. */
class SignalSnapshotCache
{
    public function __construct(private readonly MarketDataGuard $guard = new MarketDataGuard) {}

    public function get(string $key, string $pairCode): ?array
    {
        $signal = Cache::get($key);
        if (is_array($signal) && ($signal['symbol_code'] ?? null) === $pairCode && $this->isFresh($signal)) {
            return $signal;
        }
        Cache::forget($key);

        return null;
    }

    public function put(string $key, array $signal, int $requestedSeconds): void
    {
        if (! $this->isFresh($signal)) {
            Cache::forget($key);

            return;
        }
        $until = min(
            now()->timestamp + max(1, $requestedSeconds),
            Carbon::parse($signal['expires_at'])->timestamp,
            Carbon::parse($signal['freshness']['observed_at'])->timestamp + MarketDataGuard::PROVIDER_GRACE_SECONDS,
        );
        if ($until <= now()->timestamp) {
            Cache::forget($key);

            return;
        }
        Cache::put($key, $signal, Carbon::createFromTimestampUTC($until));
    }

    private function isFresh(array $signal): bool
    {
        return in_array($signal['action'] ?? null, ['BUY', 'SELL', 'CLOSE', 'HOLD', 'WAIT'], true)
            && $this->guard->validateSignal($signal, now()->utc()) === null;
    }

    /** Other cells may take time to fetch after a cache hit; check again before rendering. */
    public function forView(array $signal, string $pairCode): array
    {
        if (($signal['action'] ?? 'NO_DATA') === 'NO_DATA') {
            return $signal;
        }
        $reason = ($signal['symbol_code'] ?? null) !== $pairCode ? 'Cached signal belongs to a different market.'
            : $this->guard->validateSignal($signal, now()->utc());
        if ($reason === null) {
            return $signal;
        }

        return array_replace($signal, [
            'action' => 'NO_DATA', 'price' => 0.0, 'timestamp' => null, 'expires_at' => null,
            'quote_price' => null, 'signal_id' => null, 'trade_plan' => [],
            'freshness' => ['verified' => false], 'message' => $reason, 'error' => $reason,
            'market_status' => $this->guard->marketStatus($pairCode, now()->utc())['open'] ? 'unavailable' : 'closed',
        ]);
    }
}
