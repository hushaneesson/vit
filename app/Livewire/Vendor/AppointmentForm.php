<?php

namespace App\Livewire\Vendor;

use App\Models\Client;
use App\Models\User;
use App\Notifications\AppointmentBookedWithYouNotification;
use App\Notifications\ClientAppointmentBookedNotification;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Zap\Facades\Zap;

class AppointmentForm extends Component
{
    public int $month;
    public int $year;
    public array $schedules = [];
    public $user;

    public ?string $selectedDate = null;
    public array $selectedSlots = [];
    public bool $showModal = false;

    // New: slot confirmation state
    public bool $showConfirmModal = false;
    public ?string $pendingDate = null;
    public ?string $pendingSlotLabel = null; // e.g. "09:00 AM - 09:30 AM"
    public string $appointmentReason = '';

    public bool $isSaving = false;

    public function render()
    {
        return view('livewire.vendor.appointment-form');
    }

    public function mount()
    {
        $this->month = now()->month;
        $this->year = now()->year;

        $this->user = User::where('has_appointments', true)->first();

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

        // Do not show availability for past dates
        if ($carbon->isPast() && !$carbon->isToday()) {
            return [];
        }

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
                ->filter(function ($slot) use ($date) {

                    if (! $slot['is_available']) {
                        return false;
                    }

                    $slotDateTime = Carbon::parse(
                        $date . ' ' . $slot['start_time']
                    );

                    return $slotDateTime->greaterThan(now());
                })
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

    /**
     * Called when a slot button is clicked (from either the day grid or the "more" modal).
     */
    public function selectSlot(string $date, string $slotLabel): void
    {
        $this->pendingDate = $date;
        $this->pendingSlotLabel = $slotLabel;

        // Close the day-slots modal if it was open, and show the confirm modal on top.
        $this->showModal = false;
        $this->showConfirmModal = true;
    }

    public function closeConfirmModal(): void
    {
        $this->showConfirmModal = false;
        $this->pendingDate = null;
        $this->pendingSlotLabel = null;
        $this->appointmentReason = '';
    }

    public function confirmAppointment()
    {

        if (!$this->pendingDate || !$this->pendingSlotLabel) {
            return;
        }

        $validated = $this->validate([
            'appointmentReason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $reason = trim($validated['appointmentReason']);


        $this->isSaving = true;

        // "09:00 AM - 09:30 AM" -> ["09:00 AM", "09:30 AM"]
        [$startLabel, $endLabel] = array_map('trim', explode('-', $this->pendingSlotLabel));

        $startsAt = Carbon::parse($this->pendingDate . ' ' . $startLabel)->format('H:i');
        $endsAt = Carbon::parse($this->pendingDate . ' ' . $endLabel)->format('H:i');
        $dayKey = strtolower(Carbon::parse($this->pendingDate)->format('l'));

        // check again that the slot is available before saving.
        if (!$this->user->isBookableAtTime($this->pendingDate, $startsAt, $endsAt, null, $this->schedules[$dayKey]['slot_duration_minutes'], $this->schedules[$dayKey]['buffer_minutes'])) {
            $this->isSaving = false;
            return;
        }


        // create the appointment
        Zap::for($this->user)
            ->named("Appointment with " . auth('client')->user()->name)
            ->description(auth('client')->user()->vendor->name)
            ->appointment()
            ->from($this->pendingDate)
            ->addPeriod($startsAt, $endsAt)
            ->noOverlap()
            ->withMetadata([
                'client_id' => auth('client')->user()->id,
                'appointment_reason' => $reason,
            ])
            ->save();

        $client = auth('client')->user();

        if (! $client instanceof Client) {
            $this->isSaving = false;

            return;
        }

        $client->notify(new ClientAppointmentBookedNotification(
            $this->user->name,
            $this->pendingDate,
            $startsAt,
            $endsAt,
            $reason,
        ));

        $this->user->notify(new AppointmentBookedWithYouNotification(
            $client->name,
            $client->vendor->name,
            $this->pendingDate,
            $startsAt,
            $endsAt,
            $reason,
        ));

        $this->isSaving = false;
        $this->showConfirmModal = false;
        $this->appointmentReason = '';

        return redirect()
            ->route('vendor.appointments.index')
            ->with('status', 'Appointment booked successfully!');
    }
}
