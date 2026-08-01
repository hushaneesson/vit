<?php

namespace App\Console\Commands;

use App\Jobs\UploadSubmissionToVit;
use App\Models\CatalogSubmission;
use Illuminate\Console\Command;

/**
 * Auto-retry failed VIT uploads at configurable intervals after
 * the failure (default 1h, 6h, 24h — see VIT_RETRY_AFTER_HOURS in .env).
 * Intended to run hourly via the scheduler (see routes/console.php).
 */
class RetryFailedVitUploads extends Command
{
    protected $signature = 'vit:retry-failed-uploads';

    protected $description = 'Auto-retry failed VIT submission uploads at configured intervals since last attempt';

    public function handle(): int
    {
        $retryHours = config('vit.retry_after_hours', [1, 6, 24]);

        $failedSubmissions = CatalogSubmission::query()
            ->where('processing_status', 'failed')
            ->get();

        $dispatched = 0;

        foreach ($failedSubmissions as $submission) {
            $hoursSinceUpdate = $submission->updated_at->diffInHours(now());

            // Only retry if we've crossed one of the configured thresholds
            // and haven't already retried past it (upload_attempts loosely
            // tracks how many thresholds we've consumed).
            $shouldRetry = collect($retryHours)
                ->slice($submission->upload_attempts - 1, 1)
                ->contains(fn($threshold) => $hoursSinceUpdate >= $threshold);

            if ($shouldRetry) {
                UploadSubmissionToVit::dispatch($submission->id);
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} retry upload job(s).");

        return self::SUCCESS;
    }
}
