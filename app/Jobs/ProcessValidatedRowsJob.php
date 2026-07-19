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
 * ProcessCatalogUploadJob and creates CatalogItem rows in the
 * vendor's catalog.
 *
 * System-derived fields (e.g. seller → vendor.name) are populated
 * here, not from the uploaded file.
 */
class ProcessValidatedRowsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $catalogUploadId) {}

    public function handle(): void
    {
        $upload = CatalogUpload::with('vendor')->findOrFail($this->catalogUploadId);

        $upload->update(['status' => CatalogUploadStatus::ProcessingItems]);

        try {
            $vendor = $upload->vendor;
            $catalogName = $upload->catalog_name ?? 'Imported Catalog ' . $upload->created_at->format('Y-m-d');

            // Build field_key => CatalogItem column mapping
            $fieldToColumn = $this->fieldKeyToColumnMap();

            $validRows = $upload->rows()
                ->where('status', 'valid')
                ->cursor();

            // Columns that should NOT be compared when checking for changes
            $nonComparableColumns = ['vendor_sku', 'vendor_id', 'catalog_name', 'catalog_upload_id', 'data_fingerprint'];

            $createdCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;
            $skippedNames = [];

            DB::beginTransaction();

            foreach ($validRows as $row) {
                $data = $row->data;

                // Build the attribute map from the row data
                $attrs = [
                    'vendor_id' => $vendor->id,
                    'catalog_name' => $catalogName,
                    'catalog_upload_id' => $upload->id,
                ];

                // Map validated field data to CatalogItem columns.
                // Always set every key so that every row has the same structure.
                foreach ($fieldToColumn as $fieldKey => $columnName) {
                    $rawValue = $data[$fieldKey] ?? null;

                    if (is_null($rawValue) || $rawValue === '' || $rawValue === []) {
                        // Leave as null — the completeness scoring will mark
                        // the item as 'incomplete' if required fields are missing.
                        // No fake defaults are injected.
                        $attrs[$columnName] = null;
                        continue;
                    }

                    // Cast multi-value arrays to JSON for JSON columns
                    if (in_array($columnName, ['search_terms', 'classifications', 'specifications', 'selling_points'], true)) {
                        $attrs[$columnName] = is_array($rawValue) ? json_encode($rawValue) : json_encode([$rawValue]);
                    } elseif ($columnName === 'weight') {
                        $attrs[$columnName] = is_numeric($rawValue) ? (float) $rawValue : 0.01;
                    } elseif (in_array($columnName, ['quantity_per_unit', 'min_order_quantity', 'max_order_quantity', 'multiples', 'list_price', 'selling_price'], true)) {
                        $attrs[$columnName] = is_numeric($rawValue) ? (float) $rawValue : null;
                    } else {
                        $attrs[$columnName] = (string) $rawValue;
                    }
                }

                // Compute a deterministic fingerprint of the content data only
                // (excludes metadata like vendor_id, catalog_name, catalog_upload_id)
                $fingerprint = $this->computeFingerprint($attrs, $nonComparableColumns);

                // STEP 1: Check by fingerprint first — exact content match
                $existingByFingerprint = CatalogItem::where('vendor_id', $vendor->id)
                    ->where('data_fingerprint', $fingerprint)
                    ->first();

                if ($existingByFingerprint) {
                    // Exact same data already exists — skip entirely
                    $skippedCount++;
                    $itemName = $attrs['name'] ?? $attrs['vendor_sku'] ?? 'Unknown';
                    if ($itemName) {
                        $skippedNames[] = $itemName;
                    }
                    continue;
                }

                // STEP 2: Look for an existing item with the same vendor_sku for this vendor.
                // This way re-uploads update rather than duplicate rows.
                $vendorSku = $attrs['vendor_sku'] ?? null;

                if ($vendorSku) {
                    $existing = CatalogItem::where('vendor_id', $vendor->id)
                        ->where('vendor_sku', $vendorSku)
                        ->first();

                    if ($existing) {
                        // Check if anything actually changed before updating
                        $hasChanges = $this->hasMeaningfulChanges($existing, $attrs, $nonComparableColumns);

                        if ($hasChanges) {
                            // Update the existing record with new data
                            $attrs['data_fingerprint'] = $fingerprint;
                            $attrs['updated_at'] = now();
                            $existing->update($attrs);
                            $updatedCount++;
                        } else {
                            // No changes — skip to save resources
                            $skippedCount++;
                            $itemName = $attrs['name'] ?? $vendorSku;
                            if ($itemName) {
                                $skippedNames[] = $itemName;
                            }
                        }

                        continue;
                    }
                }

                // No existing item found — create a new one
                $attrs['id'] = (string) Str::uuid();
                $attrs['data_fingerprint'] = $fingerprint;
                $attrs['created_at'] = now();
                $attrs['updated_at'] = now();

                try {
                    CatalogItem::create($attrs);
                    $createdCount++;
                } catch (\Illuminate\Database\QueryException $e) {
                    // Handle race condition: another process created this SKU between our check and insert.
                    // Fall back to update logic.
                    if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'catalog_items_vendor_sku_unique')) {
                        $existing = CatalogItem::where('vendor_id', $vendor->id)
                            ->where('vendor_sku', $vendorSku)
                            ->first();

                        if ($existing) {
                            $attrs['data_fingerprint'] = $fingerprint;
                            $attrs['updated_at'] = now();
                            $existing->update($attrs);
                            $updatedCount++;
                        } else {
                            // Genuinely unique constraint violation — re-throw
                            throw $e;
                        }
                    } else {
                        throw $e;
                    }
                }
            }

            DB::commit();

            $upload->update([
                'status' => CatalogUploadStatus::Completed,
                'total_rows' => $upload->rows()->count(),
                'success_rows' => $createdCount + $updatedCount,
                'updated_rows' => $updatedCount,
                'skipped_rows' => $skippedCount,
                'skipped_item_names' => $skippedCount > 0 ? $skippedNames : null,
                'error_rows' => $upload->rows()->where('status', 'invalid')->count(),
                'processing_completed_at' => now(),
            ]);

            // Send validation report by email if there are too many errors
            // to display on-screen. The guard prevents duplicate sends even
            // if the job is retried.
            if ($upload->error_rows > 10 && !$upload->validation_report_emailed_at) {
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
                                catalogName: $upload->catalog_name,
                                processedAt: $upload->processing_completed_at,
                                totalErrors: $upload->error_rows,
                                totalWarnings: 0,
                                errors: $failedRows,
                                warnings: collect(),
                            ));

                        $upload->update(['validation_report_emailed_at' => now()]);
                    }
                } catch (\Throwable $e) {
                    // Email failed — do NOT mark validation_report_emailed_at.
                    // The results are preserved in the DB and the UI will
                    // fall back to showing the first 10 rows with a notice.
                    Log::warning('Failed to send validation report email for upload ' . $upload->id . ': ' . $e->getMessage());
                }
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
     * Compute a deterministic MD5 fingerprint from the content fields of a row,
     * excluding metadata columns that don't represent actual data.
     */
    private function computeFingerprint(array $attrs, array $excludeColumns): string
    {
        $content = [];

        foreach ($attrs as $column => $value) {
            if (in_array($column, $excludeColumns, true)) {
                continue;
            }

            // Normalize the value for deterministic comparison
            if (is_null($value) || $value === '' || $value === []) {
                $content[$column] = null;
            } elseif (is_array($value)) {
                // Sort arrays so order doesn't matter
                sort($value);
                $content[$column] = $value;
            } elseif (is_numeric($value)) {
                // Normalize numeric values to avoid "10" vs 10.0 mismatches
                $content[$column] = (string) (float) $value;
            } else {
                $content[$column] = trim((string) $value);
            }
        }

        // Sort by key for deterministic ordering
        ksort($content);

        return md5(json_encode($content));
    }

    /**
     * Determine if the new row data has meaningful differences from the existing record.
     * Handles type coercion, null/empty equivalence, and JSON comparison.
     */
    private function hasMeaningfulChanges(CatalogItem $existing, array $newAttrs, array $nonComparableColumns): bool
    {
        foreach ($newAttrs as $column => $newValue) {
            if (in_array($column, $nonComparableColumns, true)) {
                continue;
            }

            $oldValue = $existing->getRawOriginal($column);

            // Handle null vs empty string equivalence
            if (is_null($oldValue) && ($newValue === '' || $newValue === null)) {
                continue;
            }
            if (is_null($newValue) && ($oldValue === '' || $oldValue === null)) {
                continue;
            }

            // JSON columns — decode both sides for comparison
            if (in_array($column, ['search_terms', 'classifications', 'specifications', 'selling_points'], true)) {
                $decodedOld = json_decode((string) $oldValue, true);
                $decodedNew = json_decode((string) $newValue, true);

                // Normalize both: sort arrays for deterministic comparison
                if (is_array($decodedOld)) {
                    sort($decodedOld);
                }
                if (is_array($decodedNew)) {
                    sort($decodedNew);
                }

                if ($decodedOld !== $decodedNew) {
                    return true;
                }
                continue;
            }

            // Numeric comparison — cast both to float for consistency
            if (is_numeric($oldValue) && is_numeric($newValue)) {
                if ((float) $oldValue !== (float) $newValue) {
                    return true;
                }
                continue;
            }

            // Default string comparison — trim both sides
            if (trim((string) $oldValue) !== trim((string) $newValue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Columns in catalog_items that are NOT NULL and come from user uploads.
     * These must always have a value in every row.
     */
    private function getRequiredFieldKeys(): array
    {
        return [
            'seller_sku',
            'name',
            'description',
            'product_type_or_family',
            'unspsc_code',
            'unit_of_measure',
            'list_price',
            'selling_price_per_unit',
        ];
    }

    /**
     * Map VIT field_key values to CatalogItem database columns.
     */
    private function fieldKeyToColumnMap(): array
    {
        return [
            'seller_sku' => 'vendor_sku',
            'manufacturer_sku' => 'manufacturer_sku',
            'manufacturer' => 'manufacturer_name',
            'brand_name' => 'brand_name',
            'name' => 'name',
            'description' => 'description',
            'product_type_or_family' => 'product_type',
            'unit_of_measure' => 'unit_of_measure',
            'quantity_per_unit' => 'quantity_per_unit',
            'unspsc_code' => 'unspsc_code',
            'list_price' => 'list_price',
            'selling_price_per_unit' => 'selling_price',
            'item_weight' => 'weight',
            'min_qty_per_order' => 'min_order_quantity',
            'max_qty_per_order' => 'max_order_quantity',
            'multiples' => 'multiples',
            'search_terms' => 'search_terms',
            'classifications' => 'classifications',
            'specifications' => 'specifications',
            'selling_points' => 'selling_points',
            'msds_link' => 'msds_link',
        ];
    }
}
