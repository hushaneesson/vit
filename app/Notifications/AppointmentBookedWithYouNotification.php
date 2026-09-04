<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
    ) {
        $this->onQueue('notifications');}

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
            ->line('A calendar invite is attached. Accept it to add this appointment to your calendar.')
            ->line('Client: ' . $this->clientName)
            ->line('Vendor: ' . $this->vendorName)
            ->line('Date: ' . $start->format('l, F j, Y'))
            ->line('Time: ' . $start->format('h:i A') . ' - ' . $end->format('h:i A'))
            ->line('Reason for appointment: ' . $this->appointmentReason)
            ->attachData(
                $this->buildCalendarInvite($start, $end, (string) ($notifiable->email ?? '')),
                'appointment-invite.ics',
                ['mime' => 'text/calendar; charset=UTF-8; method=REQUEST']
            );
    }

    protected function buildCalendarInvite(Carbon $start, Carbon $end, string $attendeeEmail): string
    {
        $uid = sprintf(
            'appointment-%s@%s',
            Str::uuid()->toString(),
            parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost'
        );

        $dtStamp = now()->utc()->format('Ymd\\THis\\Z');
        $dtStart = $start->copy()->utc()->format('Ymd\\THis\\Z');
        $dtEnd = $end->copy()->utc()->format('Ymd\\THis\\Z');
        $organizerEmail = config('mail.from.address', 'noreply@example.com');
        $organizerName = config('mail.from.name', config('app.name', 'VIT'));

        $summary = $this->escapeIcsText('Appointment with ' . $this->clientName);
        $description = $this->escapeIcsText(
            'Vendor: ' . $this->vendorName . "\n"
                . 'Reason for appointment: ' . $this->appointmentReason
        );

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'PRODID:-//VIT//Appointments//EN',
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $dtStamp,
            'DTSTART:' . $dtStart,
            'DTEND:' . $dtEnd,
            'SUMMARY:' . $summary,
            'DESCRIPTION:' . $description,
            'STATUS:CONFIRMED',
            'SEQUENCE:0',
            'ORGANIZER;CN=' . $this->escapeIcsText((string) $organizerName) . ':MAILTO:' . $organizerEmail,
            'ATTENDEE;CN=' . $this->escapeIcsText((string) ($notifiable->name ?? 'Attendee')) . ';RSVP=TRUE:MAILTO:' . $attendeeEmail,
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    protected function escapeIcsText(string $text): string
    {
        return str_replace(
            ["\\", ";", ",", "\n", "\r"],
            ["\\\\", "\\;", "\\,", '\\n', ''],
            $text
        );
    }
}
