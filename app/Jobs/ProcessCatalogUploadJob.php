<?php

namespace App\Jobs;

use App\Enums\CatalogUploadStatus;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
use App\Models\CommodityType;
use App\Models\UnitOfMeasure;
use App\Services\CatalogRowValidator;
use App\Services\VitFieldDefinition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Throwable;

class ProcessCatalogUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * 30 minute ceiling for very large vendor files.
     */
    public int $timeout = 1800;

    private const BATCH_SIZE = 200;

    private const READ_CHUNK_SIZE = 500;

    public function __construct(public int $catalogUploadId) {}

    public function handle(CatalogRowValidator $validator): void
    {
        $upload = $this->loadUpload();

        $this->markUploadAsProcessing($upload);

        $temporaryPath = null;

        try {
            $context = $this->buildProcessingContext($upload);

            $temporaryPath = $context['temporary_path'];

            [$successCount, $errorCount] = $this->processUpload(
                $upload,
                $validator,
                $context
            );

            $this->finalizeUpload(
                $upload,
                $successCount,
                $errorCount
            );
        } catch (Throwable $e) {
            $this->handleFailure($upload, $e);

            throw $e;
        } finally {
            $this->cleanupTemporaryFile($temporaryPath);
        }
    }

    /**
     * Load the upload and its column mappings.
     */
    private function loadUpload(): CatalogUpload
    {
        return CatalogUpload::with('columnMappings')
            ->findOrFail($this->catalogUploadId);
    }

    /**
     * Mark the upload as being processed by this job.
     */
    private function markUploadAsProcessing(CatalogUpload $upload): void
    {
        $upload->update([
            'status' => CatalogUploadStatus::Processing,
            'processing_started_at' => now(),
        ]);
    }

    /**
     * Build all metadata and reader configuration needed to process the upload.
     *
     * @return array{
     *     columnToFieldKey: mixed,
     *     activeFields: mixed,
     *     rangeFieldKeys: array<int, string>,
     *     lookupMaps: array<string, mixed>,
     *     localPath: string,
     *     temporary_path: string|null,
     *     reader: mixed,
     *     allowedColumns: array<int, int>,
     *     totalRows: int
     * }
     */
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

        $reader = $this->createReader(
            $upload->file_type,
            $localPath
        );

        $allowedColumns = [];

        if ($upload->file_type !== 'csv') {
            $this->configureExcelReader(
                $reader,
                $localPath,
                $upload,
                $allowedColumns
            );
        }

        $totalRows = $this->determineTotalRows(
            $reader,
            $localPath,
            $upload->file_type
        );

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
        ];
    }

    /**
     * Build the source-column => field-key mapping used by mapRow().
     */
    private function buildColumnToFieldKey(CatalogUpload $upload)
    {
        return $upload->columnMappings
            ->mapWithKeys(fn($mapping) => [
                $mapping->column_index => $mapping->field_key,
            ]);
    }

    /**
     * Determine which mapped fields use attribute-range mode.
     *
     * A field is treated as a range field when it has more than one
     * column mapping row.
     *
     * @return array<int, string>
     */
    private function buildRangeFieldKeys(CatalogUpload $upload): array
    {
        return $upload->columnMappings
            ->groupBy('field_key')
            ->filter(fn($group) => $group->count() > 1)
            ->keys()
            ->all();
    }

    /**
     * Build normalized lookup maps once per upload instead of querying
     * reference tables for every row.
     *
     * @return array{
     *     category: array<string, int>,
     *     unit_of_measure: array{
     *         description: array<string, int>,
     *         code: array<string, int>
     *     }
     * }
     */
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
     * Create the appropriate spreadsheet reader for the uploaded file type.
     */
    private function createReader(string $fileType, string $localPath): object
    {
        $reader = $fileType === 'csv'
            ? new CsvReader()
            : IOFactory::createReader(
                $fileType === 'xls' ? 'Xls' : 'Xlsx'
            );

        $reader->setReadDataOnly(true);

        return $reader;
    }

    /**
     * Configure Excel-specific memory controls.
     *
     * Only the first worksheet and mapped columns are loaded.
     */
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

    /**
     * Determine the total number of rows without materializing the workbook.
     */
    private function determineTotalRows(
        object $reader,
        string $localPath,
        string $fileType
    ): int {
        $worksheetInfo = $reader->listWorksheetInfo($localPath);

        $totalRows = $worksheetInfo[0]['totalRows'] ?? 0;

        return $totalRows > 0
            ? $totalRows
            : PHP_INT_MAX;
    }

    /**
     * Process the uploaded file according to its format.
     *
     * CSV files are streamed directly so the entire file does not need to be
     * loaded repeatedly for every 500-row chunk.
     *
     * Excel files continue using PhpSpreadsheet's chunked read-filter approach.
     *
     * @return array{0: int, 1: int}
     */
    private function processUpload(
        CatalogUpload $upload,
        CatalogRowValidator $validator,
        array $context
    ): array {
        if ($upload->file_type === 'csv') {
            return $this->processCsvFile(
                $upload,
                $validator,
                $context
            );
        }

        return $this->processExcelFile(
            $upload,
            $validator,
            $context
        );
    }

    /**
     * Process CSV rows as a stream.
     *
     * The first CSV row is always treated as the header and is never
     * mapped, validated, counted, or inserted into catalog_upload_rows.
     *
     * fgetcsv() preserves standard CSV quoting/escaping behavior while
     * allowing the job to process one row at a time.
     *
     * @return array{0: int, 1: int}
     */
    private function processCsvFile(
        CatalogUpload $upload,
        CatalogRowValidator $validator,
        array $context
    ): array {
        $handle = fopen($context['localPath'], 'rb');

        if ($handle === false) {
            throw new \RuntimeException(
                'Unable to open CSV file for processing.'
            );
        }

        $successCount = 0;
        $errorCount = 0;
        $batch = [];

        /*
         * CSV row numbers are kept aligned with the physical file:
         *
         * Row 1 = header
         * Row 2 = first data row
         * Row 3 = second data row
         *
         * This is also consistent with the Excel processing path.
         */
        $sourceRowNumber = 0;

        try {
            while (($rowCells = fgetcsv($handle)) !== false) {
                $sourceRowNumber++;

                /*
                 * IMPORTANT:
                 *
                 * The first physical CSV row is the header.
                 * Do not pass it through mapRow() or validation.
                 */
                if ($sourceRowNumber === 1) {
                    continue;
                }

                $rowCells = $this->indexCsvRow($rowCells);

                if (
                    empty($rowCells)
                    || $this->rowIsBlank($rowCells)
                ) {
                    continue;
                }

                [$mappedData, $rawData] = $this->mapRow(
                    $rowCells,
                    $context['columnToFieldKey'],
                    $context['activeFields'],
                    $upload->columnMappings,
                    $context['rangeFieldKeys'],
                    $context['lookupMaps']
                );

                $result = $this->validateMappedRow(
                    $upload,
                    $validator,
                    $context['activeFields'],
                    $mappedData,
                    $sourceRowNumber
                );

                if ($result['status'] === 'valid') {
                    $successCount++;
                } else {
                    $errorCount++;
                }

                $batch[] = $this->buildRowPayload(
                    $upload,
                    $sourceRowNumber,
                    $mappedData,
                    $rawData,
                    $result
                );

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->flushBatch($batch);
                }
            }

            if (!empty($batch)) {
                $this->flushBatch($batch);
            }
        } finally {
            fclose($handle);
        }

        return [$successCount, $errorCount];
    }

    /**
     * Convert a CSV row into the same 0-based column-index structure used
     * by the Excel processing path.
     *
     * @param array<int, mixed> $row
     * @return array<int, mixed>
     */
    private function indexCsvRow(array $row): array
    {
        $indexed = [];

        foreach ($row as $index => $value) {
            $indexed[(int) $index] = $value;
        }

        return $indexed;
    }

    /**
     * Process Excel files in bounded row chunks.
     *
     * Row 1 is always treated as the header.
     *
     * @return array{0: int, 1: int}
     */
    private function processExcelFile(
        CatalogUpload $upload,
        CatalogRowValidator $validator,
        array $context
    ): array {
        $successCount = 0;
        $errorCount = 0;

        /*
         * Row 1 is the header.
         * Data processing therefore starts explicitly at row 2.
         */
        $chunkStartRow = 2;

        while ($chunkStartRow <= $context['totalRows']) {
            $chunkEndRow = min(
                $chunkStartRow + self::READ_CHUNK_SIZE - 1,
                $context['totalRows']
            );

            [$chunkSuccess, $chunkErrors] = $this->processExcelChunk(
                $upload,
                $validator,
                $context,
                $chunkStartRow,
                $chunkEndRow
            );

            $successCount += $chunkSuccess;
            $errorCount += $chunkErrors;

            $chunkStartRow = $chunkEndRow + 1;
        }

        return [$successCount, $errorCount];
    }

    /**
     * Load and process one Excel row chunk.
     *
     * @return array{0: int, 1: int}
     */
    private function processExcelChunk(
        CatalogUpload $upload,
        CatalogRowValidator $validator,
        array $context,
        int $chunkStartRow,
        int $chunkEndRow
    ): array {
        $reader = $context['reader'];
        $allowedColumns = $context['allowedColumns'];

        if (!empty($allowedColumns)) {
            $reader->setReadFilter(
                $this->createReadFilter(
                    $allowedColumns,
                    $chunkStartRow,
                    $chunkEndRow
                )
            );
        }

        $spreadsheet = $reader->load(
            $context['localPath']
        );

        try {
            /*
             * Always explicitly use worksheet 0.
             *
             * This prevents workbook active-sheet metadata from causing
             * another worksheet to be processed.
             */
            $sheet = $spreadsheet->getSheet(0);

            $highestRow = $sheet->getHighestDataRow();

            /*
             * Never allow processing to move above row 2.
             *
             * Row 1 is the header and must never reach mapRow() or
             * CatalogRowValidator.
             */
            $effectiveStartRow = max(
                2,
                $chunkStartRow
            );

            $effectiveEndRow = min(
                $highestRow,
                $chunkEndRow
            );

            $successCount = 0;
            $errorCount = 0;
            $batch = [];

            for (
                $rowIndex = $effectiveStartRow;
                $rowIndex <= $effectiveEndRow;
                $rowIndex++
            ) {
                /*
                 * Defensive guard:
                 *
                 * Even if the chunk boundaries are changed in the future,
                 * row 1 can never be processed as catalog data.
                 */
                if ($rowIndex < 2) {
                    continue;
                }

                $rowCells = $this->extractExcelRow(
                    $sheet,
                    $rowIndex
                );

                if (
                    empty($rowCells)
                    || $this->rowIsBlank($rowCells)
                ) {
                    continue;
                }

                [$mappedData, $rawData] = $this->mapRow(
                    $rowCells,
                    $context['columnToFieldKey'],
                    $context['activeFields'],
                    $upload->columnMappings,
                    $context['rangeFieldKeys'],
                    $context['lookupMaps']
                );

                $result = $this->validateMappedRow(
                    $upload,
                    $validator,
                    $context['activeFields'],
                    $mappedData,
                    $rowIndex
                );

                if ($result['status'] === 'valid') {
                    $successCount++;
                } else {
                    $errorCount++;
                }

                $batch[] = $this->buildRowPayload(
                    $upload,
                    $rowIndex,
                    $mappedData,
                    $rawData,
                    $result
                );

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->flushBatch($batch);
                }
            }

            if (!empty($batch)) {
                $this->flushBatch($batch);
            }

            return [$successCount, $errorCount];
        } finally {
            $spreadsheet->disconnectWorksheets();

            unset(
                $sheet,
                $spreadsheet
            );

            gc_collect_cycles();
        }
    }

    /**
     * Extract only existing cells from one Excel row.
     *
     * Cells excluded by the read filter are never auto-created.
     *
     * @return array<int, mixed>
     */
    private function extractExcelRow(
        object $sheet,
        int $rowIndex
    ): array {
        $rowCells = [];

        $row = $sheet
            ->getRowIterator(
                $rowIndex,
                $rowIndex
            )
            ->current();

        if ($row === null) {
            return [];
        }

        foreach (
            $row->getCellIterator(
                'A',
                null,
                true
            ) as $cell
        ) {
            $colIndex =
                Coordinate::columnIndexFromString(
                    $cell->getColumn()
                ) - 1;

            $rowCells[$colIndex] =
                $cell->getValue();
        }

        return $rowCells;
    }

    /**
     * Validate a mapped row and log blocking validation failures.
     *
     * @return array{
     *     status: string,
     *     errors: array,
     *     warnings: array
     * }
     */
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

        $blockingErrors =
            $result['errors'] ?? [];

        $warnings =
            $result['warnings'] ?? [];

        $status = empty($blockingErrors)
            ? 'valid'
            : 'invalid';

        if ($status === 'invalid') {
            Log::info(
                'ProcessCatalogUploadJob: validation failed for row',
                [
                    'upload_id' => $upload->id,

                    /*
                     * Existing row_number convention is preserved.
                     *
                     * Database row_number remains zero-based relative
                     * to the data rows:
                     *
                     * physical row 2 -> row_number 1
                     * physical row 3 -> row_number 2
                     */
                    'row_number' => $sourceRowNumber - 1,

                    'blocking_errors' =>
                    $blockingErrors,

                    'warnings' =>
                    $warnings,
                ]
            );
        }

        return [
            'status' => $status,
            'errors' => $blockingErrors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Build the CatalogUploadRow insert payload.
     */
    private function buildRowPayload(
        CatalogUpload $upload,
        int $sourceRowNumber,
        array $mappedData,
        array $rawData,
        array $validationResult
    ): array {
        $warnings =
            $validationResult['warnings'];

        $errors =
            $validationResult['errors'];

        $rowPayload = [
            'warnings' => $warnings,
            'errors' => $errors,
        ];

        $validationErrors =
            empty($warnings) && empty($errors)
            ? null
            : json_encode($rowPayload);

        return [
            'catalog_upload_id' =>
            $upload->id,

            /*
             * Preserve the existing database row_number behavior.
             */
            'row_number' =>
            $sourceRowNumber - 1,

            'data' =>
            json_encode($mappedData),

            'raw_data' =>
            json_encode($rawData),

            'status' =>
            $validationResult['status'],

            'errors' =>
            $validationErrors,

            'created_at' =>
            now(),

            'updated_at' =>
            now(),
        ];
    }

    /**
     * Return the distinct 0-based source column indexes actually mapped
     * for this upload.
     *
     * @return array<int, int>
     */
    private function requiredSourceColumnIndexes(
        CatalogUpload $upload
    ): array {
        return $upload->columnMappings
            ->pluck('column_index')
            ->filter(
                fn($index) =>
                $index !== null
            )
            ->map(
                fn($index) =>
                (int) $index
            )
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Create the PhpSpreadsheet read filter used for Excel chunk processing.
     *
     * When row bounds are omitted, all rows are allowed.
     */
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

                return isset(
                    $this->allowedColumns[$columnIndex]
                );
            }
        };
    }

    /**
     * Determine whether a row contains no meaningful values.
     */
    private function rowIsBlank(
        array $values
    ): bool {
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

    /**
     * Map one row's raw cell values into:
     *
     * 1. normalized mapped data
     * 2. raw per-column data
     *
     * @param array<int, mixed> $rowCells
     * @param mixed $columnToFieldKey
     * @param mixed $activeFields
     * @param mixed $columnMappings
     * @param array<int, string> $rangeFieldKeys
     * @param array<string, mixed> $lookupMaps
     *
     * @return array{
     *     0: array<string, mixed>,
     *     1: array<string, mixed>
     * }
     */
    private function mapRow(
        array $rowCells,
        $columnToFieldKey,
        $activeFields,
        $columnMappings,
        array $rangeFieldKeys,
        array $lookupMaps = []
    ): array {
        $mappedData = [];
        $rawData = [];

        foreach (
            $rowCells as $colIndex => $rawValue
        ) {
            $fieldKey =
                $columnToFieldKey->get(
                    $colIndex
                );

            $rawData["col_{$colIndex}"] = $rawValue;

            if (!$fieldKey) {
                continue;
            }

            $field =
                $activeFields->get(
                    $fieldKey
                );

            $mapping =
                $columnMappings->firstWhere(
                    'column_index',
                    $colIndex
                );

            if (
                $field
                && $field->is_multi_value
            ) {
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

            if (
                $this->isCategoryField(
                    $fieldKey
                )
            ) {
                $mappedData['category'] =
                    $this->resolveLookupId(
                        (string) $rawValue,
                        $lookupMaps['category']
                            ?? []
                    );

                continue;
            }

            if (
                $fieldKey ===
                'unit_of_measure'
            ) {
                $mappedData[$fieldKey] =
                    $this->resolveLookupId(
                        (string) $rawValue,
                        $lookupMaps['unit_of_measure']['description']
                            ?? [],
                        $lookupMaps['unit_of_measure']['code']
                            ?? []
                    );

                continue;
            }

            $mappedData[$fieldKey] =
                $this->normalizeScalar(
                    $rawValue
                );
        }

        return [
            $mappedData,
            $rawData,
        ];
    }

    /**
     * Determine whether a field uses the CommodityType lookup.
     *
     * Both fields intentionally persist to the category database column.
     */
    private function isCategoryField(
        string $fieldKey
    ): bool {
        return in_array(
            $fieldKey,
            [
                'category',
                'product_commodity_type',
            ],
            true
        );
    }

    /**
     * Map a multi-value field, including attribute-range mode.
     */
    private function mapMultiValueField(
        array &$mappedData,
        string $fieldKey,
        object $field,
        ?object $mapping,
        mixed $rawValue,
        array $rangeFieldKeys
    ): void {
        if (
            in_array(
                $fieldKey,
                $rangeFieldKeys,
                true
            )
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
                $mapping,
                $field
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

    /**
     * Map a field operating in attribute-range mode.
     *
     * The source column name becomes the key for key/value fields,
     * while the cell value becomes the value.
     */
    private function mapRangeValue(
        array &$mappedData,
        string $fieldKey,
        object $field,
        object $mapping,
        mixed $rawValue
    ): void {
        $value =
            $this->sanitizeValue(
                $rawValue
            );

        $key =
            $this->sanitizeValue(
                trim(
                    $mapping->source_column_name
                )
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

        $mappedData[$fieldKey][] =
            $value;
    }

    /**
     * Build a normalized name => id lookup map.
     *
     * Keys are lowercased and trimmed so matching is case-insensitive
     * and whitespace-tolerant.
     *
     * When duplicate normalized names exist, the first id wins.
     */
    private function buildLookupMap(
        $idsByName
    ): array {
        $map = [];

        foreach (
            $idsByName as $name => $id
        ) {
            if ($name === null) {
                continue;
            }

            $normalized =
                $this->normalizeForLookup(
                    (string) $name
                );

            if (
                $normalized === ''
                || isset($map[$normalized])
            ) {
                continue;
            }

            $map[$normalized] =
                $id;
        }

        return $map;
    }

    /**
     * Resolve vendor text against one or more lookup maps.
     *
     * The first matching map wins.
     */
    private function resolveLookupId(
        string $rawValue,
        array ...$maps
    ): ?int {
        $normalized =
            $this->normalizeForLookup(
                $rawValue
            );

        if ($normalized === '') {
            return null;
        }

        foreach ($maps as $map) {
            if (
                isset(
                    $map[$normalized]
                )
            ) {
                return $map[$normalized];
            }
        }

        return null;
    }

    /**
     * Normalize lookup text.
     */
    private function normalizeForLookup(
        string $value
    ): string {
        return strtolower(
            trim($value)
        );
    }

    /**
     * Insert accumulated CatalogUploadRow records in one transaction.
     */
    private function flushBatch(
        array &$batch
    ): void {
        DB::transaction(
            function () use ($batch) {
                CatalogUploadRow::insert(
                    $batch
                );
            }
        );

        $batch = [];
    }

    /**
     * Normalize scalar values while preserving non-string types.
     */
    private function normalizeScalar(
        mixed $value
    ): mixed {
        return is_string($value)
            ? $this->sanitizeValue(
                $value
            )
            : $value;
    }

    /**
     * Parse a vendor's multi-value string into canonical key/value objects.
     *
     * Each entry is split on the FIRST '=' only, allowing values themselves
     * to contain '='.
     *
     * Empty keys and values are ignored.
     *
     * @return array<int, array{key: string, value: string}>
     */
    private function parseMultiValueKeyValue(
        string $rawValue,
        string $separator
    ): array {
        if (
            trim($rawValue) === ''
        ) {
            return [];
        }

        $result = [];

        $parts =
            explode(
                $separator,
                $rawValue
            );

        foreach ($parts as $part) {
            $trimmed =
                trim($part);

            if (
                $trimmed === ''
            ) {
                continue;
            }

            $equalsPos =
                strpos(
                    $trimmed,
                    '='
                );

            if (
                $equalsPos === false
            ) {
                continue;
            }

            $key =
                $this->sanitizeValue(
                    trim(
                        substr(
                            $trimmed,
                            0,
                            $equalsPos
                        )
                    )
                );

            $value =
                $this->sanitizeValue(
                    trim(
                        substr(
                            $trimmed,
                            $equalsPos + 1
                        )
                    )
                );

            if (
                $key === ''
                || $value === ''
            ) {
                continue;
            }

            $result[] = [
                'key' => $key,
                'value' => $value,
            ];
        }

        return $result;
    }

    /**
     * Split a vendor's multi-value string into a simple array.
     *
     * Used for normal array fields such as search_terms and
     * classifications.
     *
     * @return array<int, string>
     */
    private function splitMultiValue(
        string $rawValue,
        string $separator
    ): array {
        if (
            trim($rawValue) === ''
        ) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    fn($value) =>
                    $this->sanitizeValue(
                        $value
                    ),
                    explode(
                        $separator,
                        $rawValue
                    )
                ),
                fn($value) =>
                $value !== ''
            )
        );
    }

    /**
     * Preserve vendor content while normalizing surrounding whitespace.
     */
    private function sanitizeValue(
        mixed $value
    ): mixed {
        if (!is_string($value)) {
            return $value;
        }

        return trim($value);
    }

    /**
     * Get the separator configured for the vendor's source column.
     *
     * If no separator is configured, preserve the existing comma fallback.
     */
    private function getVendorSourceSeparator(
        ?object $mapping,
        object $field
    ): string {
        if (
            $mapping
            && !empty($mapping->source_separator)
        ) {
            return $mapping->source_separator;
        }

        return ',';
    }

    /**
     * Resolve an uploaded file to a local filesystem path.
     *
     * Local disks use their existing path directly.
     *
     * Remote disks are streamed into a temporary file rather than using
     * Storage::get(), which would load the entire file into PHP memory.
     *
     * @return array{
     *     path: string,
     *     temporary_path: string|null
     * }
     */
    private function resolveLocalPath(
        string $disk,
        string $path
    ): array {
        $diskConfig =
            config(
                'filesystems.disks.' . $disk,
                []
            );

        if (
            ($diskConfig['driver'] ?? null)
            === 'local'
        ) {
            return [
                'path' =>
                Storage::disk($disk)
                    ->path($path),

                'temporary_path' =>
                null,
            ];
        }

        $tempPath =
            tempnam(
                sys_get_temp_dir(),
                'catalog_process_'
            );

        if ($tempPath === false) {
            throw new \RuntimeException(
                'Unable to create temporary file for catalog processing.'
            );
        }

        $sourceStream =
            Storage::disk($disk)
            ->readStream($path);

        if ($sourceStream === false) {
            @unlink($tempPath);

            throw new \RuntimeException(
                'Unable to open uploaded catalog file for reading.'
            );
        }

        $destinationStream =
            fopen(
                $tempPath,
                'wb'
            );

        if ($destinationStream === false) {
            fclose($sourceStream);

            @unlink($tempPath);

            throw new \RuntimeException(
                'Unable to open temporary catalog file for writing.'
            );
        }

        try {
            $bytesCopied =
                stream_copy_to_stream(
                    $sourceStream,
                    $destinationStream
                );

            if ($bytesCopied === false) {
                throw new \RuntimeException(
                    'Unable to copy uploaded catalog file to temporary storage.'
                );
            }
        } finally {
            fclose(
                $sourceStream
            );

            fclose(
                $destinationStream
            );
        }

        return [
            'path' =>
            $tempPath,

            'temporary_path' =>
            $tempPath,
        ];
    }

    /**
     * Remove a temporary remote-storage copy after processing completes.
     */
    private function cleanupTemporaryFile(
        ?string $temporaryPath
    ): void {
        if (
            $temporaryPath !== null
            && is_file($temporaryPath)
        ) {
            @unlink(
                $temporaryPath
            );
        }
    }

    /**
     * Persist upload counts and dispatch the item-processing job.
     *
     * ProcessValidatedRowsJob remains responsible for converting valid
     * CatalogUploadRow records into CatalogItem records and finalizing
     * the upload.
     */
    private function finalizeUpload(
        CatalogUpload $upload,
        int $successCount,
        int $errorCount
    ): void {
        $upload->update([
            'total_rows' =>
            $successCount + $errorCount,

            'success_rows' =>
            $successCount,

            'invalid_rows' =>
            $errorCount,
        ]);

        if ($successCount > 0) {
            $upload->update([
                'status' =>
                CatalogUploadStatus::Processing,

                'processing_completed_at' =>
                null,
            ]);

            \App\Jobs\ProcessValidatedRowsJob::dispatch(
                $upload->id
            );

            return;
        }

        $upload->update([
            'status' =>
            CatalogUploadStatus::Completed,

            'total_rows' =>
            $successCount + $errorCount,

            'success_rows' =>
            $successCount,

            'invalid_rows' =>
            $errorCount,

            'processing_completed_at' =>
            now(),
        ]);
    }

    /**
     * Mark the upload as failed and record the failure reason.
     */
    private function handleFailure(
        CatalogUpload $upload,
        Throwable $e
    ): void {
        Log::error(
            'ProcessCatalogUploadJob: upload failed',
            [
                'upload_id' =>
                $upload->id,

                'error' =>
                $e->getMessage(),

                'trace' =>
                $e->getTraceAsString(),
            ]
        );

        $upload->update([
            'status' =>
            CatalogUploadStatus::Failed,

            'failure_reason' =>
            Str::limit(
                $e->getMessage(),
                5000
            ),

            'processing_completed_at' =>
            now(),
        ]);
    }
}
