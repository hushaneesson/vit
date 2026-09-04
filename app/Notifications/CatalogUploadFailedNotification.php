<?php

namespace App\Notifications;

use App\Models\CatalogSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the admin when a VIT API upload attempt fails, with
 * a link into the Filament submission detail view for retry.
 */
class CatalogUploadFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected CatalogSubmission $submission) {
        $this->onQueue('notifications');}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('VIT catalog upload failed — retry needed')
            ->greeting('Upload failed')
            ->line('Vendor: ' . $this->submission->vendor?->name)
            ->line('Catalog: Submission #' . $this->submission->id)
            ->line('Attempt #' . $this->submission->upload_attempts)
            ->line('Error: ' . $this->submission->last_upload_error)
            ->action('Review & Retry', url('/admin/catalog-submissions/' . $this->submission->id . '/edit'));
    }
}
