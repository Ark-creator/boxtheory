<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class TradingService
{
    /**
     * Get the real-time XAU/USD price.
     * Used to determine if price is in a Buy, Sell, or No-Trade zone.
     */
    public function getXauUsdData()
    {
        // Cache live price for 1 minute to avoid hitting API limits
        return Cache::remember('xau_usd_price', 60, function () {
            $response = Http::get('https://www.alphavantage.co/query', [
                'function' => 'CURRENCY_EXCHANGE_RATE',
                'from_currency' => 'XAU',
                'to_currency' => 'USD',
                'apikey' => config('services.alphavantage.key'),
            ]);

            $data = $response->json();

            return [
                'price' => $data['Realtime Currency Exchange Rate']['5. Exchange Rate'] ?? 0
            ];
        });
    }

    /**
     * Get the Previous Day's High and Low.
     * This defines the "Box" boundaries for the strategy.
     */
    public function getDailyLevels()
    {
        // Cache daily levels for 1 hour as they only change once per day
        return Cache::remember('xau_daily_levels', 3600, function () {
            $response = Http::get('https://www.alphavantage.co/query', [
                'function' => 'TIME_SERIES_DAILY',
                'symbol' => 'XAUUSD',
                'apikey' => config('services.alphavantage.key'),
            ]);

            $data = $response->json();

            if (!isset($data['Time Series (Daily)'])) {
                return ['high' => 0, 'low' => 0];
            }

            // Get the most recent completed daily candle (yesterday)
            $timeSeries = $data['Time Series (Daily)'];
            $yesterdayDate = array_keys($timeSeries)[1]; // [0] is today, [1] is yesterday
            $yesterdayData = $timeSeries[$yesterdayDate];

            return [
                'high' => (float) $yesterdayData['2. high'], // The strongest seller
                'low'  => (float) $yesterdayData['3. low'],  // The strongest buyer
            ];
        });
    }
}