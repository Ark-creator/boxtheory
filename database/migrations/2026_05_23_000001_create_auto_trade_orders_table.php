<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_trade_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('strategy_slug');
            $table->string('pair_code', 20)->default('XAUUSD');
            $table->string('mt5_symbol', 40);
            $table->string('account_type')->default('cent');
            $table->string('execution_mode')->default('dry_run');
            $table->string('action', 20);
            $table->string('position_before', 20)->default('flat');
            $table->string('position_after', 20)->default('flat');
            $table->string('status', 30)->default('pending');
            $table->decimal('volume', 8, 2)->default(0.01);
            $table->decimal('entry_price', 14, 5)->nullable();
            $table->decimal('stop_loss', 14, 5)->nullable();
            $table->decimal('take_profit_1', 14, 5)->nullable();
            $table->decimal('take_profit_2', 14, 5)->nullable();
            $table->decimal('risk_per_unit', 14, 5)->nullable();
            $table->timestamp('signal_timestamp')->nullable();
            $table->string('signal_signature', 64)->index();
            $table->json('decision_payload')->nullable();
            $table->json('bridge_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamps();

            $table->index(['mt5_symbol', 'status']);
            $table->index(['created_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_trade_orders');
    }
};
