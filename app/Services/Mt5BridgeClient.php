<?php

namespace App\Services;

use App\Models\AutoTraderSetting;
use Illuminate\Support\Facades\Http;

class Mt5BridgeClient
{
    public function snapshot(?AutoTraderSetting $settings = null): array
    {
        $settings ??= AutoTraderSetting::query()->find(1);
        $url = (string) ($settings?->bridge_url ?: config('trading.auto_trader.bridge_url'));
        $token = (string) ($settings?->bridge_token ?: config('trading.auto_trader.bridge_token'));
        if ($url === '' || $token === '') {
            throw new \RuntimeException('Configure the MT5 bridge URL and token to receive broker XAUUSD signals.');
        }
        $url = preg_replace('~/trade/?$~', '', rtrim($url, '/'));
        $data = Http::withToken($token)->acceptJson()->timeout(10)->get($url.'/snapshot')->throw()->json();
        if (! is_array($data) || ($data['status'] ?? '') !== 'ok' || ($data['source'] ?? '') !== 'mt5' || empty($data['account_key'])) {
            throw new \RuntimeException('Invalid MT5 snapshot.');
        }
        if (! is_array($data['capabilities'] ?? null) || ! in_array('entry_extension_limit', $data['capabilities'], true)) {
            throw new \RuntimeException('Update the MT5 bridge to enforce the breakout entry limit before using this strategy.');
        }
        if ($settings && ($data['symbol'] ?? '') !== $settings->mt5_symbol) {
            throw new \RuntimeException('The bridge symbol differs from the configured MT5 symbol.');
        }
        $observed = \Illuminate\Support\Carbon::parse($data['observed_at'] ?? '1970-01-01')->utc();
        if ($observed->lt(now()->utc()->subSeconds(30)) || $observed->gt(now()->utc()->addSeconds(5))) {
            throw new \RuntimeException('MT5 snapshot is stale or has a future timestamp.');
        }

        return $data;
    }
}
