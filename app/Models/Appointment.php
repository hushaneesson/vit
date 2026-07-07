<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Zap\Enums\ScheduleTypes;
use Zap\Models\Schedule;

class Appointment extends Schedule
{
    protected static function booted(): void
    {
        static::addGlobalScope('appointments_only', function (Builder $query): void {
            $query->where('schedule_type', ScheduleTypes::APPOINTMENT->value);
        });

        static::creating(function (self $appointment): void {
            $appointment->schedule_type = ScheduleTypes::APPOINTMENT;
        });
    }
}
