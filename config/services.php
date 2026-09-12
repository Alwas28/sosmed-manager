<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    /*
    | Buffer — publishing gateway (lihat Blueprint bagian 8 & 13).
    | Daftarkan aplikasi di https://buffer.com/developers/apps untuk
    | mendapatkan client id / secret, lalu set "Callback URL" ke nilai
    | services.buffer.redirect di bawah ini.
    */
    'buffer' => [
        'client_id' => env('BUFFER_CLIENT_ID'),
        'client_secret' => env('BUFFER_CLIENT_SECRET'),
        // Access token pribadi dari halaman aplikasi Buffer — bila diisi, koneksi
        // bisa dibuat langsung tanpa alur OAuth.
        'access_token' => env('BUFFER_ACCESS_TOKEN'),
        'redirect' => env('BUFFER_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/buffer/callback'),
        'scope' => env('BUFFER_SCOPE', 'account:read posts:read posts:write offline_access'),
        // Endpoint API Buffer generasi baru (OAuth2 + PKCE, GraphQL).
        'authorize_url' => env('BUFFER_AUTHORIZE_URL', 'https://auth.buffer.com/auth'),
        'token_url' => env('BUFFER_TOKEN_URL', 'https://auth.buffer.com/token'),
        'api_base' => env('BUFFER_API_BASE', 'https://api.buffer.com'),
    ],

];
