<?php

namespace App\Jobs;

use App\Enums\CatalogUploadStatus;
use App\Models\CatalogItem;
use App\Models\CatalogUpload;
use App\Models\ClassificationType;
use App\Models\CountryCode;
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
 * Consumes validated CatalogUploadRow records from ProcessCatalogUploadJob
 * and creates/updates CatalogItem rows in the vendor's catalog.
 *
 * Matching strategy:
 *   1. dealer_sku
 *
 * dealer_sku is the unique identifier for catalog items. The schema
 * enforces a unique constraint on (vendor_id, dealer_sku).
 *
 * When updating an existing CatalogItem, blank CSV values are not written
 * back to the database. Only non-blank values from the CSV overwrite
 * existing data.
 */
class ProcessValidatedRowsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $catalogUploadId) {}

    public function handle(): void
    {
        $upload = $this->loadUpload();

        if (!$this->shouldProcess($upload)) {
            return;
        }

        if (!$this->claimUpload($upload)) {
            return;
        }

        try {
            $this->processUpload($upload);
        } catch (Throwable $e) {
            $this->handleProcessingFailure($upload, $e);

            throw $e;
        }
    }

    /**
     * Load the upload and the relationships required during processing.
     */
    private function loadUpload(): CatalogUpload
    {
        return CatalogUpload::with([
            'vendor',
            'columnMappings',
        ])->findOrFail($this->catalogUploadId);
    }

    /**
     * Determine whether the upload should be processed.
     *
     * Completed uploads are always skipped.
     * ProcessingItems uploads are skipped unless their processing lock
     * has become stale.
     */
    private function shouldProcess(CatalogUpload $upload): bool
    {
        if ($upload->status === CatalogUploadStatus::Completed) {
            return false;
        }

        if ($upload->status === CatalogUploadStatus::ProcessingItems) {
            $processingTimeout = (int) config(
                'catalog.processing_timeout_minutes',
                60
            );

            $isStale = $upload->processing_started_at
                && $upload->processing_started_at
                ->addMinutes($processingTimeout)
                ->isPast();

            if (!$isStale && $upload->processing_started_at !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Atomically claim the upload for item processing.
     */
    private function claimUpload(CatalogUpload $upload): bool
    {
        return DB::transaction(function () use ($upload) {
            $updated = CatalogUpload::where('id', $upload->id)
                ->whereIn('status', [
                    CatalogUploadStatus::Processing,
                    CatalogUploadStatus::ProcessingItems,
                ])
                ->update([
                    'status' => CatalogUploadStatus::ProcessingItems,
                    'processing_started_at' => now(),
                ]);

            return $updated > 0;
        });
    }

    /**
     * Process all valid rows and complete the upload.
     */
    private function processUpload(CatalogUpload $upload): void
    {
        $vendor = $upload->vendor;

        $validRows = $upload->rows()
            ->where('status', 'valid')
            ->cursor();

        $nonComparableColumns = [
            'dealer_sku',
            'vendor_id',
        ];

        $createdCount = 0;
        $updatedCount = 0;
        $unchangedCount = 0;

        DB::beginTransaction();

        foreach ($validRows as $row) {
            $result = $this->processRow(
                $upload,
                $vendor,
                $row,
                $nonComparableColumns
            );

            $createdCount += $result['created'];
            $updatedCount += $result['updated'];
            $unchangedCount += $result['unchanged'];
        }

        DB::commit();

        $emailSent = $this->sendValidationReportIfNeeded($upload);

        if ($emailSent) {
            $this->completeUpload(
                $upload,
                $createdCount,
                $updatedCount,
                $unchangedCount
            );
        }
    }

    /**
     * Process one validated upload row.
     *
     * @return array{created: int, updated: int, unchanged: int}
     */
    private function processRow(
        CatalogUpload $upload,
        $vendor,
        $row,
        array $nonComparableColumns
    ): array {
        $data = $this->prepareRowData($row);

        Log::info('ProcessValidatedRowsJob: processing row', [
            'upload_id' => $upload->id,
            'row_number' => $row->row_number,
            'row_status' => $row->status,
            'row_errors' => $row->errors,
            'dealer_sku' => $data['dealer_sku'] ?? null,
        ]);

        $attrs = $this->buildAttributes($data, $vendor);

        // Associate every item produced by this upload with the catalog the
        // upload belongs to (CatalogUpload.catalog_id -> CatalogItem.catalog_id).
        $attrs['catalog_id'] = $upload->catalog_id;

        /*
         * Classification contributors must be processed in this order:
         *
         * 1. Mapper classifications
         * 2. Country of Origin
         * 3. UNSPSC
         * 4. MSDS
         * 5. Final classifications normalization
         *
         * The final normalization must happen only after all contributors
         * have added their values.
         */
        $this->addCountryOfOriginClassification($attrs);
        $this->addUnspscClassification($attrs);
        $this->addMsdsClassification($attrs);
        $this->normalizeClassificationsAttribute($attrs);

        /*
         * Only non-blank values are allowed to overwrite an existing item.
         */
        $updateAttrs = $this->buildUpdateAttributes($attrs);

        $sellerSku = $attrs['dealer_sku'] ?? null;

        if ($sellerSku) {
            $existing = $this->findExistingItem(
                $vendor->id,
                $sellerSku
            );

            if ($existing) {
                return $this->updateExistingItem(
                    $existing,
                    $updateAttrs,
                    $nonComparableColumns,
                    $sellerSku
                );
            }
        }

        return $this->createItem(
            $attrs,
            $updateAttrs,
            $vendor->id,
            $sellerSku
        );
    }

    /**
     * Convert the row data into a usable array.
     */
    private function prepareRowData($row): array
    {
        $data = $row->data;

        if (!is_array($data)) {
            $data = json_decode(
                (string) ($data ?? ''),
                true
            ) ?: [];
        }

        return $data;
    }

    /**
     * Build the CatalogItem attribute map using VitFieldDefinition
     * as the source of truth.
     */
    private function buildAttributes(array $data, $vendor): array
    {
        $attrs = [
            'vendor_id' => $vendor->id,
        ];

        $definitions = VitFieldDefinition::all()
            ->whereIn('field_key', array_keys($data))
            ->keyBy('field_key');

        foreach ($data as $fieldKey => $rawValue) {
            $definition = $definitions->get($fieldKey);

            $modelAttribute = $this->resolveModelAttribute(
                $fieldKey,
                $definition
            );

            if ($this->handleBlankValue(
                $attrs,
                $fieldKey,
                $modelAttribute,
                $rawValue
            )) {
                continue;
            }

            if ($definition && $definition->is_multi_value) {
                $this->processMultiValueField(
                    $attrs,
                    $fieldKey,
                    $modelAttribute,
                    $rawValue,
                    $definition
                );

                continue;
            }

            $attrs[$modelAttribute] = $this->castFieldValue(
                $fieldKey,
                $rawValue,
                $definition
            );
        }

        return $attrs;
    }

    /**
     * Resolve the actual database attribute used by the processing pipeline.
     *
     * Relationship paths and accessors used by the export layer cannot be
     * written directly to CatalogItem, so those fields fall back to their
     * field_key.
     */
    private function resolveModelAttribute(
        string $fieldKey,
        $definition
    ): string {
        $modelAttribute = $definition
            ? ($definition->model_attribute ?? $fieldKey)
            : $fieldKey;

        if (
            str_contains($modelAttribute, '.')
            || $modelAttribute === 'countryOfOrigin'
        ) {
            return $fieldKey;
        }

        return $modelAttribute;
    }

    /**
     * Handle blank input values.
     *
     * @return bool True when processing for this field is complete.
     */
    private function handleBlankValue(
        array &$attrs,
        string $fieldKey,
        string $modelAttribute,
        mixed $rawValue
    ): bool {
        if (
            !is_null($rawValue)
            && $rawValue !== ''
            && $rawValue !== []
        ) {
            return false;
        }

        if ($fieldKey === 'item_weight_in_pounds') {
            $attrs[$modelAttribute] = 0.01;

            return true;
        }

        // is_discontinued must never be NULL.
        if ($fieldKey === 'discontinued') {
            $attrs[$modelAttribute] = false;

            return true;
        }

        // CatalogItem.availability is INTEGER NOT NULL DEFAULT 0
        if ($fieldKey === 'availability') {
            $attrs[$modelAttribute] = 0;

            return true;
        }

        $attrs[$modelAttribute] = null;

        return true;
    }

    /**
     * Process fields marked as multi-value in VitFieldDefinition.
     *
     * Specifications are normalized immediately.
     * Classifications are intentionally kept raw because additional
     * classification values are added later in the pipeline.
     */
    private function processMultiValueField(
        array &$attrs,
        string $fieldKey,
        string $modelAttribute,
        mixed $rawValue,
        $definition
    ): void {
        $parts = is_array($rawValue)
            ? $rawValue
            : json_decode((string) $rawValue, true);

        if (!is_array($parts)) {
            $parts = [];
        }

        if ($definition->is_key_value) {
            /*
             * Classifications collect contributions from:
             *
             * - mapper classifications
             * - Country of Origin
             * - UNSPSC
             * - MSDS
             *
             * Therefore they must not be normalized until all sources
             * have contributed.
             */
            if ($modelAttribute === 'classifications') {
                $attrs[$modelAttribute] = $parts;

                return;
            }

            // Specifications use the canonical key/value structure.
            $attrs[$modelAttribute] =
                $this->normalizeKeyValueMultiValue($parts);

            return;
        }

        // Normal array fields are cleaned and preserved as simple arrays.
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

    /**
     * Cast a normal field according to its VitFieldDefinition field_type.
     */
    private function castFieldValue(
        string $fieldKey,
        mixed $rawValue,
        $definition
    ): mixed {
        if (!$definition) {
            return (string) $rawValue;
        }

        $cast = match ($definition->field_type) {
            'number', 'decimal' => is_numeric($rawValue)
                ? (float) $rawValue
                : null,

            'boolean' => is_bool($rawValue)
                ? $rawValue
                : (
                    in_array(
                        strtolower((string) $rawValue),
                        ['true', 'false', '1', '0', 'yes', 'no'],
                        true
                    )
                    ? (bool) $rawValue
                    : false
                ),

            default => (string) $rawValue,
        };

        // Preserve the existing item_weight fallback behavior.
        if ($fieldKey === 'item_weight_in_pounds') {
            $cast = is_numeric($rawValue)
                ? (float) $rawValue
                : 0.01;
        }

        // availability is stored in an INTEGER NOT NULL column; coerce numeric
        // input to an integer and fall back to the column default .
        if ($fieldKey === 'availability') {
            $cast = is_numeric($rawValue)
                ? (int) $rawValue
                : 0;
        }

        return $cast;
    }

    /**
     * Add Country of Origin to classifications.
     *
     * Country of Origin is not stored as a standalone classification
     * column. The normalized country code is stored in classifications as:
     *
     * COUNTRY_OF_ORIGIN=XX
     */
    private function addCountryOfOriginClassification(array &$attrs): void
    {
        if (!array_key_exists('country_of_origin', $attrs)) {
            return;
        }

        $countryOfOrigin = $attrs['country_of_origin'];

        unset($attrs['country_of_origin']);

        if ($countryOfOrigin === null || $countryOfOrigin === '') {
            return;
        }

        $country = CountryCode::query()
            ->where('name', trim((string) $countryOfOrigin))
            ->orWhere(
                'code',
                strtoupper(trim((string) $countryOfOrigin))
            )
            ->first();

        /*
         * If the country cannot be resolved, do not invent a value and
         * do not store the raw country name.
         */
        if ($country === null) {
            return;
        }

        $classifications = $this->getClassificationsArray($attrs);

        /*
         * Replace an existing COUNTRY_OF_ORIGIN entry so repeated uploads
         * do not create duplicates.
         */
        $classifications = array_values(array_filter(
            $classifications,
            fn($entry) => !(
                is_string($entry)
                && str_starts_with(
                    strtoupper(trim($entry)),
                    'COUNTRY_OF_ORIGIN='
                )
            )
        ));

        $classifications[] =
            'COUNTRY_OF_ORIGIN=' . strtoupper($country->code);

        $attrs['classifications'] = $classifications;
    }

    /**
     * Add UNSPSC to classifications.
     *
     * The classification key comes from ClassificationType rather than
     * being assumed by the processing layer.
     */
    private function addUnspscClassification(array &$attrs): void
    {
        if (!array_key_exists('unspsc_code', $attrs)) {
            return;
        }

        $unspsc = $attrs['unspsc_code'];

        // unspsc_code has no standalone database column; the persisted value
        // lives only inside classifications.
        unset($attrs['unspsc_code']);

        if ($unspsc === null || $unspsc === '') {
            return;
        }

        $unspscType = ClassificationType::query()
            ->where('key', 'UNSPSC')
            ->first();

        if ($unspscType === null) {
            return;
        }

        $unspscKey = trim((string) $unspscType->key);

        if ($unspscKey === '') {
            return;
        }

        $classifications = $this->getClassificationsArray($attrs);

        /*
         * Replace an existing entry with this key to prevent duplicates
         * on repeated uploads.
         */
        $classifications = array_values(array_filter(
            $classifications,
            fn($entry) => !(
                is_string($entry)
                && str_starts_with(
                    strtoupper(trim($entry)),
                    strtoupper($unspscKey) . '='
                )
            )
        ));

        $classifications[] =
            $unspscKey . '=' . trim((string) $unspsc);

        $attrs['classifications'] = $classifications;
    }

    /**
     * Add MSDS URL to classifications.
     *
     * msds_link has no standalone database column; the value is persisted
     * only in classifications as:
     *
     * MSDS_URL=<value>
     */
    private function addMsdsClassification(array &$attrs): void
    {
        if (!array_key_exists('msds_link', $attrs)) {
            return;
        }

        $msdsLink = $attrs['msds_link'];

        // msds_link has no standalone database column; the persisted value
        // lives only inside classifications.
        unset($attrs['msds_link']);

        if ($msdsLink === null || $msdsLink === '') {
            return;
        }

        $classifications = $this->getClassificationsArray($attrs);

        /*
         * Remove any existing MSDS_URL entry before adding the new value.
         */
        $classifications = array_values(array_filter(
            $classifications,
            fn($entry) => !(
                is_string($entry)
                && str_starts_with(
                    strtoupper(trim($entry)),
                    'MSDS_URL='
                )
            )
        ));

        $classifications[] =
            'MSDS_URL=' . trim((string) $msdsLink);

        $attrs['classifications'] = $classifications;
    }

    /**
     * Safely retrieve the classifications currently accumulated in attrs.
     */
    private function getClassificationsArray(array $attrs): array
    {
        $classifications = $attrs['classifications'] ?? [];

        return is_array($classifications)
            ? $classifications
            : [];
    }

    /**
     * Perform the final classifications normalization.
     *
     * This deliberately runs after every classification contributor has
     * finished:
     *
     * mapper
     * Country of Origin
     * UNSPSC
     * MSDS
     *
     * The resulting structure matches specifications:
     *
     * [
     *     ['key' => 'Color', 'value' => 'gray'],
     *     ['key' => 'Size', 'value' => 'Small'],
     * ]
     */
    private function normalizeClassificationsAttribute(array &$attrs): void
    {
        if (!array_key_exists('classifications', $attrs)) {
            return;
        }

        $attrs['classifications'] =
            $this->normalizeClassifications($attrs['classifications']);
    }

    /**
     * Build the update payload.
     *
     * Blank CSV values must never overwrite existing database values.
     */
    private function buildUpdateAttributes(array $attrs): array
    {
        $updateAttrs = array_filter(
            $attrs,
            fn($value) =>
            $value !== null
                && $value !== ''
                && $value !== [],
            ARRAY_FILTER_USE_BOTH
        );

        // vendor_id is metadata and must never be bulk-overwritten.
        unset($updateAttrs['vendor_id']);

        return $updateAttrs;
    }

    /**
     * Find an existing catalog item by vendor and dealer SKU.
     */
    private function findExistingItem(
        $vendorId,
        string $sellerSku
    ): ?CatalogItem {
        return CatalogItem::where('vendor_id', $vendorId)
            ->where('dealer_sku', $sellerSku)
            ->first();
    }

    /**
     * Update an existing CatalogItem if meaningful changes are detected.
     *
     * @return array{created: int, updated: int, unchanged: int}
     */
    private function updateExistingItem(
        CatalogItem $existing,
        array $updateAttrs,
        array $nonComparableColumns,
        string $sellerSku
    ): array {
        $normalizedUpdateAttrs =
            $this->normalizeForComparison($updateAttrs);

        if (
            $this->hasMeaningfulChanges(
                $existing,
                $normalizedUpdateAttrs,
                $nonComparableColumns,
                $sellerSku
            )
        ) {
            $updateAttrs['updated_at'] = now();

            $existing->update($updateAttrs);

            return [
                'created' => 0,
                'updated' => 1,
                'unchanged' => 0,
            ];
        }

        return [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 1,
        ];
    }

    /**
     * Create a new CatalogItem.
     *
     * If the unique dealer SKU constraint is hit, re-fetch the existing
     * item and update it instead.
     *
     * @return array{created: int, updated: int, unchanged: int}
     */
    private function createItem(
        array $attrs,
        array $updateAttrs,
        $vendorId,
        ?string $sellerSku
    ): array {
        $attrs['id'] = (string) Str::uuid();
        $attrs['created_at'] = now();
        $attrs['updated_at'] = now();

        try {
            CatalogItem::create($attrs);

            return [
                'created' => 1,
                'updated' => 0,
                'unchanged' => 0,
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            if (!$this->isDuplicateDealerSkuException($e)) {
                throw $e;
            }

            $existing = $this->findExistingItem(
                $vendorId,
                $sellerSku
            );

            if (!$existing) {
                throw $e;
            }

            $updateAttrs['updated_at'] = now();

            $existing->update($updateAttrs);

            return [
                'created' => 0,
                'updated' => 1,
                'unchanged' => 0,
            ];
        }
    }

    /**
     * Determine whether a database exception represents the expected
     * duplicate dealer SKU race/duplicate-upload case.
     */
    private function isDuplicateDealerSkuException(
        \Illuminate\Database\QueryException $e
    ): bool {
        return $e->getCode() === '23000'
            && str_contains(
                $e->getMessage(),
                'dealer_sku'
            )
            && str_contains(
                $e->getMessage(),
                'Duplicate entry'
            );
    }

    /**
     * Send the validation report when required.
     *
     * @return bool True when processing may be marked completed.
     */
    private function sendValidationReportIfNeeded(
        CatalogUpload $upload
    ): bool {
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

            $warningRows = $warningRows->filter(function ($row) {
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

    /**
     * Mark the upload as completed and store processing statistics.
     */
    private function completeUpload(
        CatalogUpload $upload,
        int $createdCount,
        int $updatedCount,
        int $unchangedCount
    ): void {
        $upload->update([
            'status' => CatalogUploadStatus::Completed,
            'total_rows' => $upload->rows()->count(),
            'success_rows' =>
            $createdCount
                + $updatedCount
                + $unchangedCount,
            'created_rows' => $createdCount,
            'updated_rows' => $updatedCount,
            'unchanged_rows' => $unchangedCount,
            'invalid_rows' =>
            $upload->rows()
                ->where('status', 'invalid')
                ->count(),
            'processing_completed_at' => now(),
        ]);
    }

    /**
     * Handle a processing failure.
     */
    private function handleProcessingFailure(
        CatalogUpload $upload,
        Throwable $e
    ): void {
        DB::rollBack();

        Log::error(
            'ProcessValidatedRowsJob: processing failed',
            [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]
        );

        $upload->update([
            'status' => CatalogUploadStatus::Failed,
            'failure_reason' => Str::limit(
                'Row processing failed: ' . $e->getMessage(),
                5000
            ),
            'processing_completed_at' => now(),
        ]);
    }

    /**
     * Normalize multi-value data into the canonical key/value object format.
     *
     * Used by specifications.
     *
     * @param array<int, mixed> $parts
     * @return array<int, array{key: string, value: string}>
     */
    private function normalizeKeyValueMultiValue(array $parts): array
    {
        $normalized = [];

        foreach ($parts as $entry) {
            // Already canonical.
            if (
                is_array($entry)
                && isset($entry['key'])
                && isset($entry['value'])
            ) {
                $key = trim((string) $entry['key']);
                $value = trim((string) $entry['value']);

                if ($key !== '' && $value !== '') {
                    $normalized[] = [
                        'key' => $key,
                        'value' => $value,
                    ];
                }

                continue;
            }

            // Associative array: ['Color' => 'Silver'].
            if (
                is_array($entry)
                && array_keys($entry) !== range(
                    0,
                    count($entry) - 1
                )
            ) {
                foreach ($entry as $key => $value) {
                    $key = trim((string) $key);
                    $value = trim((string) $value);

                    if ($key !== '' && $value !== '') {
                        $normalized[] = [
                            'key' => $key,
                            'value' => $value,
                        ];
                    }
                }

                continue;
            }

            // Indexed string: "Color=Silver".
            if (
                is_string($entry)
                && str_contains($entry, '=')
            ) {
                $equalsPos = strpos($entry, '=');

                $key = trim(
                    substr($entry, 0, $equalsPos)
                );

                $value = trim(
                    substr($entry, $equalsPos + 1)
                );

                if ($key !== '' && $value !== '') {
                    $normalized[] = [
                        'key' => $key,
                        'value' => $value,
                    ];
                }
            }
        }

        return $normalized;
    }

    /**
     * Normalize the final classifications value into:
     *
     * [
     *     ['key' => 'Color', 'value' => 'gray'],
     *     ['key' => 'Size', 'value' => 'Small'],
     * ]
     *
     * This must run only after all classification sources have contributed.
     *
     * Supported input formats include:
     *
     * - null / empty
     * - KEY=value strings
     * - key/value objects
     * - associative arrays
     * - JSON representations
     * - mixtures of the above
     *
     * No fixed classification key list is assumed.
     *
     * Duplicate keys are case-insensitive. The first position is preserved,
     * while the last processed value wins.
     *
     * @return array<int, array{key: string, value: string}>|null
     */
    private function normalizeClassifications(
        mixed $value
    ): ?array {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            $parts =
                json_last_error() === JSON_ERROR_NONE
                && is_array($decoded)
                ? $decoded
                : [$value];
        } else {
            $parts = is_array($value)
                ? $value
                : [];
        }

        $normalized = [];
        $seenKeys = [];

        foreach ($parts as $entry) {
            $key = null;
            $entryValue = null;

            // Already canonical.
            if (
                is_array($entry)
                && isset($entry['key'])
                && isset($entry['value'])
            ) {
                $key = trim((string) $entry['key']);
                $entryValue = trim((string) $entry['value']);
            }

            // Associative array: ['Color' => 'Silver'].
            elseif (
                is_array($entry)
                && array_keys($entry) !== range(
                    0,
                    count($entry) - 1
                )
            ) {
                foreach ($entry as $assocKey => $assocValue) {
                    $key = trim((string) $assocKey);
                    $entryValue = trim((string) $assocValue);

                    break;
                }
            }

            // Indexed string: "Color=Silver".
            elseif (
                is_string($entry)
                && str_contains($entry, '=')
            ) {
                $equalsPos = strpos($entry, '=');

                $key = trim(
                    substr($entry, 0, $equalsPos)
                );

                $entryValue = trim(
                    substr($entry, $equalsPos + 1)
                );
            }

            if ($key === null || $entryValue === null) {
                continue;
            }

            if ($key === '' || $entryValue === '') {
                continue;
            }

            $lookupKey = strtoupper($key);

            if (array_key_exists($lookupKey, $seenKeys)) {
                /*
                 * Preserve the original position but allow the latest
                 * value to replace the previous value.
                 */
                $normalized[$seenKeys[$lookupKey]]['value'] = $entryValue;

                continue;
            }

            $seenKeys[$lookupKey] = count($normalized);

            $normalized[] = [
                'key' => $key,
                'value' => $entryValue,
            ];
        }

        return $normalized === []
            ? null
            : $normalized;
    }

    /**
     * Normalize incoming CSV values for comparison against database values.
     *
     * This ensures consistent comparison regardless of how CSV values
     * were typed.
     */
    private function normalizeForComparison(
        array $attrs
    ): array {
        $normalized = [];

        foreach ($attrs as $key => $value) {
            /*
             * Treat the default item_weight fallback as blank.
             */
            if (
                $key === 'item_weight'
                && $value === 0.01
            ) {
                continue;
            }

            /*
             * Normalize numeric strings to floats.
             */
            if (is_numeric($value)) {
                $normalized[$key] = (float) $value;

                continue;
            }

            /*
             * Empty strings and arrays are treated as blank.
             */
            if ($value === '' || $value === []) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Compare incoming values against an existing CatalogItem.
     *
     * Multi-value fields are compared structurally rather than as raw
     * JSON strings.
     */
    private function hasMeaningfulChanges(
        CatalogItem $existing,
        array $newAttrs,
        array $nonComparableColumns,
        ?string $sku = null
    ): bool {
        $detectedChanges = [];

        /*
         * Load the multi-value field definitions once rather than repeatedly
         * resolving them inside the field loop.
         */
        $multiValueFields = VitFieldDefinition::all()
            ->where('is_multi_value', true)
            ->pluck('field_key')
            ->values()
            ->all();

        foreach ($newAttrs as $column => $newValue) {
            if (
                in_array(
                    $column,
                    $nonComparableColumns,
                    true
                )
            ) {
                continue;
            }

            $oldValue = $existing->getRawOriginal($column);

            $oldValue =
                $this->normalizeValueForComparison($oldValue);

            $newValue =
                $this->normalizeValueForComparison($newValue);

            /*
             * Both values are empty.
             */
            if (
                $oldValue === null
                && $newValue === null
            ) {
                continue;
            }

            /*
             * Treat null and empty string as equivalent.
             */
            if (
                $oldValue === null
                && $newValue === ''
            ) {
                continue;
            }

            if (
                $newValue === null
                && $oldValue === ''
            ) {
                continue;
            }

            /*
             * Multi-value fields require structural comparison.
             */
            if (
                in_array(
                    $column,
                    $multiValueFields,
                    true
                )
            ) {
                if (
                    !$this->multiValueValuesAreEqual(
                        $column,
                        $oldValue,
                        $newValue
                    )
                ) {
                    $detectedChanges[$column] = [
                        'old' => $this->decodeComparisonValue(
                            $oldValue
                        ),
                        'new' => $this->decodeComparisonValue(
                            $newValue
                        ),
                    ];
                }

                continue;
            }

            /*
             * Numeric values are compared numerically.
             */
            if (
                is_numeric($oldValue)
                && is_numeric($newValue)
            ) {
                if (
                    (float) $oldValue
                    !== (float) $newValue
                ) {
                    $detectedChanges[$column] = [
                        'old' => $oldValue,
                        'new' => $newValue,
                    ];
                }

                continue;
            }

            /*
             * Arrays that reach this point are compared as JSON.
             */
            if (
                is_array($oldValue)
                || is_array($newValue)
            ) {
                $oldValue = json_encode(
                    $oldValue ?? []
                );

                $newValue = json_encode(
                    $newValue ?? []
                );
            }

            /*
             * Final string comparison.
             */
            if (
                trim((string) $oldValue)
                !== trim((string) $newValue)
            ) {
                $detectedChanges[$column] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        if (!empty($detectedChanges)) {
            Log::info(
                'Catalog import: detected changes for SKU',
                [
                    'sku' => $sku,
                    'changes' => $detectedChanges,
                ]
            );

            return true;
        }

        return false;
    }

    /**
     * Compare two multi-value fields according to their definition.
     */
    private function multiValueValuesAreEqual(
        string $column,
        mixed $oldValue,
        mixed $newValue
    ): bool {
        $decodedOld =
            $this->decodeComparisonValue($oldValue);

        $decodedNew =
            $this->decodeComparisonValue($newValue);

        $fieldDefinition =
            VitFieldDefinition::find($column);

        if (
            $fieldDefinition
            && $fieldDefinition->is_key_value
        ) {
            /*
             * Specifications/classifications are key/value structures.
             *
             * Sort by key so ordering differences do not produce false
             * updates.
             */
            if (is_array($decodedOld)) {
                usort(
                    $decodedOld,
                    fn($a, $b) => ($a['key'] ?? '')
                        <=>
                        ($b['key'] ?? '')
                );
            }

            if (is_array($decodedNew)) {
                usort(
                    $decodedNew,
                    fn($a, $b) => ($a['key'] ?? '')
                        <=>
                        ($b['key'] ?? '')
                );
            }
        } else {
            /*
             * Normal arrays are order-independent.
             */
            if (is_array($decodedOld)) {
                sort($decodedOld);
            }

            if (is_array($decodedNew)) {
                sort($decodedNew);
            }
        }

        return $decodedOld === $decodedNew;
    }

    /**
     * Decode a JSON value for structural comparison.
     */
    private function decodeComparisonValue(
        mixed $value
    ): mixed {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode(
            (string) $value,
            true
        );

        return is_array($decoded)
            ? $decoded
            : $value;
    }

    /**
     * Normalize a single value for comparison.
     *
     * Trims whitespace and converts empty strings to null.
     */
    private function normalizeValueForComparison(
        mixed $value
    ): mixed {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === ''
                ? null
                : $trimmed;
        }

        return $value;
    }
}
