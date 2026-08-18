<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Notifications\Messages\MailMessage;

class NewCommodityTypeMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $categoryName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New product category submitted - action required',
        );
    }

    public function build(): static
    {
        $message = (new MailMessage)
            ->greeting('Hi,')
            ->line('A vendor has submitted a product category that does not yet exist in your database.')
            ->line('Product category: ' . $this->categoryName)
            ->line('Please update your database accordingly and approve the new category on the Vendor Portal.');

        return $this->html(
            (string) $message->render(),
        );
    }
}
