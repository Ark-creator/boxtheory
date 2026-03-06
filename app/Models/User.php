<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',          // customer or admin
        'is_approved',   // global approval status
        'watchlist_pairs',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_approved' => 'boolean',
            'watchlist_pairs' => 'array',
        ];
    }

    /**
     * Relationship: A user can subscribe to many strategies.
     * We pull the receipt and status from the pivot table.
     */
    // public function strategies()
    // {
    //     return $this->belongsToMany(Strategy::class)
    //                 ->withPivot('receipt_path', 'status')
    //                 ->withTimestamps();
    // }

    /**
     * Helper to check if user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    
    // app/Models/User.php
public function strategies()
{
    return $this->belongsToMany(Strategy::class)
                ->withPivot('receipt_path', 'status', 'expires_at')
                ->withTimestamps();
}

public function strategyPayments()
{
    return $this->hasMany(StrategyPayment::class);
}

// Helper to check if a specific strategy is active
// public function hasAccessTo($strategySlug)
// {
//     return $this->strategies()
//         ->where('slug', $strategySlug)
//         ->wherePivot('status', 'active')
//         ->exists();
// }

public function hasAccessTo($strategyId)
{
    if ($this->isAdmin()) {
        return true;
    }

    return $this->strategies()
        ->where('strategy_id', $strategyId)
        ->wherePivot('status', 'active')
        ->wherePivot('expires_at', '>', now()) // Must not be expired
        ->exists();
}
}
