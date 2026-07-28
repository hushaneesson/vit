<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to system administrators when a vendor client requests review
 * of their catalog items.
 *
 * Follows the same pattern as CatalogUploadFailedNotification — a simple
 * email with key details and a link to the admin review page.
 */
class CatalogReadyForReviewNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $vendorName     Name of the vendor requesting review
     */
    public function __construct(
        protected string $vendorName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Catalog review requested for {$this->vendorName}")
            ->greeting('Catalog Review Requested')
            ->line("Vendor **{$this->vendorName}** has submitted their catalog for review.")
            ->line('')
            ->line('Please review the catalog and approve or reject the submission.')
            ->action('Review Catalog', route('filament.admin.resources.catalog-submissions.index'));
    }
}
