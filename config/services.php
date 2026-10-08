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

    'orderwise' => [
        'base_url' => rtrim((string) env('ORDERWISE_BASE_URL', ''), '/'),
        'username' => env('ORDERWISE_USERNAME'),
        'password' => env('ORDERWISE_PASSWORD'),
        'export_id' => env('ORDERWISE_EXPORT_ID', '49'),
        'token_cache_minutes' => (int) env('ORDERWISE_TOKEN_CACHE_MINUTES', 55),
        'timeout_seconds' => (int) env('ORDERWISE_TIMEOUT_SECONDS', 30),
    ],

    'shopify' => [
        'store_domain' => env('SHOPIFY_STORE_DOMAIN'),
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
        'api_version' => env('SHOPIFY_API_VERSION', '2026-10'),
        'push_enabled' => (bool) env('PO_SYNC_PUSH_SHOPIFY', false),
    ],

];
