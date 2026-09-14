<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('strategies')->insertOrIgnore([
            'slug' => 'gold-risk-breakout', 'name' => 'Gold Risk Breakout (Research)', 'price' => 0,
            'description' => 'XAUUSD H1 trend and closed M15 breakout with ATR/structure stop and 2R target. Research candidate; profitability is unverified.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve strategy subscriptions and historical records on rollback.
    }
};
