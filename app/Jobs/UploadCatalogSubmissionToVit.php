<?php

namespace App\Jobs;

use App\Enums\CatalogSubmissionStatus;
use App\Models\CatalogSubmission;
use App\Notifications\CatalogUploadedNotification;
use App\Notifications\CatalogUploadFailedNotification;
use App\Services\VitApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Uploads a catalog submission's generated Excel file to the VIT API/FTP server.
 */

class UploadCatalogSubmissionToVit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Retries are modeled explicitly via upload_attempts + scheduler, not queue retries.
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

        if ($submission->status !== CatalogSubmissionStatus::Approved) {
            return;
        }

        $submission->update(['processing_status' => 'uploading']);

        try {
            $response = $client->uploadCatalogSubmission($submission);

            if ($response->successful()) {
                $submission->update([
                    'status' => CatalogSubmissionStatus::Uploaded,
                    'processing_status' => 'completed',
                    'uploaded_at' => now(),
                    'vit_api_response' => $response->json() ?? ['raw' => $response->body()],
                ]);

                Log::info('VIT upload completed for catalog submission ' . $submission->id);

                $this->notifySuccess($submission);

                return;
            }

            $this->recordFailure(
                $submission,
                'HTTP ' . $response->status() . ': ' . $response->body()
            );
        } catch (Throwable $e) {
            Log::error('VIT upload failed for catalog submission ' . $submission->id, ['error' => $e->getMessage()]);

            $this->recordFailure($submission, $e->getMessage());
        }
    }

    protected function recordFailure(CatalogSubmission $submission, string $error): void
    {
        $submission->update([
            'processing_status' => 'failed',
            'upload_attempts' => $submission->upload_attempts + 1,
            'last_upload_error' => $error,
            'failure_reason' => $error,
        ]);

        $this->notifyFailure($submission);
    }

    protected function notifySuccess(CatalogSubmission $submission): void
    {
        $client = $submission->requestedByClient;

        if ($client && $client->email) {
            Notification::route('mail', $client->email)
                ->notify(new CatalogUploadedNotification($submission));
        }

        Notification::route('mail', config('vit.gateway_email'))
            ->notify(new CatalogUploadedNotification($submission));
    }

    protected function notifyFailure(CatalogSubmission $submission): void
    {
        Notification::route('mail', config('vit.gateway_email'))
            ->notify(new CatalogUploadFailedNotification($submission));
    }
}
