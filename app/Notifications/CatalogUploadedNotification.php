<?php

namespace App\Notifications;

use App\Models\CatalogSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the vendor (and cc'd to admin via a separate route) when a
 * submission has been successfully delivered to VIT's API.
 */
class CatalogUploadedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected CatalogSubmission $submission) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Catalog successfully uploaded to VIT')
            ->greeting('Good news!')
            ->line('Catalog has been successfully uploaded to VIT.')
            ->line('Product count: ' . $this->submission->product_count)
            ->line('Uploaded at: ' . optional($this->submission->uploaded_at)->format('Y-m-d H:i'))
            ->action('View Submission', url('/vendor/dashboard'));
    }
}
