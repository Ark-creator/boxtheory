<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoTradeOrder extends Model
{
    protected $fillable = [
        'uuid',
        'strategy_slug',
        'pair_code',
        'mt5_symbol',
        'account_type',
        'execution_mode',
        'action',
        'position_before',
        'position_after',
        'status',
        'volume',
        'entry_price',
        'stop_loss',
        'take_profit_1',
        'take_profit_2',
        'risk_per_unit',
        'signal_timestamp',
        'signal_signature',
        'decision_payload',
        'bridge_response',
        'error_message',
        'placed_at',
    ];

    protected function casts(): array
    {
        return [
            'volume' => 'decimal:2',
            'entry_price' => 'decimal:5',
            'stop_loss' => 'decimal:5',
            'take_profit_1' => 'decimal:5',
            'take_profit_2' => 'decimal:5',
            'risk_per_unit' => 'decimal:5',
            'signal_timestamp' => 'datetime',
            'decision_payload' => 'array',
            'bridge_response' => 'array',
            'placed_at' => 'datetime',
        ];
    }
}
