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

    /*
    |--------------------------------------------------------------------------
    | Periodic Database Reset (demo/staging environments only)
    |--------------------------------------------------------------------------
    |
    | When enabled, `elink:reset-database` (scheduled weekly) wipes and
    | reseeds the database. Must be explicitly opted into per-environment —
    | never enable this in production.
    |
    */
    'allow_db_reset' => env('ALLOW_DB_RESET', false),
];
