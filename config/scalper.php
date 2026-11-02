<?php

return [
    'gold' => [
        // Signal data only; selecting MT5 does not enable broker execution.
        'source' => env('RSI_GOLD_SCALPER_SOURCE', 'twelvedata'),
        // legacy | trend-v2 | trend-session-v2. Research candidates do not establish a live edge.
        'version' => env('RSI_GOLD_SCALPER_VERSION', 'trend-session-v2'),
    ],
];
