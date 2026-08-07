<?php

namespace App\Jobs;

use App\Enums\CatalogUploadStatus;
use App\Models\CatalogItem;
use App\Models\CatalogUpload;
use App\Models\Vendor;
use App\Notifications\CatalogUploadValidationReportNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 8B: consumes the validated CatalogUploadRow records from
 * ProcessCatalogUploadJob and creates/updates CatalogItem rows in
 * the vendor's catalog.
 *
 * Matching strategy:
 *   1. dealer_sku (primary key for matching existing items)
 *
 * dealer_sku is the unique identifier for catalog items. The schema
 * enforces a unique constraint on (vendor_id, dealer_sku), so every
 * CatalogItem has exactly one dealer_sku per vendor.
 *
 * When updating an existing CatalogItem, blank CSV values are NOT
 * written back to the database — only non-blank values from the CSV
 * overwrite the existing record. This preserves existing data that
 * the CSV does not cover.
 */
class ProcessValidatedRowsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $catalogUploadId) {}

    public function handle(): void
    {
        $upload = CatalogUpload::with(['vendor', 'columnMappings'])->findOrFail($this->catalogUploadId);

        if ($upload->status === CatalogUploadStatus::Completed) {
            return;
        }

        if ($upload->status === CatalogUploadStatus::ProcessingItems) {
            $processingTimeout = (int) config('catalog.processing_timeout_minutes', 60);
            $isStale = $upload->processing_started_at
                && $upload->processing_started_at->addMinutes($processingTimeout)->isPast();

            if (!$isStale && $upload->processing_started_at !== null) {
                return;
            }
        }

        $claimed = DB::transaction(function () use ($upload) {
            $updated = CatalogUpload::where('id', $upload->id)
                ->whereIn('status', [CatalogUploadStatus::Processing, CatalogUploadStatus::ProcessingItems])
                ->update(['status' => CatalogUploadStatus::ProcessingItems, 'processing_started_at' => now()]);

            return $updated > 0;
        });

        if (!$claimed) {
            return;
        }

        try {
            $vendor = $upload->vendor;

            $validRows = $upload->rows()
                ->where('status', 'valid')
                ->cursor();

            $nonComparableColumns = ['dealer_sku', 'vendor_id'];

            $createdCount = 0;
            $updatedCount = 0;
            $unchangedCount = 0;

            DB::beginTransaction();

            foreach ($validRows as $row) {
                $data = $row->data;
                if (!is_array($data)) {
                    $data = json_decode((string) ($data ?? ''), true) ?: [];
                }

                // Build the attribute map directly from VIT field keys.
                // CatalogItem columns now match VIT field keys, so no translation needed.
                $attrs = [
                    'vendor_id' => $vendor->id,
                ];

                foreach ($data as $fieldKey => $rawValue) {
                    if (is_null($rawValue) || $rawValue === '' || $rawValue === []) {
                        if ($fieldKey === 'item_weight') {
                            $attrs[$fieldKey] = 0.01;
                            continue;
                        }
                        $attrs[$fieldKey] = null;
                        continue;
                    }

                    // Cast multi-value arrays for JSON columns
                    if (in_array($fieldKey, ['search_terms', 'classifications', 'specifications', 'selling_points'], true)) {
                        $attrs[$fieldKey] = is_array($rawValue) ? $rawValue : [$rawValue];
                    } elseif ($fieldKey === 'item_weight') {
                        $attrs[$fieldKey] = is_numeric($rawValue) ? (float) $rawValue : 0.01;
                    } elseif (in_array($fieldKey, ['quantity_per_unit', 'min_qty_per_order', 'max_qty_per_order', 'multiples', 'list_price', 'selling_price'], true)) {
                        $attrs[$fieldKey] = is_numeric($rawValue) ? (float) $rawValue : null;
                    } else {
                        $attrs[$fieldKey] = (string) $rawValue;
                    }
                }

                // Build the update payload — only fields that have non-blank
                // values in the CSV. This ensures we never overwrite existing
                // data with blank CSV values.
                $updateAttrs = array_filter(
                    $attrs,
                    fn($value) => $value !== null && $value !== '' && $value !== [],
                    ARRAY_FILTER_USE_BOTH,
                );
                // Remove metadata fields that should never be bulk-overwritten
                unset($updateAttrs['vendor_id']);

                // ----------------------------------------------------------
                // Match by dealer_sku (the unique identifier for catalog items)
                // ----------------------------------------------------------
                $sellerSku = $attrs['dealer_sku'] ?? null;

                if ($sellerSku) {
                    $existing = CatalogItem::where('vendor_id', $vendor->id)
                        ->where('dealer_sku', $sellerSku)
                        ->first();

                    if ($existing) {
                        // Normalize the incoming values before comparison
                        $normalizedUpdateAttrs = $this->normalizeForComparison($updateAttrs);

                        if ($this->hasMeaningfulChanges($existing, $normalizedUpdateAttrs, $nonComparableColumns, $sellerSku)) {
                            // Update only the fields that have values in the CSV
                            $updateAttrs['updated_at'] = now();
                            $existing->update($updateAttrs);
                            $updatedCount++;
                        } else {
                            $unchangedCount++;
                        }

                        continue;
                    }
                }

                // ----------------------------------------------------------
                // No existing item found — create new
                // ----------------------------------------------------------
                $attrs['id'] = (string) Str::uuid();
                $attrs['created_at'] = now();
                $attrs['updated_at'] = now();

                try {
                    CatalogItem::create($attrs);
                    $createdCount++;
                } catch (\Illuminate\Database\QueryException $e) {
                    if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'catalog_items_dealer_sku_unique')) {
                        $existing = CatalogItem::where('vendor_id', $vendor->id)
                            ->where('dealer_sku', $sellerSku)
                            ->first();

                        if ($existing) {
                            $updateAttrs['updated_at'] = now();
                            $existing->update($updateAttrs);
                            $updatedCount++;
                        } else {
                            throw $e;
                        }
                    } else {
                        throw $e;
                    }
                }
            }

            DB::commit();

            $emailSent = false;
            if ($upload->invalid_rows > 10 && !$upload->validation_report_emailed_at) {
                try {
                    $client = $upload->client;

                    if ($client && $client->email) {
                        $failedRows = $upload->rows()
                            ->where('status', 'invalid')
                            ->whereNotNull('errors')
                            ->orderBy('row_number')
                            ->get(['row_number', 'errors']);

                        Notification::route('mail', $client->email)
                            ->notify(new CatalogUploadValidationReportNotification(
                                catalogName: 'Upload #' . $upload->id,
                                processedAt: $upload->processing_completed_at,
                                totalErrors: $upload->invalid_rows,
                                totalWarnings: 0,
                                errors: $failedRows,
                                warnings: collect(),
                            ));

                        $upload->update(['validation_report_emailed_at' => now()]);
                        $emailSent = true;
                    }
                } catch (\Throwable $e) {
                    $upload->update(['processing_started_at' => null]);
                    Log::warning('Failed to send validation report email for upload ' . $upload->id . ': ' . $e->getMessage());
                }
            } else {
                $emailSent = true;
            }

            if ($emailSent) {
                $upload->update([
                    'status' => CatalogUploadStatus::Completed,
                    'total_rows' => $upload->rows()->count(),
                    'success_rows' => $createdCount + $updatedCount + $unchangedCount,
                    'created_rows' => $createdCount,
                    'updated_rows' => $updatedCount,
                    'unchanged_rows' => $unchangedCount,
                    'invalid_rows' => $upload->rows()->where('status', 'invalid')->count(),
                    'processing_completed_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            DB::rollBack();

            $upload->update([
                'status' => CatalogUploadStatus::Failed,
                'failure_reason' => 'Row processing failed: ' . $e->getMessage(),
                'processing_completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Normalize incoming CSV values for comparison against database values.
     *
     * This ensures consistent comparison regardless of how the CSV data
     * was typed (e.g. "10.00" string vs 10.0 float, null vs "").
     */
    private function normalizeForComparison(array $attrs): array
    {
        $normalized = [];

        foreach ($attrs as $key => $value) {
            // Treat default item_weight (0.01) as "blank" since it's a
            // fallback when the CSV has no value for this field. We should
            // not treat it as a real incoming value that would trigger an
            // update overwrite.
            if ($key === 'item_weight' && $value === 0.01) {
                continue;
            }

            // Normalize numeric strings to floats for consistent comparison
            if (is_numeric($value)) {
                $normalized[$key] = (float) $value;
                continue;
            }

            // Normalize empty strings to null
            if ($value === '' || $value === []) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Compare incoming CSV values against existing database values to
     * determine whether the item needs updating.
     */
    private function hasMeaningfulChanges(CatalogItem $existing, array $newAttrs, array $nonComparableColumns, ?string $sku = null): bool
    {
        $detectedChanges = [];

        foreach ($newAttrs as $column => $newValue) {
            if (in_array($column, $nonComparableColumns, true)) {
                continue;
            }

            $oldValue = $existing->getRawOriginal($column);

            // Normalize old value for comparison
            $oldValue = $this->normalizeValueForComparison($oldValue);
            $newValue = $this->normalizeValueForComparison($newValue);

            // Both null/empty — skip
            if ($oldValue === null && $newValue === null) {
                continue;
            }

            // One is null, other is empty string — skip (equivalent)
            if ($oldValue === null && $newValue === '') {
                continue;
            }
            if ($newValue === null && $oldValue === '') {
                continue;
            }

            // JSON fields
            if (in_array($column, ['search_terms', 'classifications', 'specifications', 'selling_points'], true)) {
                $decodedOld = is_array($oldValue) ? $oldValue : json_decode((string) $oldValue, true);
                $decodedNew = is_array($newValue) ? $newValue : json_decode((string) $newValue, true);

                if (is_array($decodedOld)) {
                    sort($decodedOld);
                }
                if (is_array($decodedNew)) {
                    sort($decodedNew);
                }

                if ($decodedOld !== $decodedNew) {
                    $detectedChanges[$column] = ['old' => $decodedOld, 'new' => $decodedNew];
                }
                continue;
            }

            // Numeric comparison
            if (is_numeric($oldValue) && is_numeric($newValue)) {
                if ((float) $oldValue !== (float) $newValue) {
                    $detectedChanges[$column] = ['old' => $oldValue, 'new' => $newValue];
                }
                continue;
            }

            // String comparison
            if (trim((string) $oldValue) !== trim((string) $newValue)) {
                $detectedChanges[$column] = ['old' => $oldValue, 'new' => $newValue];
            }
        }

        if (!empty($detectedChanges)) {
            Log::info('Catalog import: detected changes for SKU', [
                'sku' => $sku,
                'changes' => $detectedChanges,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Normalize a single value for comparison by trimming whitespace and
     * converting empty strings to null.
     */
    private function normalizeValueForComparison(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }
}
