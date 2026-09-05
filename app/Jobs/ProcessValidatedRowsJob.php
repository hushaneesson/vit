<?php

namespace App\Jobs;

use App\Enums\CatalogUploadStatus;
use App\Models\CatalogUpload;
use App\Services\Catalog\CatalogItemProcessor;
use App\Services\Catalog\CatalogValidationReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates the upload lifecycle for validated CatalogUploadRow records.
 *
 * Consumes validated rows produced by ProcessCatalogUploadJob and creates or
 * updates CatalogItem rows in the vendor's catalog.
 *
 * Matching strategy: dealer_sku (globally unique via (vendor_id, dealer_sku)).
 * When updating an existing CatalogItem, blank CSV values are not written
 * back to the database. Only non-blank values overwrite existing data.
 *
 * Detailed item construction, comparison, classification, and validation-report
 * logic lives in the App\Services\Catalog services. This job only drives the
 * workflow:
 *
 * load upload -> should process -> claim -> process rows -> commit
 * -> validation report email (if required) -> complete upload
 */
class ProcessValidatedRowsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $catalogUploadId) {}

    public function handle(
        CatalogItemProcessor $itemProcessor,
        CatalogValidationReportService $reportService
    ): void {
        $upload = $this->loadUpload();

        if (!$this->shouldProcess($upload)) {
            return;
        }

        if (!$this->claimUpload($upload)) {
            return;
        }

        try {
            $this->processUpload($upload, $itemProcessor, $reportService);
        } catch (Throwable $e) {
            $this->handleProcessingFailure($upload, $e);

            throw $e;
        }
    }

    /**
     * Load the upload and the relationships required during processing.
     */
    private function loadUpload(): CatalogUpload
    {
        return CatalogUpload::with([
            'vendor',
            'columnMappings',
        ])->findOrFail($this->catalogUploadId);
    }

    /**
     * Determine whether the upload should be processed.
     *
     * Completed uploads are always skipped.
     * ProcessingItems uploads are skipped unless their processing lock
     * has become stale.
     */
    private function shouldProcess(CatalogUpload $upload): bool
    {
        if ($upload->status === CatalogUploadStatus::Completed) {
            return false;
        }

        if ($upload->status === CatalogUploadStatus::ProcessingItems) {
            $processingTimeout = (int) config(
                'catalog.processing_timeout_minutes',
                60
            );

            $isStale = $upload->processing_started_at
                && $upload->processing_started_at
                ->addMinutes($processingTimeout)
                ->isPast();

            if (!$isStale && $upload->processing_started_at !== null) {
                return false;
            }
        }

        return true;
    }
    /**
     * Atomically claim the upload for item processing.
     */
    private function claimUpload(CatalogUpload $upload): bool
    {
        return DB::transaction(function () use ($upload) {
            $updated = CatalogUpload::where('id', $upload->id)
                ->whereIn('status', [
                    CatalogUploadStatus::Processing,
                    CatalogUploadStatus::ProcessingItems,
                ])
                ->update([
                    'status' => CatalogUploadStatus::ProcessingItems,
                    'processing_started_at' => now(),
                ]);

            return $updated > 0;
        });
    }

    /**
     * Process all valid rows, commit the transaction, then complete the
     * upload (sending the validation report email first if required).
     */
    private function processUpload(
        CatalogUpload $upload,
        CatalogItemProcessor $itemProcessor,
        CatalogValidationReportService $reportService
    ): void {
        $vendor = $upload->vendor;

        $createdCount = 0;
        $updatedCount = 0;
        $unchangedCount = 0;

        $validRows = $upload->rows()->where('status', 'valid');

        $validRows->chunkById(500, function ($rows) use (
            $upload,
            $vendor,
            $itemProcessor,
            &$createdCount,
            &$updatedCount,
            &$unchangedCount
        ) {
            DB::beginTransaction();

            try {
                foreach ($rows as $row) {
                    $result = $itemProcessor->processRow(
                        $upload,
                        $vendor,
                        $row
                    );

                    $createdCount += $result['created'];
                    $updatedCount += $result['updated'];
                    $unchangedCount += $result['unchanged'];
                }

                DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();

                Log::error('ProcessValidatedRowsJob: batch failed and was rolled back', [
                    'upload_id' => $upload->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });

        $emailSent = $reportService->sendValidationReportIfNeeded($upload);

        if ($emailSent) {
            $this->completeUpload(
                $upload,
                $createdCount,
                $updatedCount,
                $unchangedCount
            );
        }
    }

    /**
     * Mark the upload as completed and store processing statistics.
     */
    private function completeUpload(
        CatalogUpload $upload,
        int $createdCount,
        int $updatedCount,
        int $unchangedCount
    ): void {
        $upload->update([
            'status' => CatalogUploadStatus::Completed,
            'total_rows' => $upload->rows()->count(),
            'success_rows' =>
            $createdCount
                + $updatedCount
                + $unchangedCount,
            'created_rows' => $createdCount,
            'updated_rows' => $updatedCount,
            'unchanged_rows' => $unchangedCount,
            'invalid_rows' =>
            $upload->rows()
                ->where('status', 'invalid')
                ->count(),
            'processing_completed_at' => now(),
        ]);
    }

    /**
     * Handle a processing failure.
     */
    private function handleProcessingFailure(
        CatalogUpload $upload,
        Throwable $e
    ): void {
        Log::error(
            'ProcessValidatedRowsJob: processing failed',
            [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]
        );

        $upload->update([
            'status' => CatalogUploadStatus::Failed,
            'failure_reason' => Str::limit(
                'Row processing failed: ' . $e->getMessage(),
                5000
            ),
            'processing_completed_at' => now(),
        ]);
    }
}
