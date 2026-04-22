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
        // Optional for Video Agent (`POST /v3/video-agents`); pin narration when set.
        'voice_id' => env('HEYGEN_VOICE_ID'),
        'max_poll_attempts' => env('HEYGEN_MAX_POLL_ATTEMPTS', 60),
        'target_seconds' => env('HEYGEN_TARGET_SECONDS', 20),
        'words_per_minute' => env('HEYGEN_WORDS_PER_MINUTE', 150),
        // Legacy aspect label: maps to Video Agent `orientation` (9:16 → portrait, 16:9 → landscape).
        // When true, prefer `captioned_video_url` after render and add subtitle instructions to the agent prompt.
        'caption' => env('HEYGEN_CAPTION', true),
        'caption_file_format' => env('HEYGEN_CAPTION_FILE_FORMAT', 'srt'),
        'aspect_ratio' => env('HEYGEN_ASPECT_RATIO', '9:16'),
        'resolution' => env('HEYGEN_RESOLUTION', '1080p'),
        // Photo / Avatar IV style motion (optional).
        'motion_prompt' => env('HEYGEN_MOTION_PROMPT'),
        'expressiveness' => env('HEYGEN_EXPRESSIVENESS'),
        // Solid background: type color + hex. Set HEYGEN_BACKGROUND_COLOR=none to omit.
        'background_color' => env('HEYGEN_BACKGROUND_COLOR', '#0a0a0a'),
        // Join multi-line advice scripts into one `script` (paragraph breaks between beats).
        'multi_scene' => env('HEYGEN_MULTI_SCENE', true),
        'max_scenes' => env('HEYGEN_MAX_SCENES', 12),
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
