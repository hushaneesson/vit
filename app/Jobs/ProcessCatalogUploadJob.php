<?php

namespace App\Jobs;

use App\Enums\CatalogUploadStatus;
use App\Models\CatalogItem;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
use App\Models\CommodityType;
use App\Models\UnitOfMeasure;
use App\Services\Catalog\CatalogItemProcessor;
use App\Services\Catalog\CatalogRowValidator;
use App\Services\Catalog\CatalogValidationReportService;
use App\Services\VitFieldDefinition;
use App\Services\WeightUnitConverter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as OpenSpoutCsvReader;
use OpenSpout\Reader\XLSX\Reader as OpenSpoutXlsxReader;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Throwable;

class ProcessCatalogUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // 30-minute ceiling for very large vendor files.
    public int $timeout = 1800;

    /** Run on the dedicated imports queue so long uploads never block exports/notifications. */
    private const READ_CHUNK_SIZE = 500;

    public function __construct(
        public int $catalogUploadId,
        public bool $isVitFileImport = false
    ) {
        $this->onQueue('imports');
    }

    // Job lifecycle

    public function handle(
        CatalogRowValidator $validator,
        CatalogItemProcessor $itemProcessor,
        CatalogValidationReportService $reportService
    ): void {
        $upload = $this->loadUpload();

        $this->markUploadAsProcessing($upload);

        /*
         * Remove staging rows left behind by a previous failed processing
         * attempt so this pass starts clean. The unique constraint on
         * (catalog_upload_id, row_number) would otherwise abort reprocessing
         * with a duplicate-key error.
         */
        CatalogUploadRow::where('catalog_upload_id', $upload->id)->delete();

        $temporaryPath = null;

        try {
            $context = $this->buildProcessingContext($upload);
            $temporaryPath = $context['temporary_path'];

            $counts = $this->processUpload(
                $upload,
                $validator,
                $itemProcessor,
                $reportService,
                $context
            );

            $this->finalizeUpload(
                $upload,
                $reportService,
                $counts
            );
        } catch (Throwable $e) {
            $this->handleFailure($upload, $e);
            throw $e;
        } finally {
            $this->cleanupTemporaryFile($temporaryPath);
        }
    }

    private function loadUpload(): CatalogUpload
    {
        return CatalogUpload::with(['vendor', 'columnMappings'])
            ->findOrFail($this->catalogUploadId);
    }

    private function markUploadAsProcessing(CatalogUpload $upload): void
    {
        $upload->update([
            'status' => CatalogUploadStatus::Processing,
            'processing_started_at' => now(),
        ]);
    }

    // Upload setup

    private function buildProcessingContext(CatalogUpload $upload): array
    {
        $columnToFieldKey = $this->buildColumnToFieldKey($upload);

        $activeFields = VitFieldDefinition::active()
            ->keyBy('field_key');

        $rangeFieldKeys = $this->buildRangeFieldKeys($upload);
        $lookupMaps = $this->buildLookupMaps();

        $pathResult = $this->resolveLocalPath(
            $upload->disk,
            $upload->file_path
        );

        $localPath = $pathResult['path'];

        $reader = null;
        $allowedColumns = [];
        $totalRows = PHP_INT_MAX;

        // Reader selection by format:
        //   xlsx, csv → OpenSpout streaming (no PhpSpreadsheet reader needed)
        //   xls       → PhpSpreadsheet (binary BIFF — OpenSpout has no reader)
        if ($upload->file_type === 'xls') {
            $reader = $this->createReader($upload->file_type);
            $this->configureExcelReader(
                $reader,
                $localPath,
                $upload,
                $allowedColumns
            );
            $totalRows = $this->determineTotalRows($reader, $localPath);
        }

        // Mapper configuration determines the source weight unit.
        $weightUnit = WeightUnitConverter::DEFAULT_UNIT;

        $weightMapping = $upload->columnMappings
            ->firstWhere('field_key', 'item_weight_in_pounds');

        if (
            $weightMapping
            && !empty($weightMapping->source_separator)
        ) {
            $weightUnit = (string) $weightMapping->source_separator;
        }
        return [
            'columnToFieldKey' => $columnToFieldKey,
            'activeFields' => $activeFields,
            'rangeFieldKeys' => $rangeFieldKeys,
            'lookupMaps' => $lookupMaps,
            'localPath' => $localPath,
            'temporary_path' => $pathResult['temporary_path'],
            'reader' => $reader,
            'allowedColumns' => $allowedColumns,
            'totalRows' => $totalRows,
            'is_vit_export' => $this->isVitFileImport,
            'weight_unit' => $weightUnit,
        ];
    }

    private function buildColumnToFieldKey(CatalogUpload $upload)
    {
        return $upload->columnMappings
            ->mapWithKeys(fn($mapping) => [
                $mapping->column_index => $mapping->field_key,
            ]);
    }

    private function buildRangeFieldKeys(CatalogUpload $upload): array
    {
        return $upload->columnMappings
            ->groupBy('field_key')
            ->filter(fn($group) => $group->count() > 1)
            ->keys()
            ->all();
    }

    private function buildLookupMaps(): array
    {
        return [
            'category' => $this->buildLookupMap(
                CommodityType::query()->pluck('id', 'name')
            ),

            'unit_of_measure' => [
                'description' => $this->buildLookupMap(
                    UnitOfMeasure::query()->pluck('id', 'description')
                ),

                'code' => $this->buildLookupMap(
                    UnitOfMeasure::query()->pluck('id', 'code')
                ),
            ],
        ];
    }

    /**
     * Instantiate the PhpSpreadsheet reader for .xls (binary BIFF).
     *
     * CSV and XLSX are handled by OpenSpout (see processOpenSpoutStreaming).
     * OpenSpout has no .xls reader, so PhpSpreadsheet is required here.
     *
     * @param string $fileType 'xls'
     * @return object
     */
    private function createReader(string $fileType): object
    {
        $reader = IOFactory::createReader('Xls');
        $reader->setReadDataOnly(true);

        return $reader;
    }

    private function configureExcelReader(
        object $reader,
        string $localPath,
        CatalogUpload $upload,
        array &$allowedColumns
    ): void {
        $sheetNames = $reader->listWorksheetNames($localPath);

        if (!empty($sheetNames)) {
            $reader->setLoadSheetsOnly($sheetNames[0]);
        }

        $requiredColumns = $this->requiredSourceColumnIndexes($upload);

        if (!empty($requiredColumns)) {
            $allowedColumns = array_flip($requiredColumns);

            $reader->setReadFilter(
                $this->createReadFilter($allowedColumns)
            );
        }
    }

    private function determineTotalRows(
        object $reader,
        string $localPath
    ): int {
        $worksheetInfo = $reader->listWorksheetInfo($localPath);
        $totalRows = $worksheetInfo[0]['totalRows'] ?? 0;

        return $totalRows > 0
            ? $totalRows
            : PHP_INT_MAX;
    }

    // File processing

    private function processUpload(
        CatalogUpload $upload,
        CatalogRowValidator $validator,
        CatalogItemProcessor $itemProcessor,
        CatalogValidationReportService $reportService,
        array $context
    ): array {
        // Reader selection by format:
        //   xlsx, csv → OpenSpout streaming (unified pipeline)
        //   xls       → PhpSpreadsheet (binary BIFF)
        if (in_array($upload->file_type, ['xlsx', 'csv'], true)) {
            return $this->processOpenSpoutStreaming(
                $upload,
                $validator,
                $itemProcessor,
                $context,
                $upload->file_type
            );
        }

        return $this->processExcelFile(
            $upload,
            $validator,
            $itemProcessor,
            $context
        );
    }

    private function processExcelFile(
        CatalogUpload $upload,
        CatalogRowValidator $validator,
        CatalogItemProcessor $itemProcessor,
        array $context
    ): array {
        $createdCount = 0;
        $updatedCount = 0;
        $unchangedCount = 0;
        $invalidCount = 0;
        $totalRows = $context['totalRows'];

        $vendor = $upload->vendor;

        // Load the workbook once; the reader already limits it to mapped columns.
        $spreadsheet = $context['reader']->load(
            $context['localPath']
        );

        try {
            $sheet = $spreadsheet->getSheet(0);

            // Real, authoritative bound on how far this sheet's data goes.
            // $totalRows (from worksheet info) is normally the same value,
            // but falls back to PHP_INT_MAX when PhpSpreadsheet can't
            // determine it (e.g. certain malformed/legacy .xls files) —
            // without this, the chunk loop below would run until the job
            // timeout instead of stopping at the real end of the data.
            $highestRow = $sheet->getHighestDataRow();

            $chunkStartRow = 2;

            while (
                $chunkStartRow <= $totalRows
                && $chunkStartRow <= $highestRow
            ) {
                $chunkEndRow = min(
                    $chunkStartRow + self::READ_CHUNK_SIZE - 1,
                    $totalRows,
                    $highestRow
                );

                $chunkResult = $this->processExcelRows(
                    $upload,
                    $validator,
                    $itemProcessor,
                    $context,
                    $sheet,
                    $chunkStartRow,
                    $chunkEndRow,
                    $vendor
                );

                $createdCount += $chunkResult['created'];
                $updatedCount += $chunkResult['updated'];
                $unchangedCount += $chunkResult['unchanged'];
                $invalidCount += $chunkResult['invalid'];
                $chunkStartRow = $chunkEndRow + 1;
            }
        } finally {
            $spreadsheet->disconnectWorksheets();

            unset(
                $sheet,
                $spreadsheet
            );

            gc_collect_cycles();
        }

        return [
            'created' => $createdCount,
            'updated' => $updatedCount,
            'unchanged' => $unchangedCount,
            'invalid' => $invalidCount,
        ];
    }

    /**
     * Unified OpenSpout streaming processor for XLSX and CSV.
     *
     * Both formats flow through the same reader pipeline. The reader is
     * selected by file type via makeStreamingReader().
     *
     * @param string $fileType 'xlsx' | 'csv'
     */
    private function processOpenSpoutStreaming(
        CatalogUpload $upload,
        CatalogRowValidator $validator,
        CatalogItemProcessor $itemProcessor,
        array $context,
        string $fileType
    ): array {
        $createdCount = 0;
        $updatedCount = 0;
        $unchangedCount = 0;
        $invalidCount = 0;
        $batch = [];
        $sourceRowNumber = 0;
        $lastLogicalBatchBoundary = 0;

        $vendor = $upload->vendor;

        $reader = $this->makeStreamingReader($fileType);

        try {
            $reader->open($context['localPath']);

            $sheetIterator = $reader->getSheetIterator();
            $sheetIterator->rewind();
            $sheet = $sheetIterator->current();

            if ($sheet === null) {
                throw new \RuntimeException(
                    "File has no worksheets: {$upload->original_filename}"
                );
            }

            $rowIterator = $sheet->getRowIterator();

            foreach ($rowIterator as $row) {
                $sourceRowNumber++;

                if ($sourceRowNumber === 1) {
                    continue;
                }

                $rowCells = $this->extractOpenSpoutRow($row);

                if (empty($rowCells) || $this->rowIsBlank($rowCells)) {
                    continue;
                }

                $rowResult = $this->processMappedRow(
                    $upload,
                    $validator,
                    $itemProcessor,
                    $context,
                    $vendor,
                    $rowCells,
                    $sourceRowNumber
                );

                $createdCount += $rowResult['created'];
                $updatedCount += $rowResult['updated'];
                $unchangedCount += $rowResult['unchanged'];
                $invalidCount += $rowResult['invalid'];

                if ($rowResult['invalid_payload'] !== null) {
                    $batch[] = $rowResult['invalid_payload'];

                    if ($sourceRowNumber - $lastLogicalBatchBoundary >= self::READ_CHUNK_SIZE) {
                        $this->insertInvalidRows($batch);
                        $batch = [];
                        $lastLogicalBatchBoundary = $sourceRowNumber;

                        Log::info('ProcessCatalogUploadJob: logical batch boundary reached', [
                            'upload_id' => $upload->id,
                            'rows_processed' => $sourceRowNumber,
                            'invalid_rows_pending_insert' => count($batch),
                        ]);
                    }
                }
            }

            $this->insertInvalidRows($batch);
        } finally {
            $reader->close();
        }

        return [
            'created' => $createdCount,
            'updated' => $updatedCount,
            'unchanged' => $unchangedCount,
            'invalid' => $invalidCount,
        ];
    }

    /**
     * Instantiate the correct OpenSpout reader for the streaming pipeline.
     *
     * @param string $fileType 'xlsx' | 'csv'
     */
    private function makeStreamingReader(string $fileType): \OpenSpout\Reader\ReaderInterface
    {
        return match ($fileType) {
            'csv' => (function () {
                $options = new CsvOptions();
                $options->FIELD_DELIMITER = ',';
                $options->FIELD_ENCLOSURE = '"';
                $options->ENCODING = 'UTF-8';

                return new OpenSpoutCsvReader($options);
            })(),
            'xlsx' => new OpenSpoutXlsxReader(),
            default => throw new \InvalidArgumentException(
                "Unsupported file type for streaming: {$fileType}"
            ),
        };
    }

    /**
     * Map, validate, and (if valid) persist + process a single source row.
     *
     * Shared by both the OpenSpout streaming path (.xlsx/.csv) and the
     * PhpSpreadsheet path (.xls) so the created/updated/unchanged/invalid
     * decision logic lives in exactly one place.
     *
     * @return array{created: int, updated: int, unchanged: int, invalid: int, invalid_payload: ?array}
     */
    private function processMappedRow(
        CatalogUpload $upload,
        CatalogRowValidator $validator,
        CatalogItemProcessor $itemProcessor,
        array $context,
        $vendor,
        array $rowCells,
        int $sourceRowNumber
    ): array {
        [$mappedData, $rawData] = $this->mapRow(
            $rowCells,
            $context['columnToFieldKey'],
            $context['activeFields'],
            $upload->columnMappings,
            $context['rangeFieldKeys'],
            $context['lookupMaps'],
            $context['is_vit_export'],
            $context['weight_unit']
        );

        $result = $this->validateMappedRow(
            $upload,
            $validator,
            $context['activeFields'],
            $mappedData,
            $sourceRowNumber
        );

        if ($result['status'] !== 'valid') {
            return [
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'invalid' => 1,
                'invalid_payload' => $this->buildRowPayload(
                    $upload,
                    $sourceRowNumber,
                    $mappedData,
                    $rawData,
                    $result
                ),
            ];
        }

        $catalogUploadRow = $this->persistValidRow(
            $upload,
            $sourceRowNumber,
            $mappedData,
            $rawData,
            $result
        );

        $itemResult = $this->processValidRow(
            $upload,
            $vendor,
            $itemProcessor,
            $catalogUploadRow
        );

        if (!empty($itemResult['cross_catalog_rejected'])) {
            return [
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'invalid' => 1,
                'invalid_payload' => null,
            ];
        }

        return [
            'created' => $itemResult['created'],
            'updated' => $itemResult['updated'],
            'unchanged' => $itemResult['unchanged'],
            'invalid' => 0,
            'invalid_payload' => null,
        ];
    }

    /**
     * Persist a validated source row as a CatalogUploadRow model instance so it
     * can be passed to CatalogItemProcessor::processRow(), which requires a
     * persisted model (it may update the row for cross-catalog SKU rejections).
     */
    private function persistValidRow(
        CatalogUpload $upload,
        int $sourceRowNumber,
        array $mappedData,
        array $rawData,
        array $validationResult
    ): CatalogUploadRow {
        $payload = $this->buildRowPayload(
            $upload,
            $sourceRowNumber,
            $mappedData,
            $rawData,
            $validationResult
        );

        // Eloquent manages created_at/updated_at automatically on save().
        unset($payload['created_at'], $payload['updated_at']);

        $catalogUploadRow = new CatalogUploadRow();
        $catalogUploadRow->fill($payload);
        $catalogUploadRow->save();

        return $catalogUploadRow;
    }

    /**
     * Process one valid row into catalog_items inside its own independent
     * transaction. A failure rolls back only this row's catalog-item work; the
     * upload continues with the next source row.
     *
     * @return array{created: int, updated: int, unchanged: int, cross_catalog_rejected?: bool}
     */
    private function processValidRow(
        CatalogUpload $upload,
        $vendor,
        CatalogItemProcessor $itemProcessor,
        CatalogUploadRow $catalogUploadRow
    ): array {
        $dealerSku = $catalogUploadRow->data['dealer_sku'] ?? null;

        try {
            Log::info('ProcessCatalogUploadJob: catalog item transaction started', [
                'upload_id' => $upload->id,
                'source_row_number' => $catalogUploadRow->row_number,
                'dealer_sku' => $dealerSku,
            ]);

            $result = DB::transaction(function () use ($upload, $vendor, $itemProcessor, $catalogUploadRow) {
                return $itemProcessor->processRow(
                    $upload,
                    $vendor,
                    $catalogUploadRow
                );
            });

            Log::info('ProcessCatalogUploadJob: catalog item transaction committed', [
                'upload_id' => $upload->id,
                'source_row_number' => $catalogUploadRow->row_number,
                'dealer_sku' => $dealerSku,
            ]);

            return $result;
        } catch (Throwable $e) {
            Log::error('ProcessCatalogUploadJob: catalog item transaction failed', [
                'upload_id' => $upload->id,
                'source_row_number' => $catalogUploadRow->row_number,
                'catalog_upload_row_id' => $catalogUploadRow->id,
                'dealer_sku' => $dealerSku,
                'error' => $e->getMessage(),
            ]);

            return [
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
            ];
        }
    }

    private function extractOpenSpoutRow(
        $row
    ): array {
        $rowCells = [];

        foreach ($row->getCells() as $colIndex => $cell) {
            $value = $cell->getValue();

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }

            $rowCells[$colIndex] = $value;
        }

        return $rowCells;
    }

    private function processExcelRows(
        CatalogUpload $upload,
        CatalogRowValidator $validator,
        CatalogItemProcessor $itemProcessor,
        array $context,
        object $sheet,
        int $chunkStartRow,
        int $chunkEndRow,
        $vendor
    ): array {
        $highestRow = $sheet->getHighestDataRow();
        $effectiveStartRow = max(2, $chunkStartRow);
        $effectiveEndRow = min($highestRow, $chunkEndRow);

        $createdCount = 0;
        $updatedCount = 0;
        $unchangedCount = 0;
        $invalidCount = 0;
        $batch = [];

        for (
            $rowIndex = $effectiveStartRow;
            $rowIndex <= $effectiveEndRow;
            $rowIndex++
        ) {
            $rowCells = $this->extractExcelRow($sheet, $rowIndex);

            if (empty($rowCells) || $this->rowIsBlank($rowCells)) {
                continue;
            }

            $rowResult = $this->processMappedRow(
                $upload,
                $validator,
                $itemProcessor,
                $context,
                $vendor,
                $rowCells,
                $rowIndex
            );

            $createdCount += $rowResult['created'];
            $updatedCount += $rowResult['updated'];
            $unchangedCount += $rowResult['unchanged'];
            $invalidCount += $rowResult['invalid'];

            if ($rowResult['invalid_payload'] !== null) {
                $batch[] = $rowResult['invalid_payload'];
            }
        }

        $this->insertInvalidRows($batch);

        return [
            'created' => $createdCount,
            'updated' => $updatedCount,
            'unchanged' => $unchangedCount,
            'invalid' => $invalidCount,
        ];
    }

    private function extractExcelRow(
        object $sheet,
        int $rowIndex
    ): array {
        $rowCells = [];

        $row = $sheet
            ->getRowIterator($rowIndex, $rowIndex)
            ->current();

        if ($row === null) {
            return [];
        }

        foreach (
            $row->getCellIterator('A', null, true) as $cell
        ) {
            $colIndex =
                Coordinate::columnIndexFromString(
                    $cell->getColumn()
                ) - 1;

            $rowCells[$colIndex] = $cell->getValue();
        }

        return $rowCells;
    }

    // Validation

    private function validateMappedRow(
        CatalogUpload $upload,
        CatalogRowValidator $validator,
        $activeFields,
        array $mappedData,
        int $sourceRowNumber
    ): array {
        $result = $validator->validate(
            $activeFields,
            $mappedData
        );

        $blockingErrors = $result['errors'] ?? [];
        $warnings = $result['warnings'] ?? [];

        $status = empty($blockingErrors)
            ? 'valid'
            : 'invalid';

        // A SKU cannot move between catalogs.
        if (
            $status === 'valid'
            && $this->skuBelongsToAnotherCatalog(
                $upload,
                $mappedData['dealer_sku'] ?? null
            )
        ) {
            $blockingErrors[] = [
                'field_key' => 'dealer_sku',
                'message' =>
                CatalogItemProcessor::CROSS_CATALOG_SKU_ERROR,
            ];

            $status = 'invalid';
        }

        if ($status === 'invalid') {
            Log::info(
                'ProcessCatalogUploadJob: validation failed for row',
                [
                    'upload_id' => $upload->id,
                    'row_number' => $sourceRowNumber - 1,
                    'blocking_errors' => $blockingErrors,
                    'warnings' => $warnings,
                ]
            );
        }

        return [
            'status' => $status,
            'errors' => $blockingErrors,
            'warnings' => $warnings,
        ];
    }

    private function skuBelongsToAnotherCatalog(
        CatalogUpload $upload,
        $dealerSku
    ): bool {
        if ($dealerSku === null || trim((string) $dealerSku) === '') {
            return false;
        }

        return CatalogItem::where('vendor_id', $upload->vendor_id)
            ->where('dealer_sku', trim((string) $dealerSku))
            ->where('catalog_id', '!=', $upload->catalog_id)
            ->exists();
    }

    private function buildRowPayload(
        CatalogUpload $upload,
        int $sourceRowNumber,
        array $mappedData,
        array $rawData,
        array $validationResult
    ): array {
        $warnings = $validationResult['warnings'];
        $errors = $validationResult['errors'];

        $rowPayload = [
            'warnings' => $warnings,
            'errors' => $errors,
        ];

        $validationErrors =
            empty($warnings) && empty($errors)
            ? null
            : json_encode($rowPayload);

        return [
            'catalog_upload_id' => $upload->id,
            'row_number' => $sourceRowNumber - 1,
            'data' => json_encode($mappedData),
            'raw_data' => json_encode($rawData),
            'status' => $validationResult['status'],
            'errors' => $validationErrors,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function requiredSourceColumnIndexes(
        CatalogUpload $upload
    ): array {
        return $upload->columnMappings
            ->pluck('column_index')
            ->filter(fn($index) => $index !== null)
            ->map(fn($index) => (int) $index)
            ->unique()
            ->values()
            ->all();
    }

    private function createReadFilter(
        array $allowedColumns,
        ?int $minRow = null,
        ?int $maxRow = null
    ): IReadFilter {
        return new class(
            $allowedColumns,
            $minRow,
            $maxRow
        ) implements IReadFilter {
            public function __construct(
                private array $allowedColumns,
                private ?int $minRow = null,
                private ?int $maxRow = null
            ) {}

            public function readCell(
                string $columnAddress,
                int $row,
                string $worksheetName = ''
            ): bool {
                if ($row < 1) {
                    return false;
                }

                if (
                    $this->minRow !== null
                    && $row < $this->minRow
                ) {
                    return false;
                }

                if (
                    $this->maxRow !== null
                    && $row > $this->maxRow
                ) {
                    return false;
                }

                $columnIndex =
                    Coordinate::columnIndexFromString(
                        $columnAddress
                    ) - 1;

                return isset($this->allowedColumns[$columnIndex]);
            }
        };
    }

    // Row mapping

    private function rowIsBlank(array $values): bool
    {
        foreach ($values as $value) {
            if (
                !is_null($value)
                && trim((string) $value) !== ''
            ) {
                return false;
            }
        }

        return true;
    }

    private function mapRow(
        array $rowCells,
        $columnToFieldKey,
        $activeFields,
        $columnMappings,
        array $rangeFieldKeys,
        array $lookupMaps = [],
        bool $isVitFileImport = false,
        string $weightUnit = WeightUnitConverter::DEFAULT_UNIT
    ): array {
        $mappedData = [];
        $rawData = [];

        foreach ($rowCells as $colIndex => $rawValue) {
            $fieldKey = $columnToFieldKey->get($colIndex);

            $rawData["col_{$colIndex}"] = $rawValue;

            if (!$fieldKey) {
                continue;
            }

            $field = $activeFields->get($fieldKey);

            $mapping = $columnMappings->firstWhere(
                'column_index',
                $colIndex
            );

            if ($field && $field->is_multi_value) {
                $this->mapMultiValueField(
                    $mappedData,
                    $fieldKey,
                    $field,
                    $mapping,
                    $rawValue,
                    $rangeFieldKeys
                );

                continue;
            }

            if ($this->isCategoryField($fieldKey)) {
                $mappedData['category'] =
                    $this->resolveLookupId(
                        (string) $rawValue,
                        $lookupMaps['category'] ?? []
                    );

                continue;
            }

            if ($fieldKey === 'unit_of_measure') {
                $mappedData[$fieldKey] =
                    $this->resolveLookupId(
                        (string) $rawValue,
                        $lookupMaps['unit_of_measure']['description'] ?? [],
                        $lookupMaps['unit_of_measure']['code'] ?? []
                    );

                continue;
            }

            $mappedData[$fieldKey] =
                $this->normalizeScalar($rawValue);
        }

        $this->applyVitAwareTransforms(
            $mappedData,
            $isVitFileImport,
            $weightUnit
        );

        return [
            $mappedData,
            $rawData,
        ];
    }

    // VIT transformations

    private function applyVitAwareTransforms(
        array &$mappedData,
        bool $isVitFileImport,
        string $weightUnit
    ): void {
        if (
            array_key_exists('item_weight_in_pounds', $mappedData)
            && !$isVitFileImport
            && $weightUnit !== WeightUnitConverter::DEFAULT_UNIT
            && is_numeric($mappedData['item_weight_in_pounds'])
        ) {
            $mappedData['item_weight_in_pounds'] =
                WeightUnitConverter::toPounds(
                    (float) $mappedData['item_weight_in_pounds'],
                    $weightUnit
                );
        }

        if (
            $isVitFileImport
            && isset($mappedData['short_description'])
            && is_string($mappedData['short_description'])
            && $mappedData['short_description'] !== ''
        ) {
            $parsed = $this->parseAppendedQuantityPerUnit(
                $mappedData['short_description']
            );

            if ($parsed !== null) {
                [$name, $quantity] = $parsed;

                if ($name !== '') {
                    $mappedData['short_description'] = $name;
                }

                if (
                    !array_key_exists('quantity_per_unit', $mappedData)
                    || $mappedData['quantity_per_unit'] === null
                    || $mappedData['quantity_per_unit'] === ''
                ) {
                    $mappedData['quantity_per_unit'] = $quantity;
                }
            }
        }
    }

    private function parseAppendedQuantityPerUnit(
        string $value
    ): ?array {
        if (
            !preg_match(
                '/^(?<name>.+),\s*(?<qty>\d+(?:\.\d+)?)\s*(?<word>[^,\/]*)(?:\/(?<uom>[^,]*))?$/u',
                $value,
                $matches
            )
        ) {
            return null;
        }

        return [
            trim($matches['name']),
            $matches['qty'],
        ];
    }

    private function isCategoryField(string $fieldKey): bool
    {
        return in_array(
            $fieldKey,
            [
                'category',
                'product_commodity_type',
            ],
            true
        );
    }

    private function mapMultiValueField(
        array &$mappedData,
        string $fieldKey,
        object $field,
        ?object $mapping,
        mixed $rawValue,
        array $rangeFieldKeys
    ): void {
        if (
            in_array($fieldKey, $rangeFieldKeys, true)
            && $mapping
            && !empty($mapping->source_column_name)
        ) {
            $this->mapRangeValue(
                $mappedData,
                $fieldKey,
                $field,
                $mapping,
                $rawValue
            );

            return;
        }

        $vendorSeparator =
            $this->getVendorSourceSeparator(
                $mapping
            );

        if ($field->is_key_value) {
            $mappedData[$fieldKey] =
                $this->parseMultiValueKeyValue(
                    (string) $rawValue,
                    $vendorSeparator
                );

            return;
        }

        $mappedData[$fieldKey] =
            $this->splitMultiValue(
                (string) $rawValue,
                $vendorSeparator
            );
    }

    private function mapRangeValue(
        array &$mappedData,
        string $fieldKey,
        object $field,
        object $mapping,
        mixed $rawValue
    ): void {
        $value = $this->sanitizeValue($rawValue);
        $key = $this->sanitizeValue(
            trim($mapping->source_column_name)
        );

        if (
            $value === null
            || $value === ''
            || $key === ''
        ) {
            return;
        }

        if ($field->is_key_value) {
            $mappedData[$fieldKey][] = [
                'key' => $key,
                'value' => $value,
            ];

            return;
        }

        $mappedData[$fieldKey][] = $value;
    }

    // Lookup helpers

    private function buildLookupMap($idsByName): array
    {
        $map = [];

        foreach ($idsByName as $name => $id) {
            if ($name === null) {
                continue;
            }

            $normalized = $this->normalizeForLookup((string) $name);

            if (
                $normalized === ''
                || isset($map[$normalized])
            ) {
                continue;
            }

            $map[$normalized] = $id;
        }

        return $map;
    }

    private function resolveLookupId(
        string $rawValue,
        array ...$maps
    ): ?int {
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
     * Persist invalid-row tracking payloads. This is a single bulk INSERT
     * statement for staging rows only — it is not a processing transaction
     * and has no bearing on catalog-item transaction boundaries.
     */
    private function insertInvalidRows(array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        CatalogUploadRow::insert($batch);
    }

    private function normalizeScalar(mixed $value): mixed
    {
        return is_string($value)
            ? $this->sanitizeValue($value)
            : $value;
    }

    private function parseMultiValueKeyValue(
        string $rawValue,
        string $separator
    ): array {
        if (trim($rawValue) === '') {
            return [];
        }

        $result = [];
        $parts = explode($separator, $rawValue);

        foreach ($parts as $part) {
            $trimmed = trim($part);

            if ($trimmed === '') {
                continue;
            }

            $equalsPos = strpos($trimmed, '=');

            if ($equalsPos === false) {
                continue;
            }

            $key = $this->sanitizeValue(
                trim(
                    substr(
                        $trimmed,
                        0,
                        $equalsPos
                    )
                )
            );

            $value = $this->sanitizeValue(
                trim(
                    substr(
                        $trimmed,
                        $equalsPos + 1
                    )
                )
            );

            if ($key === '' || $value === '') {
                continue;
            }

            $result[] = [
                'key' => $key,
                'value' => $value,
            ];
        }

        return $result;
    }

    private function splitMultiValue(
        string $rawValue,
        string $separator
    ): array {
        if (trim($rawValue) === '') {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    fn($value) => $this->sanitizeValue($value),
                    explode($separator, $rawValue)
                ),
                fn($value) => $value !== ''
            )
        );
    }

    private function sanitizeValue(mixed $value): mixed
    {
        return is_string($value)
            ? trim($value)
            : $value;
    }

    private function getVendorSourceSeparator(
        ?object $mapping
    ): string {
        if (
            $mapping
            && !empty($mapping->source_separator)
        ) {
            return $mapping->source_separator;
        }

        return ',';
    }

    // File helpers

    private function resolveLocalPath(
        string $disk,
        string $path
    ): array {
        $diskConfig = config(
            'filesystems.disks.' . $disk,
            []
        );

        if (($diskConfig['driver'] ?? null) === 'local') {
            return [
                'path' => Storage::disk($disk)->path($path),
                'temporary_path' => null,
            ];
        }

        $tempPath = tempnam(
            sys_get_temp_dir(),
            'catalog_process_'
        );

        if ($tempPath === false) {
            throw new \RuntimeException(
                'Unable to create temporary file for catalog processing.'
            );
        }

        $sourceStream = Storage::disk($disk)->readStream($path);

        if ($sourceStream === false) {
            @unlink($tempPath);

            throw new \RuntimeException(
                'Unable to open uploaded catalog file for reading.'
            );
        }

        $destinationStream = fopen($tempPath, 'wb');

        if ($destinationStream === false) {
            fclose($sourceStream);
            @unlink($tempPath);

            throw new \RuntimeException(
                'Unable to open temporary catalog file for writing.'
            );
        }

        try {
            $bytesCopied = stream_copy_to_stream(
                $sourceStream,
                $destinationStream
            );

            if ($bytesCopied === false) {
                throw new \RuntimeException(
                    'Unable to copy uploaded catalog file to temporary storage.'
                );
            }
        } finally {
            fclose($sourceStream);
            fclose($destinationStream);
        }

        return [
            'path' => $tempPath,
            'temporary_path' => $tempPath,
        ];
    }

    private function cleanupTemporaryFile(
        ?string $temporaryPath
    ): void {
        if (
            $temporaryPath !== null
            && is_file($temporaryPath)
        ) {
            @unlink($temporaryPath);
        }
    }

    // Completion and cleanup

    /**
     * Finalize the upload after the entire source file has been streamed and
     * every valid row has had its individual catalog-item transaction attempted.
     *
     * @param array{created: int, updated: int, unchanged: int, invalid: int} $counts
     */
    private function finalizeUpload(
        CatalogUpload $upload,
        CatalogValidationReportService $reportService,
        array $counts
    ): void {
        $successCount = $counts['created']
            + $counts['updated']
            + $counts['unchanged'];

        $upload->update([
            'total_rows' => $successCount + $counts['invalid'],
            'success_rows' => $successCount,
            'created_rows' => $counts['created'],
            'updated_rows' => $counts['updated'],
            'unchanged_rows' => $counts['unchanged'],
            'invalid_rows' => $counts['invalid'],
        ]);

        // Send the validation report exactly once, after the entire file.
        $reportService->sendValidationReportIfNeeded($upload);

        $upload->update([
            'status' => CatalogUploadStatus::Completed,
            'processing_completed_at' => now(),
        ]);
    }

    private function handleFailure(
        CatalogUpload $upload,
        Throwable $e
    ): void {
        Log::error(
            'ProcessCatalogUploadJob: upload failed',
            [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]
        );

        $upload->update([
            'status' => CatalogUploadStatus::Failed,
            'failure_reason' => Str::limit(
                $e->getMessage(),
                5000
            ),
            'processing_completed_at' => now(),
        ]);
    }
}
