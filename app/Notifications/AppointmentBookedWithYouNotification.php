<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class AppointmentBookedWithYouNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $clientName,
        protected string $vendorName,
        protected string $date,
        protected string $startsAt,
        protected string $endsAt,
        protected string $appointmentReason,
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
            ->subject('New Appointment Booked')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('A new appointment has been booked with you.')
            ->line('Client: ' . $this->clientName)
            ->line('Vendor: ' . $this->vendorName)
            ->line('Date: ' . $start->format('l, F j, Y'))
            ->line('Time: ' . $start->format('h:i A') . ' - ' . $end->format('h:i A'))
            ->line('Reason for appointment: ' . $this->appointmentReason);
    }
}
