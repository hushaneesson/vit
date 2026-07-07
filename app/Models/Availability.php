<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Zap\Enums\ScheduleTypes;
use Zap\Models\Schedule;

class Availability extends Schedule
{
    protected static function booted(): void
    {
        static::addGlobalScope('availabilities_only', function (Builder $query): void {
            $query->where('schedule_type', ScheduleTypes::AVAILABILITY->value);
        });

        static::creating(function (self $availability): void {
            $availability->schedule_type = ScheduleTypes::AVAILABILITY;
        });
    }
}
