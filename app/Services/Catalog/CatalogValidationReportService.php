<?php

namespace App\Services\Catalog;

use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
use App\Notifications\CatalogUploadValidationReportNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends the validation report email for a processed catalog upload when
 * required.
 *
 * This is intentionally behavior-preserving: email is only sent when there
 * are more than 10 invalid rows and it has not already been emailed.
 */
class CatalogValidationReportService
{
    /**
     * Send the validation report when required.
     *
     * @return bool True when processing may be marked completed.
     */
    public function sendValidationReportIfNeeded(CatalogUpload $upload): bool
    {
        if (
            $upload->invalid_rows <= 10
            || $upload->validation_report_emailed_at
        ) {
            return true;
        }

        try {
            $client = $upload->client;

            if (!$client || !$client->email) {
                return false;
            }

            $failedRows = $upload->rows()
                ->where('status', 'invalid')
                ->whereNotNull('errors')
                ->orderBy('row_number')
                ->get([
                    'row_number',
                    'errors',
                ]);

            $warningRows = $upload->rows()
                ->where('status', 'valid')
                ->whereNotNull('errors')
                ->orderBy('row_number')
                ->get([
                    'row_number',
                    'errors',
                ]);

            $warningRows = $warningRows->filter(function (CatalogUploadRow $row) {
                $payload = is_string($row->errors)
                    ? json_decode($row->errors, true)
                    : $row->errors;

                return is_array($payload)
                    && !empty($payload['warnings'] ?? []);
            });

            Notification::route('mail', $client->email)
                ->notify(
                    new CatalogUploadValidationReportNotification(
                        catalogName: 'Upload #' . $upload->id,
                        processedAt: $upload->processing_completed_at,
                        totalErrors: $upload->invalid_rows,
                        totalWarnings: $warningRows->count(),
                        errors: $failedRows,
                        warnings: $warningRows,
                    )
                );

            $upload->update([
                'validation_report_emailed_at' => now(),
            ]);

            return true;
        } catch (Throwable $e) {
            $upload->update([
                'processing_started_at' => null,
            ]);

            Log::warning(
                'Failed to send validation report email for upload '
                . $upload->id
                . ': '
                . $e->getMessage()
            );

            return false;
        }
    }
}
