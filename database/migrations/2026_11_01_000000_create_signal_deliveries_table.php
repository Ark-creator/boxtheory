<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->nullable()->constrained()->nullOnDelete();
            $table->string('strategy_name');
            $table->string('strategy_slug');
            $table->string('pair_code', 20);
            $table->string('action', 20);
            $table->string('recipient', 254);
            $table->string('signature', 64);
            $table->json('signal_payload');
            $table->string('status', 20)->default('pending');
            $table->string('mailer');
            $table->boolean('forced')->default(false);
            $table->timestamp('attempted_at');
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('attempted_at');
            $table->index(['strategy_slug', 'attempted_at']);
            $table->index(['status', 'attempted_at']);
            $table->index(['signature', 'recipient']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_deliveries');
    }
};
