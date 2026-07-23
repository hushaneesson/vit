<?php

namespace App\Jobs;

use App\Enums\CatalogExportStatus;
use App\Enums\CatalogSubmissionStatus;
use App\Models\CatalogExport;
use App\Services\CatalogExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatches the generation of a VIT-compliant .xlsx file for an approved catalog submission.
 *
 * Flow:
 *   - Job receives catalogExportId (pre-created during approval)
 *   - Loads CatalogExport and validates it has a related CatalogSubmission
 *   - Updates status to generating
 *   - Calls CatalogExportService to generate the excel file
 *   - On success: marks export completed, updates submission to ready_for_upload
 *   - On failure: marks export failed with failure_reason
 */
class GenerateCatalogExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800; // 30 minutes for large catalogs

    /**
     * @param int $catalogExportId The export record to process
     */
    public function __construct(
        public int $catalogExportId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CatalogExportService $exportService): void
    {
        $export = CatalogExport::with('submission')->findOrFail($this->catalogExportId);

        // Validate: must have a related CatalogSubmission
        if (!$export->submission) {
            throw new \RuntimeException('CatalogExport has no related CatalogSubmission');
        }

        // Idempotency: if already completed and file exists, do not regenerate
        if (
            $export->status === CatalogExportStatus::Completed &&
            $export->file_path &&
            \Illuminate\Support\Facades\Storage::disk($export->disk)->exists($export->file_path)
        ) {
            return;
        }

        // Update status to generating
        $export->update([
            'status' => CatalogExportStatus::Generating,
            'generating_started_at' => now(),
        ]);

        try {
            $path = $exportService->generateFromSubmission($export);

            $fileSize = \Illuminate\Support\Facades\Storage::disk($export->disk)->fileSize($path);

            $export->update([
                'file_path' => $path,
                'file_size' => $fileSize,
                'status' => CatalogExportStatus::Completed,
                'generated_at' => now(),
            ]);

            // Update submission status to ready_for_upload
            $export->submission->update([
                'status' => CatalogSubmissionStatus::ReadyForUpload,
            ]);
        } catch (\Throwable $e) {
            $export->update([
                'status' => CatalogExportStatus::Failed,
                'failure_reason' => $e->getMessage(),
                'generating_started_at' => now(),
            ]);

            throw $e;
        }
    }
}
