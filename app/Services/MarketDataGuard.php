<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/** Conservative session and provenance gates. Provider holiday status is checked separately. */
class MarketDataGuard
{
    public const PROVIDER_GRACE_SECONDS = 90;

    public const SIGNAL_LIFETIME_SECONDS = 180;

    /** @return array{open:bool, reason:string} */
    public function marketStatus(string $pairCode, Carbon $asOf): array
    {
        $code = strtoupper(str_replace('/', '', trim($pairCode)));
        $gold = $code === 'XAUUSD';
        $currencies = ['USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'NZD'];
        if (! $gold && (strlen($code) !== 6 || ! in_array(substr($code, 0, 3), $currencies, true)
            || ! in_array(substr($code, 3, 3), $currencies, true) || substr($code, 0, 3) === substr($code, 3, 3))) {
            return ['open' => false, 'reason' => 'No verified market session is configured for this symbol.'];
        }
        $newYork = $asOf->copy()->setTimezone('America/New_York');
        $day = $newYork->dayOfWeekIso;
        $minute = $newYork->hour * 60 + $newYork->minute;
        $sundayOpen = ($gold ? 18 : 17) * 60;
        if ($day === 6 || ($day === 7 && $minute < $sundayOpen) || ($day === 5 && $minute >= 17 * 60)) {
            return ['open' => false, 'reason' => 'Market is closed for the weekly New York session break.'];
        }
        if ($gold && $day >= 1 && $day <= 4 && $minute >= 17 * 60 && $minute < 18 * 60) {
            return ['open' => false, 'reason' => 'Gold is closed for the daily 17:00–18:00 New York maintenance break.'];
        }

        return ['open' => true, 'reason' => 'Within the regular New York trading session; fresh provider data is still required.'];
    }

    /**
     * Validate UTC opening times and OHLC; exclude forming/future bars.
     * Latest means the most recently expected closed bar, with a short publication grace.
     *
     * @throws \InvalidArgumentException
     */
    public function validateCandles(array $bars, int $seconds, Carbon $asOf, bool $requireLatest = true): array
    {
        $this->validInterval($seconds);
        $now = $asOf->getTimestamp();
        $previous = null;
        $closed = [];
        foreach ($bars as $bar) {
            if (! is_array($bar) || ! is_string($bar['timestamp'] ?? null)) {
                throw new \InvalidArgumentException('Each candle requires an ISO UTC opening timestamp.');
            }
            $time = $this->timestamp($bar['timestamp']);
            if ($time % $seconds !== 0 || ($previous !== null && $time <= $previous)) {
                throw new \InvalidArgumentException('Candle opening timestamps must be timeframe-aligned, unique and ascending.');
            }
            $previous = $time;
            if ($time + $seconds > $now) {
                continue;
            }
            foreach (['open', 'high', 'low', 'close'] as $key) {
                if (! isset($bar[$key]) || ! is_numeric($bar[$key]) || ! is_finite((float) $bar[$key]) || $bar[$key] <= 0) {
                    throw new \InvalidArgumentException('Candle prices must be positive finite OHLC values.');
                }
                $bar[$key] = (float) $bar[$key];
            }
            if ($bar['high'] < max($bar['open'], $bar['close'], $bar['low']) || $bar['low'] > min($bar['open'], $bar['close'])) {
                throw new \InvalidArgumentException('Candle OHLC bounds are inconsistent.');
            }
            $bar['timestamp'] = $this->iso($time);
            $closed[] = $bar;
        }
        if ($closed === []) {
            throw new \InvalidArgumentException('No completed market candles are available.');
        }
        if ($requireLatest) {
            $lastClosed = $this->timestamp($closed[array_key_last($closed)]['timestamp']) + $seconds;
            if (! $this->latestClose($lastClosed, $seconds, $now)) {
                throw new \InvalidArgumentException('Market candles are stale; the latest expected closed bar is missing.');
            }
        }

        return $closed;
    }

    /** Returns a rejection reason, or null when an entry snapshot remains fresh. */
    public function validateSignal(array $signal, Carbon $asOf): ?string
    {
        $pair = $signal['symbol_code'] ?? null;
        if (! is_string($pair) || $pair === '') {
            return 'Signal has no verified market symbol.';
        }
        $market = $this->marketStatus($pair, $asOf);
        if (! $market['open']) {
            return $market['reason'];
        }
        $freshness = $signal['freshness'] ?? null;
        if (! is_array($freshness) || ($freshness['verified'] ?? null) !== true
            || ! in_array($freshness['source'] ?? null, ['twelvedata', 'mt5'], true)
            || ($freshness['market_open'] ?? null) !== true) {
            return 'Signal has no verified open-market data provenance.';
        }
        $interval = $freshness['interval_seconds'] ?? null;
        if (! is_int($interval)) {
            return 'Signal is missing its verified candle interval.';
        }
        try {
            $this->validInterval($interval);
            $closedAt = $this->timestamp($freshness['data_as_of'] ?? null);
            $observedAt = $this->timestamp($freshness['observed_at'] ?? null);
            $generatedAt = $this->timestamp($freshness['generated_at'] ?? null);
            $expiresAt = $this->timestamp($signal['expires_at'] ?? null);
            $signalAt = $this->timestamp($signal['timestamp'] ?? null);
        } catch (\InvalidArgumentException $exception) {
            return 'Signal freshness metadata is invalid: '.$exception->getMessage();
        }
        $now = $asOf->getTimestamp();
        if ($closedAt % $interval !== 0 || ! $this->latestClose($closedAt, $interval, $now)) {
            return 'Signal does not use the latest completed market candle.';
        }
        if ($signalAt !== $closedAt && $signalAt !== $closedAt - $interval) {
            return 'Signal timestamp does not match its verified market candle.';
        }
        if ($closedAt > $now || $observedAt > $now || $generatedAt > $now) {
            return 'Signal freshness evidence contains a future timestamp.';
        }
        if ($observedAt < $now - self::PROVIDER_GRACE_SECONDS || $observedAt < $closedAt) {
            return 'Signal provider quote is stale or predates its completed candle.';
        }
        if ($generatedAt < $closedAt || $generatedAt < $observedAt || $generatedAt < $now - self::SIGNAL_LIFETIME_SECONDS) {
            return 'Signal generation time is stale or inconsistent with its market data.';
        }
        if ($expiresAt <= $now || $expiresAt > $closedAt + self::SIGNAL_LIFETIME_SECONDS || $expiresAt > $generatedAt + self::SIGNAL_LIFETIME_SECONDS) {
            return 'Signal has expired or exceeds the permitted candle-based lifetime.';
        }

        return null;
    }

    private function latestClose(int $closedAt, int $seconds, int $now): bool
    {
        $expected = intdiv($now, $seconds) * $seconds;

        return $closedAt === $expected || ($closedAt === $expected - $seconds && $now - $expected <= self::PROVIDER_GRACE_SECONDS);
    }

    private function validInterval(int $seconds): void
    {
        if ($seconds < 60 || $seconds > 86400 || $seconds % 60 !== 0 || 86400 % $seconds !== 0) {
            throw new \InvalidArgumentException('Market candle interval must be whole minutes aligned within a UTC day.');
        }
    }

    private function timestamp(mixed $timestamp): int
    {
        if (! is_string($timestamp) || ! preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(Z|[+-]\d{2}:\d{2})?$/D', $timestamp, $parts)) {
            throw new \InvalidArgumentException('Expected an ISO calendar timestamp with hours, minutes and seconds.');
        }
        $offset = $parts[3] ?? '+00:00';
        if ($offset === 'Z') {
            $offset = '+00:00';
        } elseif ((int) substr($offset, 1, 2) > 23 || (int) substr($offset, 4, 2) > 59) {
            throw new \InvalidArgumentException('Invalid UTC timestamp offset.');
        }
        $canonical = $parts[1].'T'.$parts[2].$offset;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $canonical, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i:sP') !== $canonical) {
            throw new \InvalidArgumentException('Invalid calendar date or time.');
        }

        return $date->getTimestamp();
    }

    private function iso(int $at): string
    {
        return gmdate('Y-m-d\TH:i:s', $at).'+00:00';
    }
}
