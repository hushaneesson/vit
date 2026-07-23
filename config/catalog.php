<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Processing Timeout (Minutes)
    |--------------------------------------------------------------------------
    |
    | The number of minutes before a processing job is considered stale
    | and can be safely claimed by another worker for recovery.
    |
    | */
    'processing_timeout_minutes' => env('CATALOG_PROCESSING_TIMEOUT_MINUTES', 60),
];
