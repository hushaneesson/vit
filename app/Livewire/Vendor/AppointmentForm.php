<?php

namespace App\Livewire\Vendor;

use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Component;

class AppointmentForm extends Component
{
    public int $month;
    public int $year;
    public array $schedules = [];
    public $user;

    public ?string $selectedDate = null;
    public array $selectedSlots = [];
    public bool $showModal = false;

    public function render()
    {
        return view('livewire.vendor.appointment-form');
    }

    public function mount()
    {
        $this->month = now()->month;
        $this->year = now()->year;

        $this->user = User::first();

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
        $date = Carbon::create($this->year, $this->month)->subMonth();

        $this->month = $date->month;
        $this->year = $date->year;
    }

    public function nextMonth()
    {
        $date = Carbon::create($this->year, $this->month)->addMonth();

        $this->month = $date->month;
        $this->year = $date->year;
    }

    public function getCalendarProperty()
    {
        $start = Carbon::create($this->year, $this->month, 1)->startOfWeek(0);
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
        $dayKey = strtolower($carbon->format('l'));

        if (!isset($this->schedules[$dayKey])) {
            return [];
        }

        $slots = $this->user->getBookableSlots(
            $date,
            $this->schedules[$dayKey]['slot_duration_minutes'],
            $this->schedules[$dayKey]['buffer_minutes'],
        );

        if (!empty($slots)) {
            return collect($slots)
                ->filter(fn($slot) => $slot['is_available'])
                ->map(fn($slot) => Carbon::parse($slot['start_time'])->format('h:i A')
                    . ' - '
                    . Carbon::parse($slot['end_time'])->format('h:i A'))
                ->toArray();
        }

        return $slots;
    }

    public function viewSlots(string $date): void
    {
        $day = collect($this->calendar)
            ->first(fn($item) => $item['date']->format('Y-m-d') === $date);

        $this->selectedDate = $date;
        $this->selectedSlots = $day['slots'] ?? [];
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }
}
