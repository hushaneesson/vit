<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin Email for Internal Notifications
    |--------------------------------------------------------------------------
    |
    | System administrator email address used for internal notifications such
    | as catalog review requests and other admin-facing alerts.
    |
    */
    'admin_email' => env('VIT_ADMIN_EMAIL', 'admin@example.com'),

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
