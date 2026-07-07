<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VIT Gateway Notification Address
    |--------------------------------------------------------------------------
    |
    | New/unrecognized hierarchy paths and classification types are emailed
    | here automatically (Phase 6), per VIT's onboarding requirements.
    |
    */
    'gateway_email' => env('VIT_GATEWAY_EMAIL', 'elinkgateway@valueinnovationtech.com'),

    /*
    |--------------------------------------------------------------------------
    | VIT Catalog Upload API
    |--------------------------------------------------------------------------
    |
    | Placeholder endpoint until VIT provides real production credentials.
    | Do not hardcode secrets here — always via .env.
    |
    */
    'api' => [
        'endpoint' => env('VIT_API_ENDPOINT', 'https://api.vit-placeholder.com/v1/catalogs/upload'),
        'token' => env('VIT_API_TOKEN'),
        'timeout' => (int) env('VIT_API_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Retry Schedule (Phase 11)
    |--------------------------------------------------------------------------
    |
    | Hours after a failed upload at which the scheduler should automatically
    | re-attempt delivery. Configurable without code changes.
    |
    */
    'retry_after_hours' => array_map(
        'intval',
        array_filter(explode(',', env('VIT_RETRY_AFTER_HOURS', '1,6,24')))
    ),

    /*
    |--------------------------------------------------------------------------
    | Excel Image Embedding (Phase 7)
    |--------------------------------------------------------------------------
    */
    'excel' => [
        'image_size' => (int) env('VIT_EXCEL_IMAGE_SIZE', 400), // px, square
        'image_padding' => (int) env('VIT_EXCEL_IMAGE_PADDING', 8), // px between stacked images
    ],
];
