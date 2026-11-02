<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** One spot provider, source timestamps, and fail-closed freshness checks. */
class LiveMarketDataService
{
    public function __construct(private readonly MarketDataGuard $guard = new MarketDataGuard) {}

    public function candles(array $pair, string $interval, int $limit): array
    {
        $this->requireOpen($pair);
        $minutes = match ($interval) {
            '1min' => 1, '5min' => 5, '15min' => 15, '30min' => 30, '60min', '1h' => 60,
            default => throw new \InvalidArgumentException('Unsupported live candle interval.'),
        };
        $providerInterval = $minutes === 60 ? '1h' : $minutes.'min';
        $limit = max(40, min(5000, $limit));
        // Some feeds publish indicative candles in closed sessions. Request enough
        // lookback to discard up to a weekend without starving EMA/RSI warmup.
        $requestLimit = min(5000, max(
            $limit + (int) ceil(72 * 60 / $minutes),
            (int) ceil($limit * 1.5) + (int) ceil(48 * 60 / $minutes),
        ));
        $identity = hash('sha256', $pair['twelvedata_symbol']);
        $key = 'live_market:v2:closed:'.$pair['code'].':'.$identity.':'.$providerInterval.':'.$limit;
        $packet = Cache::get($key);
        $raw = is_array($packet) && ($packet['symbol'] ?? null) === $pair['twelvedata_symbol']
            && ($packet['interval'] ?? null) === $providerInterval && ($packet['requested_timezone'] ?? null) === 'UTC'
            ? ($packet['bars'] ?? null) : null;
        if (! is_array($raw)) {
            $data = $this->request('/time_series', [
                'symbol' => $pair['twelvedata_symbol'], 'interval' => $providerInterval,
                'outputsize' => $requestLimit, 'timezone' => 'UTC', 'order' => 'asc',
            ]);
            if (($data['meta']['symbol'] ?? null) !== $pair['twelvedata_symbol']
                || ($data['meta']['interval'] ?? null) !== $providerInterval
                || ! is_array($data['values'] ?? null) || $data['values'] === []) {
                throw new \RuntimeException('Provider candle identity, interval or OHLC series is missing or mismatched.');
            }
            $raw = array_map(static fn ($bar) => [
                'timestamp' => $bar['datetime'] ?? null, 'open' => $bar['open'] ?? null,
                'high' => $bar['high'] ?? null, 'low' => $bar['low'] ?? null, 'close' => $bar['close'] ?? null,
            ], $data['values']);
        }
        // Cache reads are checked too: elapsed time cannot relabel an old bar as fresh.
        try {
            $closed = $this->guard->validateCandles($raw, $minutes * 60, now()->utc(), false);
            $closed = array_values(array_filter($closed, function ($bar) use ($pair, $minutes) {
                $at = Carbon::parse($bar['timestamp'])->utc();

                return $this->guard->marketStatus($pair['code'], $at)['open']
                    && $this->guard->marketStatus($pair['code'], $at->copy()->addMinutes($minutes)->subSecond())['open'];
            }));
            $closed = $this->guard->validateCandles($closed, $minutes * 60, now()->utc());
        } catch (\Throwable $e) {
            Cache::forget($key);
            throw $e;
        }
        $expectedClose = intdiv(now()->timestamp, $minutes * 60) * $minutes * 60;
        $lastClose = Carbon::parse($closed[array_key_last($closed)]['timestamp'])->timestamp + $minutes * 60;
        $retryAt = $lastClose < $expectedClose ? now()->utc()->addSeconds(30)
            : Carbon::createFromTimestampUTC($expectedClose + $minutes * 60);
        Cache::put($key, ['symbol' => $pair['twelvedata_symbol'], 'interval' => $providerInterval, 'requested_timezone' => 'UTC', 'bars' => $raw], $retryAt);

        return ['candles' => array_slice($closed, -$limit), 'source' => 'twelvedata_'.strtolower($pair['code']), 'error' => null];
    }

