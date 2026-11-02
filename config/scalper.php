<?php

return [
    'gold' => [
        // legacy | trend-v2 | trend-session-v2. Research candidates do not establish a live edge.
        'version' => env('RSI_GOLD_SCALPER_VERSION', 'trend-session-v2'),
    ],
];
