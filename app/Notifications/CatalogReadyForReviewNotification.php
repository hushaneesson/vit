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
     * @param  string  $catalogName    Name of the catalog being submitted
     * @param  int     $totalItems     Total catalog items for this vendor
     * @param  int     $completeItems  Items with acceptable/excellent status
     * @param  int     $incompleteItems Items with incomplete status
     */
    public function __construct(
        protected string $vendorName,
        protected string $catalogName,
        protected int $totalItems,
        protected int $completeItems,
        protected int $incompleteItems,
        protected int $submissionId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $completenessPercent = $this->totalItems > 0
            ? round(($this->completeItems / $this->totalItems) * 100)
            : 0;

        return (new MailMessage)
            ->subject("Catalog review requested: {$this->vendorName} — {$this->catalogName}")
            ->greeting('Catalog Review Requested')
            ->line("Vendor **{$this->vendorName}** has submitted their catalog **{$this->catalogName}** for review.")
            ->line('')
            ->line('**Catalog Summary:**')
            ->line("- Total items: {$this->totalItems}")
            ->line("- Complete (exportable): {$this->completeItems}")
            ->line("- Incomplete: {$this->incompleteItems}")
            ->line("- Completeness: {$completenessPercent}%")
            ->line('')
            ->line('Please review the catalog and approve or reject the submission.')
            ->action('Review Catalog', route('filament.admin.resources.catalog-submissions.edit', $this->submissionId));
    }
}
