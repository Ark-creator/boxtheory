<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'alphavantage' => [
        'key' => env('ALPHA_VANTAGE_KEY'),
    ],

    'twelvedata' => [
        'key' => env('TWELVEDATA_API_KEY'),
        'cache_seconds' => env('TRADING_INTRADAY_CACHE_SECONDS', 120),
    ],

    'paymongo' => [
        'public_key' => env('PAYMONGO_PUBLIC_KEY'),
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        // Example: gcash,paymaya,dob,qrph
        'payment_method_types' => env('PAYMONGO_PAYMENT_METHODS', 'gcash,paymaya,dob,qrph'),
    ],

    'signal_alerts' => [
        // Optional comma-separated list. If empty, all admin user emails are used.
        'admin_emails' => env('ADMIN_SIGNAL_EMAILS', ''),
        // Comma-separated actions to email, e.g. BUY,SELL,CLOSE,HOLD
        'actions' => env('SIGNAL_ALERT_ACTIONS', 'BUY,SELL,CLOSE,HOLD'),
    ],

];
