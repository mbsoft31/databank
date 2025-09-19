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

    'node' => [
        'path' => env('NODE_PATH', 'node'),
    ],

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index_prefix' => env('MEILISEARCH_INDEX_PREFIX', 'databank_'),
    ],

    'duplicate_detection' => [
        'similarity_threshold' => env('DUPLICATE_SIMILARITY_THRESHOLD', 0.8),
        'min_content_length' => env('DUPLICATE_MIN_CONTENT_LENGTH', 10),
    ],

    'exports' => [
        'max_items_per_export' => env('MAX_ITEMS_PER_EXPORT', 50),
        'cleanup_after_days' => env('EXPORT_CLEANUP_DAYS', 7),
        'storage_disk' => env('EXPORT_STORAGE_DISK', 'local'),
    ],

    'rate_limits' => [
        'authoring' => env('RATE_LIMIT_AUTHORING', '30,1'), // 30 per minute
        'search' => env('RATE_LIMIT_SEARCH', '100,1'), // 100 per minute
        'storage' => env('RATE_LIMIT_STORAGE', '10,1'), // 10 per minute
    ],

];
