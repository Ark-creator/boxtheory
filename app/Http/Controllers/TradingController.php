<?php

namespace App\Http\Controllers;

use App\Models\Strategy;
use App\Services\TradingService;
use Illuminate\Http\Request;

class TradingController extends Controller
{
    public function show($slug, TradingService $service)
    {
        // 1. Find the strategy (e.g., Box Theory)
        $strategy = Strategy::where('slug', $slug)->firstOrFail();

        // 2. Check for active 30-day subscription
        $hasAccess = auth()->user()->strategies()
            ->where('strategy_id', $strategy->id)
            ->wherePivot('status', 'active')
            ->wherePivot('expires_at', '>', now())
            ->exists();

        if (!$hasAccess) {
            return redirect()->route('strategies.index')
                ->with('error', 'Subscription required or expired. Please upload a new receipt.');
        }

        // 3. Get Box Levels (Prev Day High/Low) and Live Price
        $levels = $service->getDailyLevels(); // Returns ['high' => x, 'low' => y]
        $liveData = $service->getXauUsdData(); // Returns ['5. Exchange Rate' => z]

        return view('signals.show', [
            'strategy'     => $strategy,
            'prevHigh'     => $levels['high'], // Strongest Seller
            'prevLow'      => $levels['low'],  // Strongest Buyer
            'currentPrice' => $liveData['5. Exchange Rate'] ?? 0,
        ]);
    }
}