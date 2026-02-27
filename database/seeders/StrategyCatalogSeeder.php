<?php

namespace Database\Seeders;

use App\Models\Strategy;
use Illuminate\Database\Seeder;

class StrategyCatalogSeeder extends Seeder
{
    /**
     * Seed and keep strategy catalog updated by slug.
     */
    public function run(): void
    {
        $strategies = [
            [
                'name' => 'Box Theory Pro',
                'slug' => 'box-theory',
                'description' => 'Previous-day high/low box boundaries for reversal and breakout zones on XAUUSD.',
                'price' => 199.00,
            ],
            [
                'name' => 'RSI Gold Scalper',
                'slug' => 'rsi-scalper',
                'description' => 'RSI and moving-average momentum strategy with ATR-based stop loss and take-profit plan.',
                'price' => 149.00,
            ],
            [
                'name' => 'Conservative V2 Final',
                'slug' => 'conservative-v2',
                'description' => 'EA-based conservative profile: EMA trend + HTF filter + RSI divergence + Bollinger + ADX + ATR risk management.',
                'price' => 249.00,
            ],
        ];

        foreach ($strategies as $strategy) {
            Strategy::updateOrCreate(
                ['slug' => $strategy['slug']],
                [
                    'name' => $strategy['name'],
                    'description' => $strategy['description'],
                    'price' => $strategy['price'],
                ]
            );
        }
    }
}

