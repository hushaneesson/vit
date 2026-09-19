<?php

namespace App\Services\Catalog;

use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
use App\Notifications\CatalogUploadValidationReportNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends the validation report email for a processed catalog upload when
 * required.
 *
 * The send condition is unchanged: email is only sent when there are more
 * than 10 invalid rows and it has not already been emailed.
 *
 * Only the presentation is aggregated. Every individual row-level error stays
 * stored on CatalogUploadRow; the email summarises them by message type and
 * affected row count instead of listing each row. Processing failures
 * (CatalogUploadRow::STATUS_FAILED) are system errors and are deliberately
 * excluded from this vendor validation report.
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

            [$errorSummary, $warningSummary, $warningCount] = $this->buildSummaries($upload);

            Notification::route('mail', $client->email)
                ->notify(
                    new CatalogUploadValidationReportNotification(
                        catalogName: 'Upload #' . $upload->id,
                        rowsProcessed: (int) ($upload->total_rows ?? 0),
                        successRows: (int) ($upload->success_rows ?? 0),
                        totalErrors: (int) $upload->invalid_rows,
                        totalWarnings: $warningCount,
                        errorSummary: $errorSummary,
                        warningSummary: $warningSummary,
                        catalogId: $upload->catalog_id,
                        catalogUploadId: $upload->id,
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

    /**
     * Aggregate the recorded row-level validation messages into email-ready
     * summaries, grouped by message type.
     *
     * Errors come from invalid rows; warnings come from valid rows that were
     * flagged. Processing failures (status = failed) are excluded so system
     * errors are never presented as vendor validation problems.
     *
     * @return array{0: array<int, array{label: string, count: int}>, 1: array<int, array{label: string, count: int}>, 2: int}
     */
    private function buildSummaries(CatalogUpload $upload): array
    {
        $rows = $upload->rows()
            ->whereIn('status', [CatalogUploadRow::STATUS_INVALID, CatalogUploadRow::STATUS_VALID])
            ->whereNotNull('errors')
            ->orderBy('row_number')
            ->get([
                'status',
                'errors',
            ]);

        $errorCounts = [];
        $warningCounts = [];
        $warningRowCount = 0;

        foreach ($rows as $row) {
            $payload = is_string($row->errors)
                ? json_decode($row->errors, true)
                : $row->errors;

            if (!is_array($payload)) {
                continue;
            }

            if ($row->status === CatalogUploadRow::STATUS_INVALID) {
                foreach ($payload['errors'] ?? [] as $entry) {
                    $label = $this->friendlyLabel((string) ($entry['message'] ?? ''));

                    if ($label !== '') {
                        $errorCounts[$label] = ($errorCounts[$label] ?? 0) + 1;
                    }
                }

                continue;
            }

            $warnings = $payload['warnings'] ?? [];

            if (empty($warnings)) {
                continue;
            }

            $warningRowCount++;

            foreach ($warnings as $entry) {
                $label = $this->friendlyLabel((string) ($entry['message'] ?? ''));

                if ($label !== '') {
                    $warningCounts[$label] = ($warningCounts[$label] ?? 0) + 1;
                }
            }
        }

        return [
            $this->sortSummary($errorCounts),
            $this->sortSummary($warningCounts),
            $warningRowCount,
        ];
    }

    /**
     * Order summary entries by affected row count (descending), then label.
     *
     * @param  array<string, int>  $counts
     * @return array<int, array{label: string, count: int}>
     */
    private function sortSummary(array $counts): array
    {
        $summary = [];

        foreach ($counts as $label => $count) {
            $summary[] = [
                'label' => (string) $label,
                'count' => (int) $count,
            ];
        }

        usort($summary, static function (array $a, array $b): int {
            return $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']);
        });

        return $summary;
    }

    /**
     * Convert a stored validation message into a concise vendor-facing label.
     *
     * The stored/internal message is never modified - this only affects how
     * the message is presented in the email. Unknown messages fall back to
     * the stored text minus its trailing period.
     */
    private function friendlyLabel(string $message): string
    {
        $message = trim($message);

        if ($message === '') {
            return '';
        }

        $quoted = static fn (string $value): string => '"' . str_replace('"', "'", $value) . '"';

        if (preg_match("/^Required field '(.+?)' is missing(?: and is required to create a catalog item)?\\.$/u", $message, $matches)) {
            return 'Missing required field ' . $quoted($matches[1]);
        }

        if (preg_match("/^Required when '(.+?)' is set: '(.+?)'\\.$/u", $message, $matches)) {
            return 'Missing required field ' . $quoted($matches[2])
                . ' (required when ' . $quoted($matches[1]) . ' is set)';
        }

        if ($message === 'Seller SKU is required to identify and import a catalog item.') {
            return 'Missing dealer SKU';
        }

        if (preg_match('/^(.+?) must be a decimal number\\.$/u', $message, $matches)) {
            return 'Invalid decimal value for ' . $quoted($matches[1]);
        }

        if (preg_match('/^(.+?) must be a number\\.$/u', $message, $matches)) {
            return 'Invalid number for ' . $quoted($matches[1]);
        }

        if (preg_match('/^(.+?) must be yes\\/no or true\\/false\\.$/u', $message, $matches)) {
            return 'Invalid yes/no value for ' . $quoted($matches[1]);
        }

        if (preg_match('/^(.+?) exceeds the maximum length of (\\d+)\\.$/u', $message, $matches)) {
            return $quoted($matches[1]) . ' exceeds the maximum length of ' . $matches[2] . ' characters';
        }

        if (preg_match('/^(.+?) contains a nested array value that is not supported\\.$/u', $message, $matches)) {
            return $quoted($matches[1]) . ' contains an unsupported nested value';
        }

        return rtrim($message, '.');
    }
}
