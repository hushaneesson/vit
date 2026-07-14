<?php

namespace App\Filament\Pages;

use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use UnitEnum;

class AvailabilityCalendar extends Page
{
    protected string $view = 'filament.pages.availability-calendar';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    protected static string|UnitEnum|null $navigationGroup = 'Appointments';

    public int $month;
    public int $year;
    public array $schedules = [];
    public $user;

    public ?string $selectedDate = null;
    public array $selectedSlots = [];

    public function mount()
    {
        $this->month = now()->month;
        $this->year = now()->year;

        $this->user = auth()->user();

        $this->schedules = $this->user->schedules()
            ->where('schedule_type', 'availability')
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(function ($schedule) {
                return [$schedule->frequency_config->days[0] => $schedule->metadata];
            })
            ->toArray();
    }


    public function previousMonth()
    {
        $date = Carbon::create($this->year, $this->month)
            ->subMonth();

        $this->month = $date->month;
        $this->year = $date->year;
    }


    public function nextMonth()
    {
        $date = Carbon::create($this->year, $this->month)
            ->addMonth();

        $this->month = $date->month;
        $this->year = $date->year;
    }


    public function getCalendarProperty()
    {
        $start = Carbon::create(
            $this->year,
            $this->month,
            1
        )->startOfWeek(0);


        $end = Carbon::create($this->year, $this->month, 1)->endOfMonth()->endOfWeek();

        $days = [];

        while ($start <= $end) {
            $days[] = [
                'date' => $start->copy(),
                'slots' => $this->getAvailableSlots($start->format('Y-m-d')),
            ];

            $start->addDay();
        }

        return $days;
    }


    public function getAvailableSlots(string $date)
    {
        $carbon = Carbon::parse($date);
        $dayKey = strtolower($carbon->format('l')); // 'monday', 'tuesday' etc.

        // Day not enabled in schedule
        if (!isset($this->schedules[$dayKey])) {
            return [];
        }

        // Daily cap already hit
        // if ($this->isDailyLimitReached($user, $carbon)) {
        //     return [];
        // }

        // // Weekly cap already hit
        // if ($this->isWeeklyLimitReached($user, $carbon)) {
        //     return [];
        // }

        // Zap handles the rest: existing appointments, blocked periods, buffer
        $slots = $this->user->getBookableSlots(
            $date,
            $this->schedules[$dayKey]['slot_duration_minutes'],
            $this->schedules[$dayKey]['buffer_minutes'],
        );

        if (!empty($slots)) {
            return collect($slots)
                ->filter(function ($slot) {
                    return $slot['is_available'];
                })
                ->map(function ($slot) {
                    return [
                        Carbon::parse($slot['start_time'])->format('h:i A') . ' - ' . Carbon::parse($slot['end_time'])->format('h:i A'),
                    ];
                })
                ->flatten()
                ->toArray();
        }

        return $slots;
    }

    public function viewSlots(string $date): void
    {
        $day = collect($this->calendar)
            ->first(function ($item) use ($date) {
                return $item['date']->format('Y-m-d') === $date;
            });


        $this->selectedDate = $date;
        $this->selectedSlots = $day['slots'] ?? [];


        $this->dispatch('open-modal', id: 'day-slots');
    }
}
