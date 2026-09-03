<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the vendor client when an admin reviews their catalog submission.
 *
 * For approved submissions, the notification confirms approval and mentions
 * that the export is being generated.
 *
 * For rejected submissions, the notification includes the admin's rejection
 * reason so the vendor knows what to fix before resubmitting.
 */
class CatalogSubmissionReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string      $vendorName      Name of the vendor
     * @param  string      $status          'approved' or 'rejected'
     * @param  string|null $rejectionReason Reason for rejection (null if approved)
     */
    public function __construct(
        protected string $vendorName,
        protected string $status,
        protected ?string $rejectionReason = null,
    ) {
        $this->onQueue('notifications');}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->status === 'approved') {
            return (new MailMessage)
                ->subject("Catalog processing: {$this->vendorName}")
                ->greeting('Catalog Processing')
                ->line("Your catalog is being processed.")
                ->line('The Excel export is now being generated. You will be notified when it is ready.')
                ->salutation('— VIT System');
        }

        return (new MailMessage)
            ->subject("Catalog processing update: {$this->vendorName}")
            ->greeting('Catalog Processing Update')
            ->line("Your catalog submission could not be processed at this time.")
            ->line('')
            ->line('**Reason:**')
            ->line($this->rejectionReason ?? 'No specific reason provided.')
            ->line('')
            ->line('Please review the information above, make the necessary corrections, and submit your catalog again.')
            ->salutation('— VIT System');
    }
}
