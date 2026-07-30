<?php

namespace App\Console\Commands;

use App\Models\CatalogSubmission;
use App\Notifications\CatalogReadyForReviewNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class ResendReviewNotifications extends Command
{
    protected $signature = 'notifications:resend-review
                            {--vendor= : Resend for a specific vendor ID}
                            {--submission= : Resend for a specific submission ID}
                            {--dry-run : Show what would be sent without actually sending}';

    protected $description = 'Resend catalog review request notifications for pending submissions';

    public function handle(): int
    {
        $query = CatalogSubmission::query()
            ->where('status', 'review_requested')
            ->with(['vendor', 'requestedByClient']);

        if ($this->option('vendor')) {
            $query->where('vendor_id', $this->option('vendor'));
        }

        if ($this->option('submission')) {
            $query->where('id', $this->option('submission'));
        }

        $submissions = $query->get();

        if ($submissions->isEmpty()) {
            $this->info('No pending review requests found.');
            return 0;
        }

        $this->info("Found {$submissions->count()} submission(s) pending notification:");
        $this->table(
            ['ID', 'Vendor', 'Client Email', 'Requested At'],
            $submissions->map(fn($s) => [
                $s->id,
                $s->vendor->name ?? 'Unknown',
                $s->requestedByClient->email ?? 'N/A',
                $s->requested_at?->format('Y-m-d H:i') ?? 'N/A',
            ])->toArray()
        );

        if ($this->option('dry-run')) {
            $this->warn('Dry run complete. No emails were sent.');
            return 0;
        }

        $this->line('');
        if (!$this->confirm('Send notifications for these submissions?', true)) {
            $this->info('Cancelled.');
            return 0;
        }

        $adminEmail = config('vit.admin_email');

        foreach ($submissions as $submission) {
            if (!$adminEmail) {
                $this->error('Admin email not configured in vit.admin_email');
                return 1;
            }

            $totalItems = $submission->total_items;
            $completeItems = $submission->complete_items;
            $incompleteItems = $submission->incomplete_items;

            try {
                $vendorName = is_string($submission->vendor->name) ? $submission->vendor->name : 'Unknown Vendor';

                Notification::route('mail', $adminEmail)
                    ->notify(new CatalogReadyForReviewNotification(
                        vendorId: $submission->vendor_id,
                        vendorName: $vendorName,
                    ));

                $this->info("✓ Sent notification for submission #{$submission->id}");
            } catch (\Throwable $e) {
                $this->error("✗ Failed for submission #{$submission->id}: {$e->getMessage()}");
            }
        }

        $this->info('Done.');
        return 0;
    }
}
