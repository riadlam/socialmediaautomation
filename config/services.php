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

    'heygen' => [
        'api_key' => env('HEYGEN_API_KEY'),
        'avatar_id' => env('HEYGEN_AVATAR_ID'),
        'voice_id' => env('HEYGEN_VOICE_ID'),
        'max_poll_attempts' => env('HEYGEN_MAX_POLL_ATTEMPTS', 60),
    ],

    'zrno' => [
        'api_key' => env('ZRNO_API_KEY'),
        'base_url' => env('ZRNO_BASE_URL', 'https://zernio.com/api'),
        'platform' => env('ZRNO_PLATFORM'),
        'account_id' => env('ZRNO_ACCOUNT_ID'),
    ],

];
