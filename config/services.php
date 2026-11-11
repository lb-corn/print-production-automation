<?php

return [
    'factory' => ['api_key' => env('FACTORY_API_KEY'),'offline_after_seconds'=>(int)env('FACTORY_AGENT_OFFLINE_SECONDS',120)],

    'shopify' => [
        'artwork_collection' => 'All Bags',
        'store' => env('LB_SHOP',env('SHOPIFY_STORE')),
        'client_id' => env('LB_CLIENT_ID',env('SHOPIFY_CLIENT_ID')),
        'client_secret' => env('LB_CLIENT_SECRET',env('SHOPIFY_CLIENT_SECRET')),
        'api_version' => env('LB_API_VERSION',env('SHOPIFY_API_VERSION','2026-07')),
    ],

    'partners'=>[
        'lucky'=>['enabled'=>true,'label'=>'Lucky','prefix'=>'LB','drive_folder'=>env('LB_DRIVE_FOLDER','LuckyBags'),'drive_root_id'=>env('GOOGLE_DRIVE_ARTWORK_ROOT_ID')],
        'vibe'=>['enabled'=>(bool)env('VIBE_SHOP'),'label'=>'Vibe','prefix'=>'VB','drive_layout'=>env('VIBE_DRIVE_LAYOUT','series'),'drive_folder'=>env('VIBE_DRIVE_FOLDER','Vibe'),'drive_root_id'=>env('VIBE_DRIVE_ARTWORK_ROOT_ID'),'backing_folder_map'=>json_decode(env('VIBE_BACKING_SWATCH_FOLDER_MAP','{}'),true)?:[],'shopify'=>['store'=>env('VIBE_SHOP'),'client_id'=>env('VIBE_CLIENT_ID'),'client_secret'=>env('VIBE_CLIENT_SECRET'),'api_version'=>env('VIBE_API_VERSION','2026-07'),'artwork_collection'=>env('VIBE_ARTWORK_COLLECTION','All Bags')]],
    ],
    'bag_product_types'=>['lucky'=>array_filter(explode('|',env('LB_BAG_PRODUCT_TYPES','ACL Pro Stamped 2026|ACL Pro Stamped 2027|ACL Pro Stamped 2028'))),'vibe'=>array_filter(explode('|',env('VIBE_BAG_PRODUCT_TYPES','Vibe Bags')))],

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
        'factory_root_id' => env('GOOGLE_DRIVE_FACTORY_ROOT_ID'),
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
