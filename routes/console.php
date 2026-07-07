<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 11: auto-retry failed VIT catalog uploads at configured intervals
// (VIT_RETRY_AFTER_HOURS in .env, default 1h/6h/24h since last attempt).
Schedule::command('vit:retry-failed-uploads')->hourly();

