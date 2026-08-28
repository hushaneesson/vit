<?php

namespace App\Filament\Pages;

use App\Filament\Schemas\AvailabilityForm;
use App\Models\Availability;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;
use Zap\Enums\ScheduleTypes;

class AvailabilitySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsVertical;

    protected static ?string $navigationLabel = 'Availability Settings';

    protected static ?string $title = 'Availability Settings';

    protected static string|UnitEnum|null $navigationGroup = 'Appointments';

    protected string $view = 'filament.pages.availability-settings';

    public ?array $data = [];

    protected ?string $groupId = null;


    public function mount(): void
    {
        $schedules = $this->availabilityQuery()
            ->with('periods')
            ->get();

        $weeklyPeriods = collect(AvailabilityForm::defaultWeeklyPeriods())
            ->keyBy('day');


        $schedules->each(function (Availability $schedule) use ($weeklyPeriods): void {

            $day = data_get($schedule->frequency_config, 'days.0');

            if (! is_string($day) || ! $weeklyPeriods->has($day)) {
                return;
            }

            $period = $schedule->periods
                ->sortBy('id')
                ->first();

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


        $primary = $schedules
            ->sortBy('id')
            ->first();


        $this->groupId = data_get(
            $primary?->metadata,
            'availability_group'
        );


        $this->form->fill([
            'start_date' => $primary?->start_date,
            'end_date' => $primary?->end_date,

            'weekly_periods' => array_values(
                $weeklyPeriods->all()
            ),

            'slot_duration_minutes' => (int) data_get(
                $primary?->metadata,
                'slot_duration_minutes',
                45
            ),

            'buffer_minutes' => (int) data_get(
                $primary?->metadata,
                'buffer_minutes',
                15
            ),

            'max_appointments_per_day' => (int) data_get(
                $primary?->metadata,
                'max_appointments_per_day',
                5
            ),
        ]);
    }


    public function form(Schema $schema): Schema
    {
        return AvailabilityForm::configure($schema)
            ->statePath('data');
    }


    public function save(): void
    {
        $data = $this->form->getState();


        $enabledRows = collect($data['weekly_periods'] ?? [])
            ->filter(
                fn(array $row): bool =>
                (bool) ($row['enabled'] ?? false)
            );


        if ($enabledRows->isEmpty()) {
            throw ValidationException::withMessages([
                'data.weekly_periods' =>
                'Enable at least one day in Weekly periods.',
            ]);
        }


        $groupId = $this->groupId ??= (string) Str::uuid();


        $metadata = [
            'availability_group' => $groupId,

            'slot_duration_minutes' => (int) (
                $data['slot_duration_minutes'] ?? 30
            ),

            'buffer_minutes' => (int) (
                $data['buffer_minutes'] ?? 0
            ),

            'max_appointments_per_day' => (int) (
                $data['max_appointments_per_day'] ?? 10
            ),
        ];


        DB::transaction(function () use (
            $data,
            $enabledRows,
            $metadata
        ): void {


            $enabledDays = $enabledRows
                ->pluck('day')
                ->all();


            /*
             * Remove days that were disabled
             */
            $this->availabilityQuery()
                ->get()
                ->each(function (Availability $schedule) use ($enabledDays) {

                    $day = data_get(
                        $schedule->frequency_config,
                        'days.0'
                    );


                    if (! in_array($day, $enabledDays, true)) {
                        $schedule->delete();
                    }
                });



            /*
             * Create/update enabled days
             */
            foreach ($enabledRows as $row) {


                $attributes = [
                    'schedulable_type' => User::class,

                    'schedulable_id' => auth()->id(),

                    'name' => 'Office Availability',

                    'description' => $data['description'] ?? null,

                    'schedule_type' =>
                    ScheduleTypes::AVAILABILITY->value,

                    'start_date' =>
                    $data['start_date'],

                    'end_date' =>
                    $data['end_date'] ?? null,

                    'is_recurring' => true,

                    'frequency' => 'weekly',

                    'frequency_config' => [
                        'days' => [$row['day']],
                    ],

                    'metadata' => $metadata,

                    'is_active' => true,
                ];


                $periodAttributes = [

                    'date' =>
                    $data['start_date'],

                    'start_time' =>
                    $row['start_time'],

                    'end_time' =>
                    $row['end_time'],

                    'is_available' => true,
                ];



                $schedule = $this->availabilityQuery()
                    ->whereJsonContains(
                        'frequency_config->days',
                        $row['day']
                    )
                    ->first();



                if ($schedule) {

                    $schedule->update($attributes);

                    $schedule->periods()->delete();
                } else {

                    $schedule = Availability::create(
                        $attributes
                    );
                }



                $schedule->periods()
                    ->create($periodAttributes);
            }
        });


        $this->dispatch('notify', type: 'success', message: 'Availability saved');
    }



    protected function availabilityQuery()
    {
        return Availability::query()
            ->where(
                'schedulable_type',
                User::class
            )
            ->where(
                'schedulable_id',
                auth()->id()
            )
            ->where(
                'schedule_type',
                ScheduleTypes::AVAILABILITY->value
            );
    }



    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->submit('save'),
        ];
    }
}
