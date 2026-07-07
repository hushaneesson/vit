<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a vendor's classification free-text key doesn't match any
 * known classification_types row (Phase 6).
 */
class NewClassificationTypeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $vendorName,
        protected string $catalogName,
        protected string $classificationKey,
        protected string $classificationValue,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New product classification type submitted — action required')
            ->greeting('New classification type detected')
            ->line('A vendor has submitted a classification type that does not yet exist in the reference table.')
            ->line('Vendor: '.$this->vendorName)
            ->line('Catalog: '.$this->catalogName)
            ->line('Classification key: '.$this->classificationKey)
            ->line('Submitted value: '.$this->classificationValue)
            ->line('Please review and, if valid, add this classification type to the reference data.');
    }
}
