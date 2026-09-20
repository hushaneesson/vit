<?php

namespace App\Jobs;

use App\Enums\CatalogUploadStatus;
use App\Models\CatalogItem;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
use App\Models\ClassificationType;
use App\Models\CommodityType;
use App\Models\UnitOfMeasure;
use App\Services\Catalog\CatalogItemProcessor;
use App\Services\Catalog\CatalogRowValidator;
use App\Services\Catalog\CatalogValidationReportService;
use App\Services\VitFieldDefinition;
use App\Services\WeightUnitConverter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessValidatedRowsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry a chunk that was interrupted (timeout, worker death, transient
     * infrastructure fault) a bounded number of times before escalating.
     * 3 attempts mirrors the parent ProcessCatalogUploadJob retry policy.
     */
    public int $tries = 3;

    /**
     * System-error marker used by markRowFailed() / recordSystemFailure() so
     * the validation-report builder can classify a row as a processing failure
     * distinct from a vendor validation error.
     */
    public const SYSTEM_ERROR_FIELD = '_system';

    private int $catalogUploadId;

    private int $firstRowId;

    private int $lastRowId;

    private bool $isVitFileImport;

    private Collection $columnToFieldKey;

    private Collection $columnMappingsByIndex;

    private Collection $activeFields;

    private array $rangeFieldKeys;

    private array $lookupMaps;

    /**
     * The map is keyed by the value the database returned, so a lookup only
     * ever hits when the database value is byte-identical to the SKU being
     * checked. That keeps the answer correct under any collation: a
     * byte-equal value always compares equal, while a value that differs
     * only by case/accent is never treated as a hit and instead falls back
     * to the legacy per-row query.
     *
     * @var array<string, CatalogItem>
     */
    private array $existingItemsBySku = [];

    /** @var array<string, true> */
    private array $requestedSkuKeys = [];

    private bool $preloadProvedAbsentForRequestedSkus = false;

    private bool $existingItemsPreloaded = false;

    private ?ClassificationType $unspscClassificationType = null;

    private bool $unspscTypeResolved = false;

    private string $weightUnit;

    public function __construct(
        int $catalogUploadId,
        int $firstRowId,
        int $lastRowId,
        bool $isVitFileImport = false
    ) {
        $this->catalogUploadId = $catalogUploadId;
        $this->firstRowId = $firstRowId;
        $this->lastRowId = $lastRowId;
        $this->isVitFileImport = $isVitFileImport;
    }

    public function handle(CatalogItemProcessor $itemProcessor, CatalogRowValidator $validator): void
    {
        $upload = $this->loadUpload();

        if ($upload->status === CatalogUploadStatus::Completed) {
            return;
        }

        $this->buildContext($upload);

        $this->resolveUnspscClassificationTypeIfMapped();

        $stagedRows = $this->loadStagedRows();

        $this->preloadExistingItems($upload, $stagedRows);

        $counts = $this->rowCounts([]);

        foreach ($stagedRows as $stagedRow) {
            $rowCells = $stagedRow->raw_data ?? [];

            $rowCounts = $this->processRow($upload, $itemProcessor, $validator, $stagedRow, $rowCells);

            foreach ($counts as $key => $value) {
                $counts[$key] += $rowCounts[$key] ?? 0;
            }
        }

        $this->finalizeChunk($upload, $counts);
    }

    /**
     * Retry boundary. Laravel invokes this after the final attempt is
     * exhausted (including timeouts and worker kills that never enter the
     * handle() try/catch). It guarantees the chunk's rows never stay at
     * status='valid' and the upload always reaches a terminal state.
     *
     * Per the design: the parent ProcessCatalogUploadJob owns upload-level
     * Failed; this child only records system errors on its stranded rows and
     * then asks the normal completion check whether the upload as a whole is
     * done. Later chunks are untouched.
     */
    public function failed(Throwable $exception): void
    {
        try {
            $upload = CatalogUpload::find($this->catalogUploadId);

            if ($upload === null) {
                return;
            }

            $this->recordSystemFailure($upload, $exception);
        } catch (Throwable $e) {
            Log::warning('ProcessValidatedRowsJob: failed() bookkeeping error', [
                'upload_id' => $this->catalogUploadId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark every row in this chunk's range that is still 'valid' (i.e. not yet
     * processed) as a system processing error, so completeUploadIfDone() can
     * count it toward completion. Already-invalid / already-failed rows are
     * left untouched (idempotent for retries).
     */
    private function recordSystemFailure(CatalogUpload $upload, Throwable $exception): void
    {
        $failureMessage = 'System error while processing this row. The system was unable to complete processing after multiple attempts.';

        $chunkRows = DB::table('catalog_upload_rows')
            ->where('catalog_upload_id', $upload->id)
            ->where('id', '>=', $this->firstRowId)
            ->where('id', '<=', $this->lastRowId);

        $markedCount = $chunkRows
            ->where('status', CatalogUploadRow::STATUS_VALID)
            ->update([
                'status' => CatalogUploadRow::STATUS_FAILED,
                'errors' => json_encode([
                    self::SYSTEM_ERROR_FIELD => [
                        [
                            'field_key' => '_system',
                            'message' => $failureMessage,
                        ],
                    ],
                ]),
                'data' => json_encode(['_processing_failed' => true]),
            ]);

        $strayCount = DB::table('catalog_upload_rows')
            ->where('catalog_upload_id', $upload->id)
            ->where('id', '>=', $this->firstRowId)
            ->where('id', '<=', $this->lastRowId)
            ->whereNull('data')
            ->count();

        if ($strayCount > 0) {
            DB::table('catalog_upload_rows')
                ->where('catalog_upload_id', $upload->id)
                ->where('id', '>=', $this->firstRowId)
                ->where('id', '<=', $this->lastRowId)
                ->whereNull('data')
                ->update([
                    'status' => CatalogUploadRow::STATUS_FAILED,
                    'errors' => json_encode([
                        self::SYSTEM_ERROR_FIELD => [
                            [
                                'field_key' => '_system',
                                'message' => $failureMessage,
                            ],
                        ],
                    ]),
                    'data' => json_encode(['_processing_failed' => true]),
                ]);
        }

        /*
         * failed_rows must advance by the number of rows this dead chunk
         * actually converted to system errors (not a constant 1), otherwise
         * completeUploadIfDone() sees processed < total_rows forever and the
         * upload stays in Processing indefinitely.
         */
        $systemErrorCount = $markedCount + $strayCount;

        if ($systemErrorCount > 0) {
            CatalogUpload::whereKey($upload->id)->increment('failed_rows', $systemErrorCount);
        }

        $this->completeUploadIfDone($upload);
    }

    private function loadUpload(): CatalogUpload
    {
        return CatalogUpload::with([
            'vendor',
            'columnMappings',
        ])->findOrFail($this->catalogUploadId);
    }

    private function loadStagedRows(): \Illuminate\Database\Eloquent\Collection
    {
        // Only process rows whose mapped data is still empty.
        // This prevents a retried chunk from processing rows that already completed.
        return CatalogUploadRow::where('catalog_upload_id', $this->catalogUploadId)
            ->where('id', '>=', $this->firstRowId)
            ->where('id', '<=', $this->lastRowId)
            ->orderBy('id')
            // A3: only the columns the processing flow uses. The in-PHP
            // empty($row->data) retry filter below is intentionally unchanged.
            ->select([
                'id',
                'catalog_upload_id',
                'row_number',
                'raw_data',
                'data',
                'status',
                'errors',
            ])
            ->get()
            ->filter(fn (Model $row) => empty($row->data));
    }

    private function preloadExistingItems(CatalogUpload $upload, $stagedRows): void
    {
        $this->existingItemsBySku = [];
        $this->requestedSkuKeys = [];
        $this->preloadProvedAbsentForRequestedSkus = false;
        $this->existingItemsPreloaded = true;

        $skuKeys = [];

        foreach ($stagedRows as $row) {
            $cells = $row->raw_data ?? [];

            if (! is_array($cells)) {
                continue;
            }

            foreach ($cells as $colIndex => $rawValue) {
                if (($this->columnToFieldKey->get($colIndex) ?? null) !== 'dealer_sku') {
                    continue;
                }

                $sku = trim((string) $rawValue);

                if ($sku === '') {
                    continue;
                }

                $skuKeys[$sku] = true;
            }
        }

        $this->requestedSkuKeys = $skuKeys;

        if ($skuKeys === []) {
            return;
        }

        $items = CatalogItem::query()
            ->select(['id', 'vendor_id', 'dealer_sku', 'catalog_id'])
            ->where('vendor_id', $upload->vendor_id)
            ->whereIn('dealer_sku', array_keys($skuKeys))
            ->get();

        foreach ($items as $item) {
            // (vendor_id, dealer_sku) is unique, so there is at most one row
            // per exact key; first-wins mirrors the legacy ->first() pick.
            $this->existingItemsBySku[$item->dealer_sku] ??= $item;
        }

        // Zero rows back for a non-empty key set is the only outcome that
        // proves absence for every requested SKU under any collation.
        $this->preloadProvedAbsentForRequestedSkus = $items->isEmpty();
    }

    /**
     * Byte-exact preload hit, or null when the preload does not contain this
     * exact SKU (which may mean "does not exist" or "cannot be answered
     * without querying").
     */
    private function preloadedExistingItem(?string $sku): ?CatalogItem
    {
        if ($sku === null || $sku === '') {
            return null;
        }

        return $this->existingItemsBySku[$sku] ?? null;
    }

    /**
     * True when the SKU was part of the preload query and the preload proved
     * that no catalog item exists for this vendor + dealer_sku. Only used to
     * skip the processor's otherwise identical lookup; any SKU that was not
     * requested, or whose batch contained matches, falls back to the legacy
     * query.
     */
    private function skuProvenAbsent(?string $sku): bool
    {
        if (! $this->existingItemsPreloaded || $sku === null || $sku === '') {
            return false;
        }

        return $this->preloadProvedAbsentForRequestedSkus
            && isset($this->requestedSkuKeys[$sku]);
    }

    /**
     * A2: resolve the UNSPSC ClassificationType once per job execution instead
     * of once per row. Uploads without a column mapped to unspsc_code cannot
     * produce UNSPSC data, so they skip the lookup entirely.
     */
    private function resolveUnspscClassificationTypeIfMapped(): void
    {
        if ($this->unspscTypeResolved) {
            return;
        }

        $this->unspscTypeResolved = true;

        if (! $this->columnToFieldKey->contains('unspsc_code')) {
            return;
        }

        $this->unspscClassificationType = ClassificationType::query()
            ->where('key', 'UNSPSC')
            ->first();
    }

    private function buildContext(CatalogUpload $upload): void
    {
        $this->columnToFieldKey = $this->buildColumnToFieldKey($upload);

        $this->columnMappingsByIndex = $upload->columnMappings->keyBy('column_index');

        $this->activeFields = VitFieldDefinition::active()->keyBy('field_key');

        $this->rangeFieldKeys = $this->buildRangeFieldKeys($upload);

        $this->lookupMaps = $this->buildLookupMaps();

        $weightMapping = $upload->columnMappings->firstWhere('field_key', 'item_weight_in_pounds');

        $this->weightUnit = $weightMapping && ! empty($weightMapping->source_separator)
            ? (string) $weightMapping->source_separator
            : WeightUnitConverter::DEFAULT_UNIT;
    }

    /**
     * Default zero counts for one row/chunk, with the given fields overridden.
     * Every return path through processRow()/processRowInner() used to retype
     * this six-key array by hand; centralizing it means a new counter only
     * needs to be added here once.
     */
    private function rowCounts(array $overrides): array
    {
        return array_merge([
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'success' => 0,
            'invalid' => 0,
            'failed' => 0,
        ], $overrides);
    }

    private function processRow(
        CatalogUpload $upload,
        CatalogItemProcessor $itemProcessor,
        CatalogRowValidator $validator,
        CatalogUploadRow $stagedRow,
        array $rowCells
    ): array {
        try {
            return $this->processRowInner($upload, $itemProcessor, $validator, $stagedRow, $rowCells);
        } catch (Throwable $e) {
            $this->markRowFailed($stagedRow, $e);

            return $this->rowCounts(['failed' => 1]);
        }
    }

    private function processRowInner(
        CatalogUpload $upload,
        CatalogItemProcessor $itemProcessor,
        CatalogRowValidator $validator,
        CatalogUploadRow $stagedRow,
        array $rowCells
    ): array {
        $mappedData = $this->mapRow($rowCells, $upload->columnMappings);

        $validationResult = $this->validateMappedRow($upload, $validator, $mappedData);

        if ($validationResult['status'] !== 'valid') {
            $this->persistProcessedRow($stagedRow, $mappedData, $validationResult);

            return $this->rowCounts(['invalid' => 1]);
        }

        $this->persistProcessedRow($stagedRow, $mappedData, $validationResult);

        $result = $this->processValidRow($upload, $upload->vendor, $itemProcessor, $stagedRow, $mappedData);

        if ($result['processing_failed'] ?? false) {
            return $this->rowCounts(['failed' => 1]);
        }

        // A valid row can still be rejected when its SKU belongs to another catalog.
        // Treat that processor-level rejection as an invalid row.
        if ($result['cross_catalog_rejected'] ?? false) {
            return $this->rowCounts(['invalid' => 1]);
        }

        return $this->rowCounts([
            'created' => $result['created'],
            'updated' => $result['updated'],
            'unchanged' => $result['unchanged'],
            'success' => 1,
        ]);
    }

    private function mapRow(array $rowCells, $columnMappings): array
    {
        $mappedData = [];

        foreach ($rowCells as $colIndex => $rawValue) {
            $fieldKey = $this->columnToFieldKey->get($colIndex);

            if (! $fieldKey) {
                continue;
            }

            $field = $this->activeFields->get($fieldKey);
            $mapping = $this->columnMappingsByIndex->get($colIndex);

            if ($field && $field->is_multi_value) {
                $this->mapMultiValueField($mappedData, $fieldKey, $field, $mapping, $rawValue, $this->rangeFieldKeys);

                continue;
            }

            if ($this->isCategoryField($fieldKey)) {
                $mappedData['category'] = $this->resolveLookupId((string) $rawValue, $this->lookupMaps['category'] ?? []);

                continue;
            }

            if ($fieldKey === 'unit_of_measure') {
                $mappedData[$fieldKey] = $this->resolveLookupId(
                    (string) $rawValue,
                    $this->lookupMaps['unit_of_measure']['description'] ?? [],
                    $this->lookupMaps['unit_of_measure']['code'] ?? []
                );

                continue;
            }

            $mappedData[$fieldKey] = $this->sanitizeValue($rawValue);
        }

        $this->applyVitAwareTransforms($mappedData, $this->isVitFileImport, $this->weightUnit);

        return $mappedData;
    }

    private function applyVitAwareTransforms(array &$mappedData, bool $isVitFileImport, string $weightUnit): void
    {
        if (array_key_exists('item_weight_in_pounds', $mappedData) && ! $isVitFileImport && $weightUnit !== WeightUnitConverter::DEFAULT_UNIT && is_numeric($mappedData['item_weight_in_pounds'])) {
            $mappedData['item_weight_in_pounds'] = WeightUnitConverter::toPounds((float) $mappedData['item_weight_in_pounds'], $weightUnit);
        }

        if ($isVitFileImport && isset($mappedData['short_description']) && is_string($mappedData['short_description']) && $mappedData['short_description'] !== '') {
            $parsed = $this->parseAppendedQuantityPerUnit($mappedData['short_description']);

            if ($parsed !== null) {
                [$name, $quantity] = $parsed;

                if ($name !== '') {
                    $mappedData['short_description'] = $name;
                }

                if (! array_key_exists('quantity_per_unit', $mappedData) || $mappedData['quantity_per_unit'] === null || $mappedData['quantity_per_unit'] === '') {
                    $mappedData['quantity_per_unit'] = $quantity;
                }
            }
        }
    }

    private function parseAppendedQuantityPerUnit(string $value): ?array
    {
        if (! preg_match('/^(?<name>.+),\s*(?<qty>\d+(?:\.\d+)?)\s*(?<word>[^,\/]*)(?:\/(?<uom>[^,]*))?$/u', $value, $matches)) {
            return null;
        }

        return [trim($matches['name']), $matches['qty']];
    }

    private function isCategoryField(string $fieldKey): bool
    {
        return in_array($fieldKey, ['category', 'product_commodity_type'], true);
    }

    private function mapMultiValueField(array &$mappedData, string $fieldKey, object $field, ?object $mapping, mixed $rawValue, array $rangeFieldKeys): void
    {
        if (in_array($fieldKey, $rangeFieldKeys, true) && $mapping && ! empty($mapping->source_column_name)) {
            $this->mapRangeValue($mappedData, $fieldKey, $field, $mapping, $rawValue);

            return;
        }

        $vendorSeparator = $this->getVendorSourceSeparator($mapping);

        if ($field->is_key_value) {
            $mappedData[$fieldKey] = $this->parseMultiValueKeyValue((string) $rawValue, $vendorSeparator);

            return;
        }

        $mappedData[$fieldKey] = $this->splitMultiValue((string) $rawValue, $vendorSeparator);
    }

    private function mapRangeValue(array &$mappedData, string $fieldKey, object $field, object $mapping, mixed $rawValue): void
    {
        $value = $this->sanitizeValue($rawValue);
        $key = $this->sanitizeValue(trim($mapping->source_column_name));

        if ($value === null || $value === '' || $key === '') {
            return;
        }

        if ($field->is_key_value) {
            $mappedData[$fieldKey][] = ['key' => $key, 'value' => $value];

            return;
        }

        $mappedData[$fieldKey][] = $value;
    }

    private function validateMappedRow(CatalogUpload $upload, CatalogRowValidator $validator, array $mappedData): array
    {
        $result = $validator->validate($this->activeFields, $mappedData);

        $blockingErrors = $result['errors'] ?? [];
        $warnings = $result['warnings'] ?? [];
        $status = empty($blockingErrors) ? 'valid' : 'invalid';

        if ($status === 'valid' && $this->skuBelongsToAnotherCatalog($upload, $mappedData['dealer_sku'] ?? null)) {
            $blockingErrors[] = ['field_key' => 'dealer_sku', 'message' => CatalogItemProcessor::CROSS_CATALOG_SKU_ERROR];
            $status = 'invalid';
        }

        return ['status' => $status, 'errors' => $blockingErrors, 'warnings' => $warnings];
    }

    private function persistProcessedRow(CatalogUploadRow $stagedRow, array $mappedData, array $validationResult): void
    {
        $stagedRow->update([
            'data' => $mappedData,
            'status' => $validationResult['status'],
            'errors' => $this->buildValidationErrors($validationResult),
        ]);
    }

    private function processValidRow(CatalogUpload $upload, $vendor, CatalogItemProcessor $itemProcessor, CatalogUploadRow $catalogUploadRow, array $mappedData): array
    {
        $mappedDealerSku = $mappedData['dealer_sku'] ?? null;
        $skuKey = ($mappedDealerSku === null || $mappedDealerSku === '') ? null : trim((string) $mappedDealerSku);

        $existingItemProvenAbsent = $skuKey !== null && $this->skuProvenAbsent($skuKey);

        try {
            return DB::transaction(function () use ($upload, $vendor, $itemProcessor, $catalogUploadRow, $existingItemProvenAbsent) {
                return $itemProcessor->processRow(
                    $upload,
                    $vendor,
                    $catalogUploadRow,
                    $this->unspscClassificationType,
                    $this->unspscTypeResolved,
                    $existingItemProvenAbsent
                );
            });
        } catch (Throwable $e) {
            $dealerSku = $catalogUploadRow->data['dealer_sku'] ?? null;

            Log::error('ProcessValidatedRowsJob: row processing failed', [
                'upload_id' => $upload->id,
                'source_row_number' => $catalogUploadRow->row_number,
                'dealer_sku' => $dealerSku,
                'error' => $e->getMessage(),
            ]);

            // The item transaction rolled back (or failed before any mutation),
            // so no item change persists — but see the ambiguity note in
            // markRowFailed(): an exception thrown outside the processor's own
            // transaction boundary is still possible. Record the row as failed
            // so it reaches a terminal state either way.
            $this->markRowFailed($catalogUploadRow, $e);

            return [
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'processing_failed' => true,
            ];
        }
    }

    private function markRowFailed(CatalogUploadRow $stagedRow, Throwable $e): void
    {
        $stagedRow->update([
            'data' => array_merge($stagedRow->data ?? [], ['_processing_failed' => true]),
            'status' => CatalogUploadRow::STATUS_FAILED,
            'errors' => [
                'processing' => [
                    [
                        'field_key' => '_system',
                        'message' => mb_substr($e->getMessage() ?: get_class($e), 0, 2000),
                    ],
                ],
            ],
        ]);
    }

    private function buildValidationErrors(array $validationResult): ?array
    {
        $warnings = $validationResult['warnings'];
        $errors = $validationResult['errors'];

        if (empty($warnings) && empty($errors)) {
            return null;
        }

        return [
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    private function finalizeChunk(CatalogUpload $upload, array $counts): void
    {
        $columnAmounts = array_filter([
            'success_rows' => $counts['success'],
            'invalid_rows' => $counts['invalid'],
            'created_rows' => $counts['created'],
            'updated_rows' => $counts['updated'],
            'unchanged_rows' => $counts['unchanged'],
            'failed_rows' => $counts['failed'] ?? 0,
        ], fn ($amount) => $amount > 0);

        if (! empty($columnAmounts)) {
            $updates = [];

            foreach ($columnAmounts as $column => $amount) {
                $updates[$column] = DB::raw("$column + ".(int) $amount);
            }

            // One round-trip for every non-zero counter, instead of a
            // separate ->increment() query per counter.
            CatalogUpload::whereKey($upload->id)->update($updates);
        }

        $this->completeUploadIfDone($upload);
    }

    private function completeUploadIfDone(CatalogUpload $upload): void
    {
        $row = CatalogUpload::whereKey($upload->id)
            ->first(['id', 'total_rows', 'success_rows', 'invalid_rows', 'failed_rows', 'status']);

        if ($row === null) {
            return;
        }

        $processed = ($row->success_rows ?? 0) + ($row->invalid_rows ?? 0) + ($row->failed_rows ?? 0);

        if ($row->total_rows === null || $processed < $row->total_rows) {
            return; // Other child jobs still have rows to process.
        }

        // Atomic compare-and-set ensures only one child finalizes a concurrent upload.
        $completed = CatalogUpload::whereKey($upload->id)
            ->whereIn('status', [
                CatalogUploadStatus::Processing,
                CatalogUploadStatus::ProcessingItems,
            ])
            ->update([
                'status' => CatalogUploadStatus::Completed,
                'processing_completed_at' => now(),
            ]);

        if ($completed > 0) {
            $this->dispatchValidationReport($upload);
        }
    }

    private function dispatchValidationReport(CatalogUpload $upload): void
    {
        try {
            $fresh = CatalogUpload::with(['client'])->findOrFail($upload->id);

            (new CatalogValidationReportService)->sendValidationReportIfNeeded($fresh);
        } catch (Throwable $e) {
            Log::warning(
                'ProcessValidatedRowsJob: completion succeeded but validation '
                    .'report dispatch failed for upload '
                    .$upload->id
                    .': '
                    .$e->getMessage()
            );
        }
    }

    private function skuBelongsToAnotherCatalog(CatalogUpload $upload, $dealerSku): bool
    {
        if ($dealerSku === null || trim((string) $dealerSku) === '') {
            return false;
        }

        $sku = trim((string) $dealerSku);

        $preloadedItem = $this->preloadedExistingItem($sku);

        if ($preloadedItem !== null) {
            return (int) $preloadedItem->catalog_id !== (int) $upload->catalog_id;
        }

        /*
         * The preload only reports absence when it was asked about this SKU
         * and returned zero rows for the whole batch. That result is
         * collation-independent, because a matching row would have been
         * returned by the same comparison used below.
         */
        if ($this->skuProvenAbsent($sku)) {
            return false;
        }

        return CatalogItem::where('vendor_id', $upload->vendor_id)
            ->where('dealer_sku', $sku)
            ->where('catalog_id', '!=', $upload->catalog_id)
            ->exists();
    }

    private function buildColumnToFieldKey(CatalogUpload $upload): Collection
    {
        return $upload->columnMappings->mapWithKeys(fn ($m) => [$m->column_index => $m->field_key]);
    }

    private function buildRangeFieldKeys(CatalogUpload $upload): array
    {
        return $upload->columnMappings->groupBy('field_key')->filter(fn ($g) => $g->count() > 1)->keys()->all();
    }

    private function buildLookupMaps(): array
    {
        return [
            'category' => $this->buildLookupMap(CommodityType::query()->pluck('id', 'name')),
            'unit_of_measure' => [
                'description' => $this->buildLookupMap(UnitOfMeasure::query()->pluck('id', 'description')),
                'code' => $this->buildLookupMap(UnitOfMeasure::query()->pluck('id', 'code')),
            ],
        ];
    }

    private function buildLookupMap($idsByName): array
    {
        $map = [];

        foreach ($idsByName as $name => $id) {
            if ($name === null) {
                continue;
            }

            $normalized = $this->normalizeForLookup((string) $name);

            if ($normalized === '' || isset($map[$normalized])) {
                continue;
            }

            $map[$normalized] = $id;
        }

        return $map;
    }

    private function resolveLookupId(string $rawValue, array ...$maps): ?int
    {
        $normalized = $this->normalizeForLookup($rawValue);

        if ($normalized === '') {
            return null;
        }

        foreach ($maps as $map) {
            if (isset($map[$normalized])) {
                return $map[$normalized];
            }
        }

        return null;
    }

    private function normalizeForLookup(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * Split on $separator, trim/sanitize each part, drop empties. Shared by
     * splitMultiValue() and parseMultiValueKeyValue(), which only differ in
     * what they do with each already-cleaned part.
     */
    private function splitAndSanitize(string $rawValue, string $separator): array
    {
        if (trim($rawValue) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($v) => $this->sanitizeValue($v), explode($separator, $rawValue)),
            fn ($v) => $v !== ''
        ));
    }

    private function parseMultiValueKeyValue(string $rawValue, string $separator): array
    {
        $result = [];

        foreach ($this->splitAndSanitize($rawValue, $separator) as $part) {
            $pos = strpos($part, '=');

            if ($pos === false) {
                continue;
            }

            $key = $this->sanitizeValue(trim(substr($part, 0, $pos)));
            $value = $this->sanitizeValue(trim(substr($part, $pos + 1)));

            if ($key === '' || $value === '') {
                continue;
            }

            $result[] = ['key' => $key, 'value' => $value];
        }

        return $result;
    }

    private function splitMultiValue(string $rawValue, string $separator): array
    {
        return $this->splitAndSanitize($rawValue, $separator);
    }

    private function sanitizeValue(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    private function getVendorSourceSeparator(?object $mapping): string
    {
        if ($mapping && ! empty($mapping->source_separator)) {
            return $mapping->source_separator;
        }

        return ',';
    }
}
