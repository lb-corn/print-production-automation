<?php

return [
    'factory' => ['api_key' => env('FACTORY_API_KEY'),'offline_after_seconds'=>(int)env('FACTORY_AGENT_OFFLINE_SECONDS',120)],

    'shopify' => [
        'artwork_collection' => 'All Bags',
        'store' => env('SHOPIFY_STORE'),
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
        'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),
    ],

    'google_drive' => [
        'rename_enabled' => (bool) env('GOOGLE_DRIVE_RENAME_ENABLED', true),
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
