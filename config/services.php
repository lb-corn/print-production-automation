<?php

return [

    'shopify' => [
        'store' => env('SHOPIFY_STORE'),
        'access_token' => env('SHOPIFY_ACCESS_TOKEN'),
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
        'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),
        'connect_timeout' => (int) env('SHOPIFY_CONNECT_TIMEOUT', 5),
        'request_timeout' => (int) env('SHOPIFY_REQUEST_TIMEOUT', 20),
        'retry_attempts' => (int) env('SHOPIFY_RETRY_ATTEMPTS', 2),
        'retry_sleep_ms' => (int) env('SHOPIFY_RETRY_SLEEP_MS', 350),
    ],

    'google_drive' => [
        'service_account_file' => base_path(
            env(
                'GOOGLE_SERVICE_ACCOUNT_FILE',
                'service-account.json'
            )
        ),

        'artwork_root_id' => env(
            'GOOGLE_DRIVE_ARTWORK_ROOT_ID'
        ),

        'allowed_root_folders' => [
            '(GROUP A) Non Carpet',
            'GROUP B (Carpet)',
        ],
    ],

];
