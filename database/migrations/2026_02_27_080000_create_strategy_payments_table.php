<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('strategy_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('strategy_id')->constrained()->onDelete('cascade');
            $table->string('provider')->default('paymongo');
            $table->string('checkout_session_id')->unique();
            $table->string('payment_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 8)->default('PHP');
            $table->string('status')->default('pending'); // pending, paid, failed, expired, cancelled
            $table->text('checkout_url')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'strategy_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('strategy_payments');
    }
};

