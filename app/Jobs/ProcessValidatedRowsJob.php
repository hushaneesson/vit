<?php

namespace App\Jobs;

use App\Enums\CatalogUploadStatus;
use App\Models\CatalogItem;
use App\Models\CatalogUpload;
use App\Models\ClassificationType;
use App\Models\CountryCode;
use App\Models\Vendor;
use App\Notifications\CatalogUploadValidationReportNotification;
use App\Services\VitFieldDefinition;
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
 * consumes the validated CatalogUploadRow records from
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

                Log::info('ProcessValidatedRowsJob: processing row', [
                    'upload_id' => $upload->id,
                    'row_number' => $row->row_number,
                    'row_status' => $row->status,
                    'row_errors' => $row->errors,
                    // 'row_data_keys' => array_keys($data),
                    'dealer_sku' => $data['dealer_sku'] ?? null,
                ]);

                // Build the attribute map using VitFieldDefinition as the source
                // of truth. Resolve model_attribute, field_type, and is_multi_value
                // dynamically so the mapper stays in sync with the export layer.
                $attrs = [
                    'vendor_id' => $vendor->id,
                ];

                // Pre-fetch definitions for all keys present in this row to avoid
                // repeated static lookups inside the loop.
                $definitions = VitFieldDefinition::all()
                    ->whereIn('field_key', array_keys($data))
                    ->keyBy('field_key');

                foreach ($data as $fieldKey => $rawValue) {
                    $definition = $definitions->get($fieldKey);
                    $modelAttribute = $definition ? ($definition->model_attribute ?? $fieldKey) : $fieldKey;

                    // model_attribute may be a relationship dot-path (e.g.
                    // "commodityType.name", "hierarchyInfo.hierarchy_number",
                    // "unitOfMeasure.code") or an accessor (e.g.
                    // "countryOfOrigin") for the exporter's data_get().
                    // The processing pipeline writes to the actual DB column,
                    // which is the field_key for those relationship-backed
                    // fields (the FK column). Only use model_attribute when
                    // it is a simple column name (no dot and not an accessor).
                    if (str_contains($modelAttribute, '.') || $modelAttribute === 'countryOfOrigin') {
                        $modelAttribute = $fieldKey;
                    }

                    if (is_null($rawValue) || $rawValue === '' || $rawValue === []) {
                        if ($fieldKey === 'item_weight_in_pounds') {
                            $attrs[$modelAttribute] = 0.01;
                            continue;
                        }
                        // is_discontinued must never be NULL — default to false
                        if ($fieldKey === 'discontinued') {
                            $attrs[$modelAttribute] = false;
                            continue;
                        }
                        $attrs[$modelAttribute] = null;
                        continue;
                    }

                    // Multi-value fields: normalize based on field type.
                    // Specifications uses key/value objects, all others are simple arrays.
                    if ($definition && $definition->is_multi_value) {
                        $parts = is_array($rawValue) ? $rawValue : json_decode((string) $rawValue, true);
                        if (!is_array($parts)) {
                            $parts = [];
                        }

                        if ($definition->is_key_value) {
                            // Specifications are normalized eagerly. Classifications,
                            // by contrast, collect contributions from Country of
                            // Origin, UNSPSC, and MSDS processing below, so their raw
                            // mapped entries must be preserved here rather than
                            // converted now. The one FINAL normalization pass happens
                            // only after every classification source has contributed.
                            if ($modelAttribute === 'classifications') {
                                $attrs[$modelAttribute] = $parts;
                            } else {
                                // Specifications: normalize to key/value objects
                                $attrs[$modelAttribute] = $this->normalizeKeyValueMultiValue($parts);
                            }
                        } else {
                            // Normal array fields: just clean and preserve as simple array
                            $cleaned = [];
                            foreach ($parts as $value) {
                                if (is_string($value)) {
                                    $value = trim($value);
                                }
                                if ($value !== '' && $value !== null) {
                                    $cleaned[] = $value;
                                }
                            }
                            $attrs[$modelAttribute] = $cleaned;
                        }
                        continue;
                    }

                    // Type-aware casting based on field_type
                    if ($definition) {
                        $cast = match ($definition->field_type) {
                            'number', 'decimal' => is_numeric($rawValue) ? (float) $rawValue : null,
                            'boolean' => is_bool($rawValue) ? $rawValue : (in_array(strtolower((string) $rawValue), ['true', 'false', '1', '0', 'yes', 'no'], true) ? (bool) $rawValue : false),
                            default => (string) $rawValue,
                        };

                        // Preserve special item_weight fallback
                        if ($fieldKey === 'item_weight_in_pounds') {
                            $cast = is_numeric($rawValue) ? (float) $rawValue : 0.01;
                        }

                        $attrs[$modelAttribute] = $cast;
                    } else {
                        $attrs[$modelAttribute] = (string) $rawValue;
                    }
                }

                // Country of Origin is not a standalone column — it is packed
                // into the classifications JSON array as "COUNTRY_OF_ORIGIN=XX"
                // (the application's canonical classification key/value format).
                // Extract it from the mapped row and merge it into the
                // classifications attribute so it is not silently discarded.
                // The model_attribute for country_of_origin is 'countryOfOrigin'
                // (the CatalogItem accessor), but the processing pipeline uses
                // the field_key 'country_of_origin' as the key in $attrs.
                // Always unset the key so it never reaches
                // CatalogItem::create()/update() as a fake column.
                if (array_key_exists('country_of_origin', $attrs)) {
                    $countryOfOrigin = $attrs['country_of_origin'];

                    unset($attrs['country_of_origin']);

                    if ($countryOfOrigin !== null && $countryOfOrigin !== '') {
                        // The vendor's file provides a country NAME (or a 2-letter code).
                        // Resolve it to the canonical 2-letter code from the
                        // country_codes reference table. Only the code is stored in
                        // classifications — the name is an input, the code is the
                        // normalized stored value.
                        $country = CountryCode::query()
                            ->where('name', trim((string) $countryOfOrigin))
                            ->orWhere('code', strtoupper(trim((string) $countryOfOrigin)))
                            ->first();

                        // Not found — leave Country of Origin absent so the vendor can
                        // correct it manually on the edit screen. Do not invent a code
                        // and do not store the raw name.
                        if ($country !== null) {
                            $classifications = $attrs['classifications'] ?? [];
                            if (! is_array($classifications)) {
                                $classifications = [];
                            }

                            // Replace any existing COUNTRY_OF_ORIGIN= entry with the
                            // resolved code. Prevents duplicates on upload updates.
                            $classifications = array_values(array_filter(
                                $classifications,
                                fn($entry) => ! (
                                    is_string($entry)
                                    && str_starts_with(strtoupper(trim($entry)), 'COUNTRY_OF_ORIGIN=')
                                ),
                            ));
                            $classifications[] = 'COUNTRY_OF_ORIGIN=' . strtoupper($country->code);

                            $attrs['classifications'] = $classifications;
                        }
                    }
                }

                // UNSPSC is packed into the classifications JSON array as
                // "KEY=value" (the application's canonical classification
                // key/value format), making classifications the source of
                // truth for the exported UNSPSC value. The classification key
                // comes from the classification_types table (the source of
                // truth for classification keys), not a hardcoded string.
                // The unspsc_code column is still populated for the edit form
                // and completeness scoring, but the classification entry is
                // what the exporter reads. Replace any existing entry with
                // this key with the newly processed value to prevent
                // duplicates on upload updates. If the uploaded value is
                // empty, no entry is created.
                if (array_key_exists('unspsc_code', $attrs)) {
                    $unspsc = $attrs['unspsc_code'];

                    if ($unspsc !== null && $unspsc !== '') {
                        $unspscType = ClassificationType::query()
                            ->where('key', 'UNSPSC')
                            ->first();

                        if ($unspscType !== null) {
                            $unspscKey = trim((string) $unspscType->key);

                            if ($unspscKey !== '') {
                                $classifications = $attrs['classifications'] ?? [];
                                if (! is_array($classifications)) {
                                    $classifications = [];
                                }

                                // Replace any existing entry with this key with
                                // the newly processed value. Prevents duplicates
                                // on upload updates.
                                $classifications = array_values(array_filter(
                                    $classifications,
                                    fn($entry) => ! (
                                        is_string($entry)
                                        && str_starts_with(strtoupper(trim($entry)), strtoupper($unspscKey) . '=')
                                    ),
                                ));
                                $classifications[] = $unspscKey . '=' . trim((string) $unspsc);

                                $attrs['classifications'] = $classifications;
                            }
                        }
                    }
                }

                // MSDS Link is also represented in the classifications JSON array as
                // "MSDS_URL=<value>" so classifications contains the canonical
                // classification representation used by the catalog item form/export.
                // The msds_link database column remains populated for the rest of the app.
                //
                // If an MSDS_URL classification already exists, replace it with the
                // newly uploaded value to prevent duplicates. If the uploaded value is
                // empty, do not create a classification entry.
                if (array_key_exists('msds_link', $attrs)) {
                    $msdsLink = $attrs['msds_link'];

                    if ($msdsLink !== null && $msdsLink !== '') {
                        $classifications = $attrs['classifications'] ?? [];

                        if (! is_array($classifications)) {
                            $classifications = [];
                        }

                        // Remove any existing MSDS_URL entry before adding the
                        // newly processed value.
                        $classifications = array_values(array_filter(
                            $classifications,
                            fn($entry) => ! (
                                is_string($entry)
                                && str_starts_with(
                                    strtoupper(trim($entry)),
                                    'MSDS_URL='
                                )
                            ),
                        ));

                        $classifications[] = 'MSDS_URL=' . trim((string) $msdsLink);

                        $attrs['classifications'] = $classifications;
                    }
                }

                // FINAL classifications normalization. classifications now holds
                // every contributor's entry — the mapper's own classifications plus
                // Country of Origin, UNSPSC, and MSDS. Only after all of those
                // sources have finished flushing do we normalize the complete value
                // into the canonical key/value object array (the same structure
                // used for specifications) that is persisted to
                // CatalogItem.classifications.
                if (array_key_exists('classifications', $attrs)) {
                    $attrs['classifications'] = $this->normalizeClassifications($attrs['classifications']);
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
                    // A duplicate (vendor_id, dealer_sku) can occur when the same
                    // dealer_sku appears more than once in the upload, or when a
                    // concurrent job races the create. Treat it deterministically:
                    // re-fetch the existing item and update it instead of failing
                    // the whole upload. Match the unique constraint by the dealer_sku
                    // column rather than relying on a specific index name.
                    $isDuplicateSku = $e->getCode() === '23000'
                        && str_contains($e->getMessage(), 'dealer_sku')
                        && str_contains($e->getMessage(), 'Duplicate entry');

                    if ($isDuplicateSku) {
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

                        $warningRows = $upload->rows()
                            ->where('status', 'valid')
                            ->whereNotNull('errors')
                            ->orderBy('row_number')
                            ->get(['row_number', 'errors']);

                        $warningRows = $warningRows->filter(function ($row) {
                            $payload = is_string($row->errors) ? json_decode($row->errors, true) : $row->errors;
                            return is_array($payload) && !empty($payload['warnings'] ?? []);
                        });

                        Notification::route('mail', $client->email)
                            ->notify(new CatalogUploadValidationReportNotification(
                                catalogName: 'Upload #' . $upload->id,
                                processedAt: $upload->processing_completed_at,
                                totalErrors: $upload->invalid_rows,
                                totalWarnings: $warningRows->count(),
                                errors: $failedRows,
                                warnings: $warningRows,
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

            Log::error('ProcessValidatedRowsJob: processing failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $upload->update([
                'status' => CatalogUploadStatus::Failed,
                'failure_reason' => Str::limit('Row processing failed: ' . $e->getMessage(), 5000),
                'processing_completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Normalize multi-value data into the canonical key/value object format.
     * Only used for specifications field.
     *
     * @param  array<int, mixed>  $parts
     * @return array<int, array{key: string, value: string}>
     */
    private function normalizeKeyValueMultiValue(array $parts): array
    {
        $normalized = [];

        foreach ($parts as $entry) {
            // Already canonical
            if (is_array($entry) && isset($entry['key']) && isset($entry['value'])) {
                $key = trim((string) $entry['key']);
                $value = trim((string) $entry['value']);

                if ($key !== '' && $value !== '') {
                    $normalized[] = ['key' => $key, 'value' => $value];
                }
                continue;
            }

            // Associative array: ['Color' => 'Silver']
            if (is_array($entry) && array_keys($entry) !== range(0, count($entry) - 1)) {
                foreach ($entry as $key => $value) {
                    $key = trim((string) $key);
                    $value = trim((string) $value);

                    if ($key !== '' && $value !== '') {
                        $normalized[] = ['key' => $key, 'value' => $value];
                    }
                }
                continue;
            }

            // Indexed string: "Color=Silver"
            if (is_string($entry) && str_contains($entry, '=')) {
                $equalsPos = strpos($entry, '=');
                $key = trim(substr($entry, 0, $equalsPos));
                $value = trim(substr($entry, $equalsPos + 1));

                if ($key !== '' && $value !== '') {
                    $normalized[] = ['key' => $key, 'value' => $value];
                }
            }
        }

        return $normalized;
    }

    /**
     * Normalize the FINAL classifications value into the canonical key/value
     * object structure: [{"key": "...", "value": "..."}, ...] — matching how
     * specifications are stored.
     *
     * This MUST run only after every classification source has contributed to
     * $attrs['classifications']: the mapper's own classifications plus the
     * Country of Origin, UNSPSC, and MSDS processing above.
     *
     * The incoming value may already be any of:
     *   - an empty/null value
     *   - an array of "KEY=value" strings
     *   - an array of key/value objects ({"key": ..., "value": ...})
     *   - an associative array (["Color" => "Silver"])
     *   - a JSON-encoded representation of any of the above
     *   - a mixture produced by the existing processing flow
     *
     * No fixed classification key list is assumed. Case-insensitive duplicate
     * keys collapse into the first-seen position with the LAST value winning,
     * so a later Country of Origin / UNSPSC / MSDS entry replaces an earlier
     * entry for the same key instead of duplicating it.
     *
     * @param  mixed  $value
     * @return array<int, array{key: string, value: string}>|null
     */
    private function normalizeClassifications(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $parts = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [$value];
        } else {
            $parts = is_array($value) ? $value : [];
        }

        $normalized = [];
        $seenKeys = []; // uppercased key => index in $normalized

        foreach ($parts as $entry) {
            $key = null;
            $entryValue = null;

            if (is_array($entry) && isset($entry['key']) && isset($entry['value'])) {
                // Already canonical key/value object
                $key = trim((string) $entry['key']);
                $entryValue = trim((string) $entry['value']);
            } elseif (is_array($entry) && array_keys($entry) !== range(0, count($entry) - 1)) {
                // Associative array: ['Color' => 'Silver'] — take the first pair
                foreach ($entry as $assocKey => $assocValue) {
                    $key = trim((string) $assocKey);
                    $entryValue = trim((string) $assocValue);
                    break;
                }
            } elseif (is_string($entry) && str_contains($entry, '=')) {
                // Indexed string: "Color=Silver" — split on the first '=' only
                $equalsPos = strpos($entry, '=');
                $key = trim(substr($entry, 0, $equalsPos));
                $entryValue = trim(substr($entry, $equalsPos + 1));
            }

            if ($key === '' || $entryValue === '') {
                continue;
            }

            $lookupKey = strtoupper($key);

            if (array_key_exists($lookupKey, $seenKeys)) {
                // Duplicate classification key: keep the first-seen position but
                // let the LAST processed value win (replaces the older entry).
                $normalized[$seenKeys[$lookupKey]]['value'] = $entryValue;
            } else {
                $seenKeys[$lookupKey] = count($normalized);
                $normalized[] = ['key' => $key, 'value' => $entryValue];
            }
        }

        return $normalized === [] ? null : $normalized;
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

            // Dynamic multi-value JSON fields come from the spec metadata; this
            // keeps the comparison logic aligned with the import/export contract.
            // The keys in $newAttrs are field_keys (the processing pipeline
            // writes to the DB column via field_key for relationship-backed
            // fields), so compare against field_key values.
            $multiValueColumns = VitFieldDefinition::all()
                ->where('is_multi_value', true)
                ->pluck('field_key')
                ->values()
                ->all();

            if (in_array($column, $multiValueColumns, true)) {
                $decodedOld = is_array($oldValue) ? $oldValue : json_decode((string) $oldValue, true);
                $decodedNew = is_array($newValue) ? $newValue : json_decode((string) $newValue, true);

                // Get field definition to determine comparison strategy
                $fieldDef = VitFieldDefinition::find($column);

                if ($fieldDef && $fieldDef->is_key_value) {
                    // Specifications: normalize to canonical format and sort by key for comparison
                    if (is_array($decodedOld)) {
                        usort($decodedOld, fn($a, $b) => ($a['key'] ?? '') <=> ($b['key'] ?? ''));
                    }
                    if (is_array($decodedNew)) {
                        usort($decodedNew, fn($a, $b) => ($a['key'] ?? '') <=> ($b['key'] ?? ''));
                    }
                } else {
                    // Normal array fields: sort values for order-independent comparison
                    if (is_array($decodedOld)) {
                        sort($decodedOld);
                    }
                    if (is_array($decodedNew)) {
                        sort($decodedNew);
                    }
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

            if (is_array($oldValue) || is_array($newValue)) {
                // Arrays (e.g., multi-value spec data) — normalize and compare as JSON
                $oldValue = json_encode($oldValue ?? []);
                $newValue = json_encode($newValue ?? []);
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
