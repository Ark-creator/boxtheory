<?php

return [
    // Dashboard/email candles: mt5 or twelvedata. Broker execution always requires MT5.
    'signal_source' => env('GOLD_SIGNAL_SOURCE', 'mt5'),

    // Maximum directional entry distance from the broken channel, in prior-bar ATR.
    'max_entry_extension_atr' => env('GOLD_MAX_ENTRY_EXTENSION_ATR', 0.75),
];
