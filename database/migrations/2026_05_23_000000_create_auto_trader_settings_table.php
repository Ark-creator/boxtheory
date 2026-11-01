<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_trader_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('execution_mode')->default('dry_run');
            $table->string('account_type')->default('cent');
            $table->string('pair_code', 20)->default('XAUUSD');
            $table->string('mt5_symbol', 40)->default('XAUUSD.STDc');
            $table->string('strategy_slug')->default('conservative-v2');
            $table->decimal('lot_size', 8, 2)->default(0.01);
            $table->decimal('risk_percent_per_trade', 5, 2)->nullable();
            $table->unsignedInteger('max_trades_per_day')->default(3);
            $table->unsignedInteger('max_open_trades')->default(1);
            $table->unsignedInteger('cooldown_minutes')->default(30);
            $table->decimal('max_daily_loss', 12, 2)->nullable();
            $table->boolean('allow_buy')->default(true);
            $table->boolean('allow_sell')->default(true);
            $table->boolean('news_filter_enabled')->default(false);
            $table->string('bridge_url')->nullable();
            $table->text('bridge_token')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_trader_settings');
    }
};
