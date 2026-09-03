<?php

namespace App\Jobs;

use App\Enums\CatalogSubmissionStatus;
use App\Models\CatalogSubmission;
use App\Notifications\CatalogReadyForReviewNotification;
use App\Services\Catalog\CatalogExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Generates a VIT-compliant .xlsx file from a catalog submission's
 * snapshot data and stores it on the configured disk.
 *
 * Flow:
 *   - Job receives catalogSubmissionId (dispatched from CatalogSubmissionButton::SubmitCatalog)
 *   - Loads CatalogSubmission and validates it has snapshot items
 *   - Updates processing_status to 'generating'
 *   - Calls CatalogExportService::generateFromSubmission() to build the Excel
 *   - On success: stores file_path, file_size, generated_at, sets
 *     processing_status = 'completed', status = 'ready_for_review'
 *   - On failure: sets processing_status = 'failed' with failure_reason
 *
 * This job runs asynchronously — the admin sees the submission in
 * 'ready_for_review' status once the Excel is ready for download.
 */
class GenerateCatalogExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800; // 30 minutes for large catalogs

    /**
     * Run on the dedicated exports queue so exports run concurrently with imports.
     *
     * @param int $catalogSubmissionId The submission to process
     */
    public function __construct(
        public int $catalogSubmissionId
    ) {
        $this->onQueue('exports');
    }

    /**
     * Execute the job.
     */
    public function handle(CatalogExportService $exportService): void
    {
        $submission = CatalogSubmission::findOrFail($this->catalogSubmissionId);
        $disk = $submission->disk ?? config('filesystems.default');

        // Idempotency: if already completed and file exists, do not regenerate
        if (
            $submission->processing_status === 'completed' &&
            $submission->file_path &&
            Storage::disk($disk)->exists($submission->file_path)
        ) {
            return;
        }

        // Update processing status to generating
        $submission->update([
            'processing_status' => 'generating',
            'generating_started_at' => now(),
        ]);

        try {
            $path = $exportService->generateFromSubmission($submission);

            $fileSize = Storage::disk($disk)->size($path);

            $submission->update([
                'file_path' => $path,
                'file_size' => $fileSize,
                'processing_status' => 'completed',
                'generated_at' => now(),
                'status' => CatalogSubmissionStatus::ReadyForReview,
            ]);

            /*
             * Ready for Review notification is sent ONLY here — after the
             * Excel has been generated, stored, and the submission marked
             * ready_for_review. A failed export rethrows before this line,
             * so the notification can never be sent for a failed export.
             */
            $adminEmail = config('vit.gateway_email');

            if (is_string($adminEmail) && $adminEmail !== '') {
                Notification::route('mail', $adminEmail)
                    ->notify(new CatalogReadyForReviewNotification(
                        vendorId: $submission->vendor_id,
                    ));
            }
        } catch (\Throwable $e) {
            $submission->update([
                'processing_status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
