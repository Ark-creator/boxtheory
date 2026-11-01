<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignalDelivery extends Model
{
    protected $fillable = [
        'strategy_id',
        'strategy_name',
        'strategy_slug',
        'pair_code',
        'action',
        'recipient',
        'signature',
        'signal_payload',
        'status',
        'mailer',
        'forced',
        'attempted_at',
        'sent_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'signal_payload' => 'array',
            'forced' => 'boolean',
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
