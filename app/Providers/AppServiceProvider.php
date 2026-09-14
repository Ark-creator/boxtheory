<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate; // THIS IS THE CRITICAL LINE
use Illuminate\Support\ServiceProvider; // Also include this if you use the User model in the gate

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Services\GoldBreakoutStrategy::class, function () {
            $maximum = config('gold_breakout.max_entry_extension_atr', 0.75);
            if (! is_numeric($maximum)) {
                throw new \InvalidArgumentException('GOLD_MAX_ENTRY_EXTENSION_ATR must be a positive finite number.');
            }

            return new \App\Services\GoldBreakoutStrategy((float) $maximum);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Now Gate will be recognized correctly
        Gate::define('admin-access', function (User $user) {
            return $user->role === 'admin';
        });
    }
}
