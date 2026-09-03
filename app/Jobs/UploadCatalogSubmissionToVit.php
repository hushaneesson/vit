<?php

namespace App\Jobs;

use App\Models\CatalogSubmission;
use App\Services\VitApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Uploads a catalog submission's generated Excel file to the VIT API/FTP server.
 *
 * This is a dedicated job for the CatalogSubmission workflow — it does NOT
 * reuse the old UploadSubmissionToVit job (which works with the legacy
 * Submission model). The old Submission workflow remains untouched.
 *
 * Triggered when an admin approves a submission that has a completed Excel
 * export. The job uploads the existing file (does NOT regenerate it).
 *
 * On success: sets submission status to 'uploaded', processing_status to
 * 'completed', and records the upload timestamp.
 * On failure: sets processing_status to 'failed' with the error message.
 * The submission status stays 'approved' so the admin can retry.
 */
class UploadCatalogSubmissionToVit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(protected int $catalogSubmissionId)
    {
        $this->onQueue('vit-uploads');
    }

    public function handle(VitApiClient $client): void
    {
        $submission = CatalogSubmission::find($this->catalogSubmissionId);

        if (! $submission) {
            return;
        }

        // Guard: only upload approved submissions with uploading status
        if ($submission->status !== 'approved' || $submission->processing_status !== 'uploading') {
            return;
        }

        try {
            $response = $client->uploadCatalogSubmission($submission);

            if ($response->successful()) {
                $submission->update([
                    'status' => 'uploaded',
                    'processing_status' => 'completed',
                    'uploaded_at' => now(),
                ]);

                Log::info('VIT upload completed for catalog submission ' . $submission->id);

                return;
            }

            $this->recordFailure($submission, 'HTTP ' . $response->status() . ': ' . $response->body());
        } catch (Throwable $e) {
            Log::error('VIT upload failed for catalog submission ' . $submission->id, ['error' => $e->getMessage()]);
            $this->recordFailure($submission, $e->getMessage());
        }
    }

    protected function recordFailure(CatalogSubmission $submission, string $error): void
    {
        $submission->update([
            'processing_status' => 'failed',
            'failure_reason' => $error,
        ]);

        Log::error('VIT upload failed for catalog submission ' . $submission->id, ['error' => $error]);
    }
}
