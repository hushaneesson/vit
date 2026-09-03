<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a vendor submits a Category Level 1!2!3 combination that does
 * not yet exist in the product_hierarchies reference table (Phase 6).
 */
class NewHierarchyPathNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $vendorName,
        protected string $catalogName,
        protected string $path,
    ) {
        $this->onQueue('notifications');}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New product hierarchy path submitted — action required')
            ->greeting('New hierarchy path detected')
            ->line('A vendor has submitted a Category Level 1!2!3 combination that does not yet exist in the reference table.')
            ->line('Vendor: '.$this->vendorName)
            ->line('Catalog: '.$this->catalogName)
            ->line('Path: '.$this->path)
            ->line('Please add this path (with its VIT hierarchy number) to the Product Hierarchies reference data so this vendor\'s submission can be completed.');
    }
}
