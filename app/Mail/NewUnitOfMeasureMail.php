<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Notifications\Messages\MailMessage;

class NewUnitOfMeasureMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $unitOfMeasureCode,
        public string $unitOfMeasureDescription,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New unit of measure submitted - action required',
        );
    }

    public function build(): static
    {
        $message = (new MailMessage)
            ->greeting('Hi,')
            ->line('A vendor has submitted a unit of measure that does not yet exist in your database.')
            ->line('Unit of measure code: ' . $this->unitOfMeasureCode)
            ->line('Unit of measure description: ' . $this->unitOfMeasureDescription)
            ->line('Please update your database accordingly and approve the new unit of measure on the Vendor Portal.');

        return $this->html(
            (string) $message->render(),
        );
    }
}
