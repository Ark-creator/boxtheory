<?php

namespace App\Console\Commands;

use App\Services\MarketDataGuard;
use App\Services\TradingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Read-only market checks: no signal dispatch, email, orders or account updates. */
class CheckSignalData extends Command
{
    protected $signature = 'signals:check-data {--pair=XAUUSD : Configured market code}';

    protected $description = 'Check provider quote and confirmed-candle freshness without sending emails or orders';

    public function handle(TradingService $trading, MarketDataGuard $guard): int
    {
        $pair = $trading->normalizePairCode((string) $this->option('pair'));
        $clock = now()->utc();
        $status = $guard->marketStatus($pair, $clock);
        $result = ['pair' => $pair, 'checked_at_utc' => $clock->toIso8601String(),
            'checked_at_manila' => $clock->copy()->setTimezone('Asia/Manila')->toIso8601String(), 'session' => $status,
            'emails_sent' => 0, 'orders_sent' => 0];
        if (! $status['open']) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $quote = $trading->getMarketData($pair);
        $result['quote'] = $quote;
        $healthy = $quote['error'] === null;
        if ($quote['timestamp']) {
            $result['quote_age_seconds'] = now()->timestamp - Carbon::parse($quote['timestamp'])->timestamp;
        }
        foreach (['5min' => 300, '15min' => 900, '60min' => 3600] as $interval => $seconds) {
            $data = $trading->getCandles($pair, $interval, 300);
            $last = $data['candles'][array_key_last($data['candles'])] ?? null;
            $healthy = $healthy && $data['error'] === null;
            $result['candles'][$interval] = ['source' => $data['source'], 'closed_bars' => count($data['candles']),
                'last_opened_at' => $last['timestamp'] ?? null,
                'last_closed_at' => $last ? Carbon::parse($last['timestamp'])->addSeconds($seconds)->toIso8601String() : null,
                'error' => $data['error']];
        }
        $result['healthy'] = $healthy;
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
