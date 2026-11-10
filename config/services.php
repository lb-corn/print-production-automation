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

    'materials' => [
        'sheet_id' => env('MATERIALS_SHEET_ID','1NyRZkS7ZFvsr2PmBLuj7eFABRkYlmBOSOdI442DooXM'),
        'sheet_tab' => env('MATERIALS_SHEET_TAB',env('MATERIALS_AUTHENTICATED',false)?'Lucky':'Lucky Bags'),
        'authenticated' => (bool) env('MATERIALS_AUTHENTICATED',false),
        'partner_tabs' => ['lucky'=>env('MATERIALS_LUCKY_TAB','Lucky'),'vibe'=>env('MATERIALS_VIBE_TAB','Vibe')],
    ],

    'backing_colors' => [
        'enabled'=>(bool)env('BACKING_COLOR_MATCHING_ENABLED',env('MATERIALS_AUTHENTICATED',false)),
        'folder_name'=>env('BACKING_SWATCH_FOLDER_NAME','Backside Scan Materials'),
        'folder_map'=>json_decode(env('BACKING_SWATCH_FOLDER_MAP','{}'),true)?:[],
        'product_image_hosts'=>array_filter(array_map('trim',explode(',',env('BACKING_PRODUCT_IMAGE_HOSTS','cdn.shopify.com')))),
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
