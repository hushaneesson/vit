<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class ClientAppointmentBookedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $bookedWithName,
        protected string $date,
        protected string $startsAt,
        protected string $endsAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $start = Carbon::parse($this->date . ' ' . $this->startsAt);
        $end = Carbon::parse($this->date . ' ' . $this->endsAt);

        return (new MailMessage)
            ->subject('Appointment Confirmation')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Your appointment has been booked successfully.')
            ->line('Booked with: ' . $this->bookedWithName)
            ->line('Date: ' . $start->format('l, F j, Y'))
            ->line('Time: ' . $start->format('h:i A') . ' - ' . $end->format('h:i A'))
            ->line('If you need to make changes, please contact your vendor representative.');
    }
}
