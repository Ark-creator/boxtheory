<?php

namespace App\Console\Commands;

use App\Services\MarketDataGuard;
use App\Services\Mt5BridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Market snapshot diagnostic only: no email dispatch or trade submission. */
class CheckMt5Connection extends Command
{
    protected $signature = 'mt5:check';

    protected $description = 'Check the MT5 connection, quote and closed M5 candles without sending orders or emails';

    public function handle(Mt5BridgeClient $bridge, MarketDataGuard $guard): int
    {
        $clock = now()->utc();
        $result = ['checked_at_utc' => $clock->toIso8601String(),
            'checked_at_manila' => $clock->copy()->setTimezone('Asia/Manila')->toIso8601String(),
            'scalper_source' => config('scalper.gold.source', 'twelvedata'),
            'connection' => 'unavailable', 'market' => $guard->marketStatus('XAUUSD', $clock),
            'data_ready' => false, 'emails_sent' => 0, 'orders_sent' => 0];
        $exit = self::FAILURE;
        try {
            $snapshot = $bridge->snapshot(null, ['m5_candles', 'quote_timestamp']);
            $result['connection'] = 'connected';
            $result['symbol'] = $snapshot['symbol'];
            $result['demo_account'] = $snapshot['account']['demo'] ?? null;
            if (is_array($snapshot['clock'] ?? null)) {
                $result['broker_clock'] = $snapshot['clock'];
            }
            // Never print tokens, account identifiers, balances or position details.
            $result['m5_bars_received'] = count($snapshot['m5'] ?? []);
            $clock = now()->utc();
            $result['market'] = $guard->marketStatus('XAUUSD', $clock);
            if (! $result['market']['open']) {
                $result['message'] = 'MT5 is connected; the regular gold market is closed, so no current entry is available.';
                $exit = self::SUCCESS;
            } else {
                $quote = $snapshot['quote'] ?? [];
                foreach (['bid', 'ask'] as $field) {
                    if (! isset($quote[$field]) || ! is_numeric($quote[$field]) || ! is_finite((float) $quote[$field]) || $quote[$field] <= 0) {
                        throw new \RuntimeException('MT5 quote requires positive finite bid/ask.');
                    }
                }
                if ($quote['ask'] < $quote['bid'] || ! is_string($quote['timestamp'] ?? null)
                    || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)$/D', $quote['timestamp'])) {
                    throw new \RuntimeException('MT5 quote has invalid bid/ask or no explicit UTC source timestamp.');
                }
                $observed = Carbon::parse($quote['timestamp'])->utc();
                $age = $clock->timestamp - $observed->timestamp;
                if ($age < 0 || $age > MarketDataGuard::PROVIDER_GRACE_SECONDS) {
                    throw new \RuntimeException('MT5 quote is stale or future-dated.');
                }
                $bars = $guard->validateCandles($snapshot['m5'] ?? [], 300, $clock);
                $bars = array_values(array_filter($bars, static function ($bar) use ($guard): bool {
                    $open = Carbon::parse($bar['timestamp'])->utc();

                    return $guard->marketStatus('XAUUSD', $open)['open']
                        && $guard->marketStatus('XAUUSD', $open->copy()->addSeconds(299))['open'];
                }));
                $bars = $guard->validateCandles($bars, 300, $clock);
                if (count($bars) < 203) {
                    throw new \RuntimeException('Need 203 completed trading-session M5 bars for the updated scalper.');
                }
                $result['quote'] = ['bid' => (float) $quote['bid'], 'ask' => (float) $quote['ask'],
                    'observed_at_utc' => $observed->toIso8601String(), 'age_seconds' => $age];
                $result['active_closed_m5_bars'] = count($bars);
                $result['latest_m5_close_utc'] = Carbon::parse($bars[array_key_last($bars)]['timestamp'])->addMinutes(5)->toIso8601String();
                $result['data_ready'] = true;
                $result['message'] = 'MT5 quote and completed M5 history are current. This diagnostic sends no orders or emails.';
                $exit = self::SUCCESS;
            }
        } catch (\Throwable $exception) {
            $result['message'] = $exception->getMessage();
        }
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $exit;
    }
}
