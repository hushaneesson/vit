<?php

namespace App\Filament\Resources\Availabilities\Pages;

use App\Filament\Resources\Availabilities\AvailabilityResource;
use App\Models\Availability;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Zap\Enums\ScheduleTypes;

class CreateAvailability extends CreateRecord
{
    protected static string $resource = AvailabilityResource::class;

    protected array $periodPayload = [];

    protected array $additionalSchedules = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $enabledPeriods = collect($data['weekly_periods'] ?? [])
            ->filter(fn(array $row): bool => (bool) ($row['enabled'] ?? false))
            ->values()
            ->all();

        if ($enabledPeriods === []) {
            throw ValidationException::withMessages([
                'weekly_periods' => 'Enable at least one day in Weekly periods.',
            ]);
        }

        $groupId = (string) Str::uuid();
        $primaryPeriod = $enabledPeriods[0];

        $this->periodPayload = [
            'date' => $data['start_date'],
            'start_time' => $primaryPeriod['start_time'],
            'end_time' => $primaryPeriod['end_time'],
            'is_available' => true,
        ];

        $this->additionalSchedules = collect(array_slice($enabledPeriods, 1))
            ->map(function (array $period) use ($data, $groupId): array {
                return [
                    'schedule' => [
                        'schedulable_type' => User::class,
                        'schedulable_id' => $data['schedulable_id'],
                        'name' => "Office Availability",
                        'description' => $data['description'] ?? null,
                        'schedule_type' => ScheduleTypes::AVAILABILITY->value,
                        'start_date' => $data['start_date'],
                        'end_date' => $data['end_date'] ?? null,
                        'is_recurring' => true,
                        'frequency' => 'weekly',
                        'frequency_config' => ['days' => [$period['day']]],
                        'metadata' => [
                            'availability_group' => $groupId,
                            'slot_duration_minutes' => (int) ($data['slot_duration_minutes'] ?? 30),
                            'buffer_minutes' => (int) ($data['buffer_minutes'] ?? 0),
                            'max_appointments_per_day' => (int) ($data['max_appointments_per_day'] ?? 10),
                        ],
                        'is_active' => true,
                    ],
                    'period' => [
                        'date' => $data['start_date'],
                        'start_time' => $period['start_time'],
                        'end_time' => $period['end_time'],
                        'is_available' => true,
                    ],
                ];
            })
            ->all();

        $data['schedulable_type'] = User::class;
        $data['schedule_type'] = ScheduleTypes::AVAILABILITY->value;
        $data['is_recurring'] = true;
        $data['is_active'] = true;
        $data['frequency'] = 'weekly';
        $data['frequency_config'] = ['days' => [$primaryPeriod['day']]];

        $data['metadata'] = [
            'availability_group' => $groupId,
            'slot_duration_minutes' => (int) ($data['slot_duration_minutes'] ?? 30),
            'buffer_minutes' => (int) ($data['buffer_minutes'] ?? 0),
            'max_appointments_per_day' => (int) ($data['max_appointments_per_day'] ?? 10),
        ];

        unset(
            $data['weekly_periods'],
            $data['slot_duration_minutes'],
            $data['buffer_minutes'],
            $data['max_appointments_per_day']
        );

        return $data;
    }

    protected function afterCreate(): void
    {
        if (! empty($this->periodPayload)) {
            $this->record->periods()->create($this->periodPayload);
        }

        foreach ($this->additionalSchedules as $payload) {
            $schedule = Availability::query()->create($payload['schedule']);
            $schedule->periods()->create($payload['period']);
        }
    }
}
