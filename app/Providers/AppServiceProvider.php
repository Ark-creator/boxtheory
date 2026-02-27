<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate; // THIS IS THE CRITICAL LINE
use App\Models\User; // Also include this if you use the User model in the gate

class AppServiceProvider extends ServiceProvider
{
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