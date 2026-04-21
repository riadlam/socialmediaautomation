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
        'orientation' => env('HEYGEN_ORIENTATION'),
        'max_poll_attempts' => env('HEYGEN_MAX_POLL_ATTEMPTS', 60),
        'target_seconds' => env('HEYGEN_TARGET_SECONDS', 20),
        'words_per_minute' => env('HEYGEN_WORDS_PER_MINUTE', 150),
        // On-video captions (text voice). See HeyGen Create Avatar Video v2 docs.
        'caption' => env('HEYGEN_CAPTION', true),
        'open_caption' => env('HEYGEN_OPEN_CAPTION', false),
        // Default 9:16 vertical (Shorts / Reels / TikTok). Set width to 0 to omit dimension from API.
        'dimension_width' => env('HEYGEN_DIMENSION_WIDTH', 1080),
        'dimension_height' => env('HEYGEN_DIMENSION_HEIGHT', 1920),
        // Multi-scene: advice-style scripts split into beats (blank line = paragraph, else one line per scene).
        'multi_scene' => env('HEYGEN_MULTI_SCENE', true),
        'max_scenes' => env('HEYGEN_MAX_SCENES', 12),
        // auto | paragraph | line | single — "auto" uses paragraphs if 2+, else lines if 2+, else one scene.
        'scene_split' => env('HEYGEN_SCENE_SPLIT', 'auto'),
    ],

    'zrno' => [
        'api_key' => env('ZRNO_API_KEY'),
        'base_url' => env('ZRNO_BASE_URL', 'https://zernio.com/api'),
        'platforms_json' => env('ZRNO_PLATFORMS_JSON'),
        'platform' => env('ZRNO_PLATFORM'),
        'account_id' => env('ZRNO_ACCOUNT_ID'),
        // When true, appends an internal ref line to Zerno caption (not recommended for Instagram).
        'append_unique_caption_suffix' => env('ZRNO_APPEND_UNIQUE_CAPTION_SUFFIX', false),
    ],

];
