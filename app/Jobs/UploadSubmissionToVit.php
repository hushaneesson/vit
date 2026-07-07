<?php

namespace App\Jobs;

use App\Models\Submission;
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
 * Phase 11: uploads a single pending/failed submission to VIT's catalog API.
 * Used both by the admin's manual "Upload to VIT" bulk action and by the
 * scheduled auto-retry command.
 */
class UploadSubmissionToVit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // retries are modeled explicitly via upload_attempts + scheduler, not queue retries

    public function __construct(protected int $submissionId) {}

    public function handle(VitApiClient $client): void
    {
        $submission = Submission::find($this->submissionId);

        if (! $submission) {
            return;
        }

        $submission->update(['status' => 'processing']);

        try {
            $response = $client->upload($submission);

            if ($response->successful()) {
                $submission->update([
                    'status' => 'uploaded',
                    'uploaded_at' => now(),
                    'vit_api_response' => $response->json() ?? ['raw' => $response->body()],
                ]);

                $this->notifySuccess($submission);

                return;
            }

            $this->recordFailure($submission, 'HTTP '.$response->status().': '.$response->body());
        } catch (Throwable $e) {
            Log::error('VIT upload failed for submission '.$submission->id, ['error' => $e->getMessage()]);
            $this->recordFailure($submission, $e->getMessage());
        }
    }

    protected function recordFailure(Submission $submission, string $error): void
    {
        $submission->update([
            'status' => 'failed',
            'upload_attempts' => $submission->upload_attempts + 1,
            'last_upload_error' => $error,
        ]);

        $this->notifyFailure($submission);
    }

    protected function notifySuccess(Submission $submission): void
    {
        $submission->client?->notify(new CatalogUploadedNotification($submission));

        Notification::route('mail', config('vit.gateway_email'))
            ->notify(new CatalogUploadedNotification($submission));
    }

    protected function notifyFailure(Submission $submission): void
    {
        Notification::route('mail', config('vit.gateway_email'))
            ->notify(new CatalogUploadFailedNotification($submission));
    }
}
