<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoTraderSetting extends Model
{
    protected $fillable = [
        'enabled',
        'execution_mode',
        'account_type',
        'pair_code',
        'mt5_symbol',
        'strategy_slug',
        'lot_size',
        'risk_percent_per_trade',
        'max_trades_per_day',
        'max_open_trades',
        'cooldown_minutes',
        'max_daily_loss',
        'allow_buy',
        'allow_sell',
        'news_filter_enabled',
        'bridge_url',
        'bridge_token',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'lot_size' => 'decimal:2',
            'risk_percent_per_trade' => 'decimal:2',
            'max_daily_loss' => 'decimal:2',
            'allow_buy' => 'boolean',
            'allow_sell' => 'boolean',
            'news_filter_enabled' => 'boolean',
            'bridge_token' => 'encrypted',
            'last_run_at' => 'datetime',
        ];
    }
}
