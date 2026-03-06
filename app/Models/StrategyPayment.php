<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StrategyPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'strategy_id',
        'provider',
        'checkout_session_id',
        'payment_id',
        'amount',
        'currency',
        'status',
        'checkout_url',
        'raw_payload',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'raw_payload' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function strategy()
    {
        return $this->belongsTo(Strategy::class);
    }
}

