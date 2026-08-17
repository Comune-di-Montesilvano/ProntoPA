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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Fallback .env per webhook_cittadini_url/secret quando non configurati
    // da Admin → Impostazioni. env() letto qui (dentro config/), non nel
    // service: altrimenti ritorna null se l'app gira con config:cache.
    'webhook_cittadini' => [
        'url'    => env('WEBHOOK_CITTADINI_URL'),
        'secret' => env('WEBHOOK_CITTADINI_SECRET'),
    ],

    // Scansione antimalware allegati (ScansionaAllegato, profilo Docker
    // opzionale `security`). Infra, non brand: resta in .env, non in
    // Admin → Impostazioni (che ha solo il toggle on/off `antivirus_enabled`).
    'clamav' => [
        'host'    => env('CLAMAV_HOST', 'clamav'),
        'port'    => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 15),
    ],

];