    public function quote(array $pair): array
    {
        $this->requireOpen($pair);
        $key = 'live_market:v1:quote:'.$pair['code'];
        $data = Cache::get($key);
        if (! is_array($data)) {
            $data = $this->request('/quote', ['symbol' => $pair['twelvedata_symbol'], 'interval' => '1min', 'timezone' => 'UTC']);
        }
        try {
            if (($data['symbol'] ?? null) !== $pair['twelvedata_symbol'] || ($data['is_market_open'] ?? null) !== true) {
                throw new \RuntimeException('Provider cannot confirm this market is open for current spot quotes.');
            }
            if (($data['is_delayed'] ?? false) === true || ($data['delayed'] ?? false) === true) {
                throw new \RuntimeException('Provider marked the quote as delayed; no entry alert is allowed.');
            }
            $price = $data['close'] ?? null;
            $stamp = $data['last_quote_at'] ?? null;
            // /quote timestamp/datetime describe the chosen interval's OPEN.
            // last_quote_at is the documented latest-minute observation time.
            if (! is_numeric($price) || ! is_finite((float) $price) || (float) $price <= 0
                || ! is_numeric($stamp) || (float) $stamp !== (float) (int) $stamp || (int) $stamp <= 0) {
                throw new \RuntimeException('Provider returned no valid latest quote price or last_quote_at timestamp.');
            }
            $observed = Carbon::createFromTimestampUTC((int) $stamp);
            if ($observed->gt(now()->utc()) || $observed->lt(now()->utc()->subSeconds(90))) {
                throw new \RuntimeException('Provider quote is stale or future-dated; wait for a current quote.');
            }
        } catch (\Throwable $e) {
            Cache::forget($key);
            throw $e;
        }
        Cache::put($key, $data, 10);

        return ['price' => (float) $price, 'observed_at' => $observed->toIso8601String(), 'market_open' => true, 'source' => 'twelvedata'];
    }

    public function dailyLevels(array $pair): array
    {
        $this->requireOpen($pair);
        $expected = now()->utc()->startOfDay()->subDay();
        while ($expected->isWeekend()) {
            $expected->subDay();
        }
        // Twelve Data's timezone parameter does not apply to 1day bars.
        // Define the box day explicitly in UTC using the SAME spot H1 feed.
        $hours = $this->candles($pair, '60min', 300)['candles'];
        $byTime = [];
        foreach ($hours as $bar) {
            $byTime[Carbon::parse($bar['timestamp'])->timestamp] = $bar;
        }
        $day = [];
        for ($i = 0; $i < 24; $i++) {
            $at = $expected->copy()->addHours($i);
            if (! $this->guard->marketStatus($pair['code'], $at)['open']
                || ! $this->guard->marketStatus($pair['code'], $at->copy()->addHour()->subSecond())['open']) {
                continue;
            }
            if (! isset($byTime[$at->timestamp])) {
                throw new \RuntimeException('Previous UTC trading-day spot history is incomplete; no older or partial box is substituted.');
            }
            $day[] = $byTime[$at->timestamp];
        }
        if ($day === []) {
            throw new \RuntimeException('Previous UTC trading-day spot levels are unavailable.');
        }

        return ['high' => max(array_column($day, 'high')), 'low' => min(array_column($day, 'low')),
            'date' => $expected->toDateString(), 'source' => 'twelvedata_'.strtolower($pair['code']), 'error' => null];
    }

    private function requireOpen(array $pair): void
    {
        if (strtoupper(str_replace('/', '', $pair['twelvedata_symbol'] ?? '')) !== $pair['code']) {
            throw new \RuntimeException('Configured provider instrument does not match the requested spot pair.');
        }
        $status = $this->guard->marketStatus($pair['code'], now()->utc());
        if (! $status['open']) {
            throw new \RuntimeException($status['reason']);
        }
    }

    private function request(string $endpoint, array $query): array
    {
        $key = (string) config('services.twelvedata.key');
        if ($key === '') {
            throw new \RuntimeException('TWELVEDATA_API_KEY is missing. Live spot data is required.');
        }
        $options = [];
        $caBundle = config('services.twelvedata.ca_bundle');
        if ($caBundle !== null && $caBundle !== '') {
            if (! is_string($caBundle) || ! is_file($caBundle) || ! is_readable($caBundle)) {
                throw new \RuntimeException('TWELVEDATA_CA_BUNDLE must name a readable trusted CA certificate file. TLS verification is never disabled.');
            }
            $options['verify'] = $caBundle;
        }
        $proxy = config('services.twelvedata.proxy');
        if (is_string($proxy)) {
            $options['proxy'] = $proxy;
        }
        try {
            $response = Http::withOptions($options)->acceptJson()->timeout(10)->get('https://api.twelvedata.com'.$endpoint, $query + ['apikey' => $key]);
        } catch (\Throwable $e) {
            // HTTP exception URLs may contain the API key. Do not log them.
            if (str_contains($e->getMessage(), 'cURL error 60')) {
                throw new \RuntimeException('Provider HTTPS certificate could not be verified. Configure TWELVEDATA_CA_BUNDLE with a trusted CA file; TLS verification is never disabled.');
            }
            throw new \RuntimeException('Live spot provider request failed; no fallback or previous signal is used.');
        }
        $data = $response->json();
        if (! $response->successful()) {
            throw new \RuntimeException('Live spot provider returned HTTP '.$response->status().'.');
        }
        if (! is_array($data) || ($data['status'] ?? null) === 'error') {
            $message = is_array($data) ? (string) ($data['message'] ?? 'Invalid provider response.') : 'Invalid provider response.';
            throw new \RuntimeException(str_replace($key, '[redacted]', $message));
        }

        return $data;
    }
}
