<?php

namespace App\Jobs;

use App\Enums\CatalogUploadStatus;
use App\Models\CatalogItem;
use App\Models\CatalogUpload;
use App\Models\Vendor;
use App\Notifications\CatalogUploadValidationReportNotification;
use App\Services\FingerprintService;
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
 *
 * CatalogItem columns now directly match VIT field keys, so no
 * fieldKeyToColumnMap translation is needed.
 */
class ProcessValidatedRowsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $catalogUploadId) {}

    public function handle(): void
    {
        $upload = CatalogUpload::with('vendor')->findOrFail($this->catalogUploadId);

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

            $nonComparableColumns = ['seller_sku', 'vendor_id', 'catalog_upload_id', 'data_fingerprint'];

            $createdCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;
            $skippedNames = [];

            DB::beginTransaction();

            foreach ($validRows as $row) {
                $data = $row->data;
                if (!is_array($data)) {
                    $data = json_decode((string) ($data ?? ''), true) ?: [];
                }

                // Build the attribute map directly from VIT field keys
                // CatalogItem columns now match VIT field keys, so no translation needed.
                $attrs = [
                    'vendor_id' => $vendor->id,
                    'catalog_upload_id' => $upload->id,
                ];

                // Map validated field data to CatalogItem columns.
                // The field keys from the CSV mapping are already VIT field keys,
                // and CatalogItem columns now match those keys directly.
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
                    } elseif (in_array($fieldKey, ['quantity_per_unit', 'min_qty_per_order', 'max_qty_per_order', 'multiples', 'list_price', 'selling_price_per_unit'], true)) {
                        $attrs[$fieldKey] = is_numeric($rawValue) ? (float) $rawValue : null;
                    } else {
                        $attrs[$fieldKey] = (string) $rawValue;
                    }
                }

                $fingerprint = $this->computeFingerprint($attrs, $nonComparableColumns);

                // STEP 1: Check by fingerprint first
                $existingByFingerprint = CatalogItem::where('vendor_id', $vendor->id)
                    ->where('data_fingerprint', $fingerprint)
                    ->first();

                if ($existingByFingerprint) {
                    $skippedCount++;
                    $itemName = $attrs['name'] ?? $attrs['seller_sku'] ?? 'Unknown';
                    if ($itemName) {
                        $skippedNames[] = $itemName;
                    }
                    continue;
                }

                // STEP 2: Look for existing item with same seller_sku
                $sellerSku = $attrs['seller_sku'] ?? null;

                if ($sellerSku) {
                    $existing = CatalogItem::where('vendor_id', $vendor->id)
                        ->where('seller_sku', $sellerSku)
                        ->first();

                    if ($existing) {
                        $hasChanges = $this->hasMeaningfulChanges($existing, $attrs, $nonComparableColumns);

                        if ($hasChanges) {
                            $attrs['data_fingerprint'] = $fingerprint;
                            $attrs['updated_at'] = now();
                            $existing->update($attrs);
                            $updatedCount++;
                        } else {
                            $skippedCount++;
                            $itemName = $attrs['name'] ?? $sellerSku;
                            if ($itemName) {
                                $skippedNames[] = $itemName;
                            }
                        }

                        continue;
                    }
                }

                // No existing item found — create new
                $attrs['id'] = (string) Str::uuid();
                $attrs['data_fingerprint'] = $fingerprint;
                $attrs['created_at'] = now();
                $attrs['updated_at'] = now();

                try {
                    CatalogItem::create($attrs);
                    $createdCount++;
                } catch (\Illuminate\Database\QueryException $e) {
                    if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'catalog_items_seller_sku_unique')) {
                        $existing = CatalogItem::where('vendor_id', $vendor->id)
                            ->where('seller_sku', $sellerSku)
                            ->first();

                        if ($existing) {
                            $attrs['data_fingerprint'] = $fingerprint;
                            $attrs['updated_at'] = now();
                            $existing->update($attrs);
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
                                catalogName: 'Upload #' . $upload->id,
                                processedAt: $upload->processing_completed_at,
                                totalErrors: $upload->error_rows,
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
                    'success_rows' => $createdCount + $updatedCount,
                    'updated_rows' => $updatedCount,
                    'skipped_rows' => $skippedCount,
                    'skipped_item_names' => $skippedCount > 0 ? $skippedNames : null,
                    'error_rows' => $upload->rows()->where('status', 'invalid')->count(),
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

    private function computeFingerprint(array $attrs, array $excludeColumns): string
    {
        return FingerprintService::compute($attrs, $excludeColumns);
    }

    private function hasMeaningfulChanges(CatalogItem $existing, array $newAttrs, array $nonComparableColumns): bool
    {
        foreach ($newAttrs as $column => $newValue) {
            if (in_array($column, $nonComparableColumns, true)) {
                continue;
            }

            $oldValue = $existing->getRawOriginal($column);

            if (is_null($oldValue) && ($newValue === '' || $newValue === null)) {
                continue;
            }
            if (is_null($newValue) && ($oldValue === '' || $oldValue === null)) {
                continue;
            }

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
                    return true;
                }
                continue;
            }

            if (is_numeric($oldValue) && is_numeric($newValue)) {
                if ((float) $oldValue !== (float) $newValue) {
                    return true;
                }
                continue;
            }

            if (trim((string) $oldValue) !== trim((string) $newValue)) {
                return true;
            }
        }

        return false;
    }
}
