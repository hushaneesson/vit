<?php

namespace App\Filament\Resources\Availabilities\Pages;

use App\Filament\Resources\Availabilities\AvailabilityResource;
use App\Models\Availability;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Zap\Enums\ScheduleTypes;

class EditAvailability extends EditRecord
{
    protected static string $resource = AvailabilityResource::class;

    protected array $periodPayload = [];

    protected array $additionalSchedules = [];

    protected string $groupId;

    /** @return array<int, array{day: string, start_time: string, end_time: string, enabled: bool}> */
    protected function defaultWeeklyPeriods(): array
    {
        return [
            ['day' => 'monday', 'start_time' => '09:00', 'end_time' => '17:00', 'enabled' => false],
            ['day' => 'tuesday', 'start_time' => '09:00', 'end_time' => '17:00', 'enabled' => false],
            ['day' => 'wednesday', 'start_time' => '09:00', 'end_time' => '17:00', 'enabled' => false],
            ['day' => 'thursday', 'start_time' => '09:00', 'end_time' => '17:00', 'enabled' => false],
            ['day' => 'friday', 'start_time' => '09:00', 'end_time' => '17:00', 'enabled' => false],
            ['day' => 'saturday', 'start_time' => '09:00', 'end_time' => '17:00', 'enabled' => false],
            ['day' => 'sunday', 'start_time' => '09:00', 'end_time' => '17:00', 'enabled' => false],
        ];
    }

    protected function groupSchedules(?string $groupId): Collection
    {
        if (! $groupId) {
            return collect([$this->record]);
        }

        return Availability::query()
            ->where('schedulable_type', User::class)
            ->where('schedulable_id', $this->record->schedulable_id)
            ->where('metadata->availability_group', $groupId)
            ->with('periods')
            ->get();
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $groupId = data_get($this->record->metadata, 'availability_group');
        $schedules = $this->groupSchedules($groupId);

        $weeklyPeriods = collect($this->defaultWeeklyPeriods())
            ->keyBy('day');

        $schedules->each(function (Availability $schedule) use ($weeklyPeriods): void {
            $day = data_get($schedule->frequency_config, 'days.0');

            if (! is_string($day) || ! $weeklyPeriods->has($day)) {
                return;
            }

            $period = $schedule->periods->sortBy('id')->first();

            if (! $period) {
                return;
            }

            $weeklyPeriods->put($day, [
                'day' => $day,
                'start_time' => $period->start_time,
                'end_time' => $period->end_time,
                'enabled' => true,
            ]);
        });

        $primary = $schedules->sortBy('id')->first() ?? $this->record;

        $data['weekly_periods'] = array_values($weeklyPeriods->all());
        $data['slot_duration_minutes'] = (int) data_get($primary->metadata, 'slot_duration_minutes', 30);
        $data['buffer_minutes'] = (int) data_get($primary->metadata, 'buffer_minutes', 0);
        $data['max_appointments_per_day'] = (int) data_get($primary->metadata, 'max_appointments_per_day', 10);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
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

        $this->groupId = data_get($this->record->metadata, 'availability_group', (string) Str::uuid());
        $primaryPeriod = $enabledPeriods[0];

        $this->periodPayload = [
            'date' => $data['start_date'],
            'start_time' => $primaryPeriod['start_time'],
            'end_time' => $primaryPeriod['end_time'],
            'is_available' => true,
        ];

        $this->additionalSchedules = collect(array_slice($enabledPeriods, 1))
            ->map(function (array $period) use ($data): array {
                return [
                    'schedule' => [
                        'schedulable_type' => User::class,
                        'schedulable_id' => $data['schedulable_id'],
                        'name' => $data['name'],
                        'description' => $data['description'] ?? null,
                        'schedule_type' => ScheduleTypes::AVAILABILITY->value,
                        'start_date' => $data['start_date'],
                        'end_date' => $data['end_date'] ?? null,
                        'is_recurring' => true,
                        'frequency' => 'weekly',
                        'frequency_config' => ['days' => [$period['day']]],
                        'metadata' => [
                            'availability_group' => $this->groupId,
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
            'availability_group' => $this->groupId,
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

    protected function afterSave(): void
    {
        $this->record->periods()->delete();

        if (! empty($this->periodPayload)) {
            $this->record->periods()->create($this->periodPayload);
        }

        Availability::query()
            ->where('id', '!=', $this->record->id)
            ->where('schedulable_type', User::class)
            ->where('schedulable_id', $this->record->schedulable_id)
            ->where('metadata->availability_group', $this->groupId)
            ->each(function (Availability $schedule): void {
                $schedule->delete();
            });

        foreach ($this->additionalSchedules as $payload) {
            $schedule = Availability::query()->create($payload['schedule']);
            $schedule->periods()->create($payload['period']);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
