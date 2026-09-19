<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the vendor client when the parent ProcessCatalogUploadJob itself
 * fails (staging/chunk-dispatch failure), as opposed to per-row validation
 * issues (which use CatalogUploadValidationReportNotification) or the
 * VIT-API submission flow (which uses CatalogUploadFailedNotification).
 */
class CatalogImportFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected int $catalogId,
        protected int $catalogUploadId,
        protected ?string $failureReason = null,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reportUrl = route('vendor.catalog-upload.summary', [
            'catalogId' => $this->catalogId,
            'catalogUpload' => $this->catalogUploadId,
        ]);

        $mail = (new MailMessage)
            ->subject('Catalog import failed — action needed')
            ->greeting('Catalog import failed')
            ->line('Your catalog import (upload #'.$this->catalogUploadId.') could not be processed due to a system error.')
            ->line('No validation report was generated because the file could not be staged for processing.');

        if ($this->failureReason) {
            $mail->line('Error: '.$this->failureReason);
        }

        return $mail
            ->action('View import report', $reportUrl)
            ->line('You can review the failure details and upload a corrected file.')
            ->salutation('— VIT System');
    }
}
