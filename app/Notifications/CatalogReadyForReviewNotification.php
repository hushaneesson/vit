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
     * @param  int     $vendorId       ID of the vendor requesting review
     */
    public function __construct(
        protected int $vendorId,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vendor = \App\Models\Vendor::find($this->vendorId);
        $vendorName = $vendor && is_string($vendor->name) ? $vendor->name : 'Unknown Vendor';

        $mail = new MailMessage;
        $mail->subject('Catalog review requested');
        $mail->greeting('Catalog Review Requested');
        $mail->line('Vendor ' . $vendorName . ' has submitted their catalog for review.');
        $mail->line('');
        $mail->line('Please review the catalog and approve or reject the submission.');
        $mail->action('Review Catalog', url('/admin/catalog-submissions'));

        return $mail;
    }
}
