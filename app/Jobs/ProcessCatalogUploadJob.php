<?php

namespace App\Jobs;

use App\Enums\CatalogUploadStatus;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
use App\Models\CommodityType;
use App\Models\UnitOfMeasure;
use App\Jobs\ProcessValidatedRowsJob;
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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class ProcessCatalogUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800; // 30 min ceiling for very large vendor files

    private const BATCH_SIZE = 200;
    private const READ_CHUNK_SIZE = 500;

    public function __construct(public int $catalogUploadId) {}

    public function handle(CatalogRowValidator $validator): void
    {
        $upload = CatalogUpload::with('columnMappings')->findOrFail($this->catalogUploadId);

        $upload->update([
            'status' => CatalogUploadStatus::Processing,
            'processing_started_at' => now(),
        ]);

        try {
            // column_index (int) => field_key (string|null - null means "unmapped, skip")
            $columnToFieldKey = $upload->columnMappings
                ->mapWithKeys(fn($mapping) => [
                    $mapping->column_index => $mapping->field_key,
                ]);

            // System-derived fields (e.g. seller from vendor.name) aren't mapped
            // from file columns — they're populated from the vendor record later.
            $activeFields = VitFieldDefinition::active()->keyBy('field_key');

            // field_keys that use attribute-range mode (a field mapped to multiple
            // source columns, e.g. one attribute per column). Detected by the field
            // having more than one column mapping row.
            $rangeFieldKeys = $upload->columnMappings
                ->groupBy('field_key')
                ->filter(fn($group) => $group->count() > 1)
                ->keys()
                ->all();

            // category and unit_of_measure arrive from the vendor as free text.
            // Build normalized name => id lookup maps once up front (not per
            // row) so mapRow() can resolve the vendor's text to the matching
            // database record's id. No match found => the column is left blank.
            $lookupMaps = [
                'category' => $this->buildLookupMap(CommodityType::query()->pluck('id', 'name')),
                'unit_of_measure' => [
                    'description' => $this->buildLookupMap(UnitOfMeasure::query()->pluck('id', 'description')),
                ],
            ];

            $localPath = $this->resolveLocalPath($upload->disk, $upload->file_path);
            $reader = $upload->file_type === 'csv'
                ? new CsvReader()
                : IOFactory::createReader($upload->file_type === 'xls' ? 'Xls' : 'Xlsx');

            $reader->setReadDataOnly(true);

            // Columns that were actually mapped, as 0-based Excel column offsets.
            // Populated once up front and reused for every chunk's read filter
            // below, so it must exist even for CSV files or an upload with no
            // mapped columns.
            $allowedColumns = [];

            // Memory: only the first worksheet is ever needed for catalog
            // processing. Without this, PhpSpreadsheet's load() materializes
            // every worksheet in the workbook into the Cell collection, which
            // can exhaust PHP's memory on multi-sheet/large files. Reading the
            // sheet name list is cheap (metadata only) and lets us restrict
            // load() to the first worksheet. CSV readers always produce a
            // single worksheet, so this is only needed for Excel readers.
            if ($upload->file_type !== 'csv') {
                $sheetNames = $reader->listWorksheetNames($localPath);
                if (!empty($sheetNames)) {
                    $reader->setLoadSheetsOnly($sheetNames[0]);
                }

                // Memory: tell the Excel reader to materialize ONLY the columns
                // the user actually mapped (read from the persisted column
                // mappings), so PhpSpreadsheet never builds Cell objects for
                // unmapped columns. This targets the original memory-exhaustion
                // point (Cells.php CellCollection::add() at load()). The 0-based
                // indexes come from the SAME source column_index values used
                // during mapping, so mapRow()'s original-index lookups stay
                // correct, and range-mapped columns are already persisted as
                // multiple column-mapping rows.
                $requiredColumns = $this->requiredSourceColumnIndexes($upload);
                if (!empty($requiredColumns)) {
                    $allowedColumns = array_flip($requiredColumns);

                    $reader->setReadFilter(new class($allowedColumns) implements IReadFilter {
                        public function __construct(private array $allowedColumns) {}

                        public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                        {
                            if ($row < 1) {
                                return false;
                            }

                            return isset($this->allowedColumns[Coordinate::columnIndexFromString($columnAddress) - 1]);
                        }
                    });
                }
            }

            // Determine total row count via metadata (no cell loading)
            // For Excel: listWorkshetInfo returns total row count without materializing cells
            // For CSV: we'll use a large chunk approach that processes all rows
            $totalRows = 0;
            $startRow = 2; // Row 1 is the header, data starts at row 2

            if ($upload->file_type === 'csv') {
                // For CSV, we'll process all rows in chunks
                // First try to get row count from the reader
                $csvInfo = $reader->listWorksheetInfo($localPath);
                $totalRows = $csvInfo[0]['totalRows'] ?? 0;
            } else {
                // Excel: get total row count via metadata (no cell materialization)
                $worksheetInfo = $reader->listWorksheetInfo($localPath);
                $totalRows = $worksheetInfo[0]['totalRows'] ?? 0;
            }

            // If we couldn't determine total rows, fall back to a safe default
            // that will process until the reader can't provide more data
            if ($totalRows <= 0) {
                $totalRows = PHP_INT_MAX;
            }

            // Row 1 is the header - data starts at row 2.
            // Process in chunks of READ_CHUNK_SIZE rows.
            $successCount = 0;
            $errorCount = 0;
            $batch = [];
            $batchSize = self::BATCH_SIZE;

            $chunkStartRow = $startRow;

            while ($chunkStartRow <= $totalRows) {
                $chunkEndRow = min($chunkStartRow + self::READ_CHUNK_SIZE - 1, $totalRows);

                // Create a read filter that allows ONLY:
                // a. the required mapped columns (if any were resolved above)
                // b. rows within the current chunk
                if ($upload->file_type !== 'csv' && !empty($allowedColumns)) {
                    $reader->setReadFilter(new class($allowedColumns, $chunkStartRow, $chunkEndRow) implements IReadFilter {
                        public function __construct(private array $allowedColumns, private int $minRow, private int $maxRow) {}

                        public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                        {
                            // Allow only rows within the current chunk
                            if ($row < $this->minRow || $row > $this->maxRow) {
                                return false;
                            }

                            // Allow only mapped columns
                            if ($row < 1) {
                                return false;
                            }

                            return isset($this->allowedColumns[Coordinate::columnIndexFromString($columnAddress) - 1]);
                        }
                    });
                }

                // Load the workbook - this will only materialize the filtered columns and rows
                $spreadsheet = $reader->load($localPath);
                // Explicitly use the FIRST worksheet. Multi-sheet workbooks must
                // be processed from Sheet 1 only; getActiveSheet() could return a
                // different sheet if the workbook metadata marks another as active.
                $sheet = $spreadsheet->getSheet(0);

                $highestRow = $sheet->getHighestDataRow();

                // Process only rows in the current chunk (starting from max(2, chunkStartRow))
                $effectiveStartRow = max(2, $chunkStartRow);
                $effectiveEndRow = min($highestRow, $chunkEndRow);

                for ($rowIndex = $effectiveStartRow; $rowIndex <= $effectiveEndRow; $rowIndex++) {
                    $rowCells = [];
                    $row = $sheet->getRowIterator($rowIndex, $rowIndex)->current();

                    if ($row !== null) {
                        // Iterate ONLY existing cells (onlyExisting=true) so unmapped
                        // columns excluded by the read filter are never auto-created
                        // (which would re-defeat the memory optimization). Index each
                        // value by its real Excel coordinate so mapRow()'s
                        // column_index lookups stay correct even though the row is
                        // now sparse — only mapped columns are present, keyed by
                        // their original 0-based offset from column A.
                        foreach ($row->getCellIterator('A', null, true) as $cell) {
                            $colIndex = Coordinate::columnIndexFromString($cell->getColumn()) - 1;
                            $rowCells[$colIndex] = $cell->getValue();
                        }
                    }

                    if (!isset($rowCells) || empty($rowCells) || $this->rowIsBlank($rowCells)) {
                        continue;
                    }

                    [$mappedData, $rawData] = $this->mapRow($rowCells, $columnToFieldKey, $activeFields, $upload->columnMappings, $rangeFieldKeys, $lookupMaps);

                    $result = $validator->validate($activeFields, $mappedData);
                    $blockingErrors = $result['errors'] ?? [];
                    $warnings = $result['warnings'] ?? [];
                    $status = empty($blockingErrors) ? 'valid' : 'invalid';

                    $status === 'valid' ? $successCount++ : $errorCount++;

                    $rowPayload = [
                        'warnings' => $warnings,
                        'errors' => $blockingErrors,
                    ];

                    if ($status === 'invalid') {
                        Log::info('ProcessCatalogUploadJob: validation failed for row', [
                            'upload_id' => $upload->id,
                            'row_number' => $rowIndex - 1,
                            // 'mapped_data' => $mappedData,
                            // 'raw_data' => $rawData,
                            'blocking_errors' => $blockingErrors,
                            'warnings' => $warnings,
                        ]);
                    }

                    $batch[] = [
                        'catalog_upload_id' => $upload->id,
                        'row_number' => $rowIndex - 1,
                        'data' => json_encode($mappedData),
                        'raw_data' => json_encode($rawData),
                        'status' => $status,
                        'errors' => empty($rowPayload['warnings']) && empty($rowPayload['errors'])
                            ? null
                            : json_encode($rowPayload),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (count($batch) >= $batchSize) {
                        $this->flushBatch($batch);
                    }
                }

                // Flush any pending batch after the chunk
                if (! empty($batch)) {
                    $this->flushBatch($batch);
                }

                // Disconnect and release the spreadsheet memory
                if (isset($spreadsheet)) {
                    $spreadsheet->disconnectWorksheets();
                }
                unset($sheet, $spreadsheet);

                // Run garbage collection to free memory
                gc_collect_cycles();

                // Move to the next chunk
                $chunkStartRow = $chunkEndRow + 1;
            }

            // Persist the final counts so tests and the progress screen can read them.
            $upload->update([
                'total_rows' => $successCount + $errorCount,
                'success_rows' => $successCount,
                'invalid_rows' => $errorCount,
            ]);

            // convert validated rows into CatalogItem records.
            // ProcessValidatedRowsJob will claim ownership and finalize the upload.
            if ($successCount > 0) {
                // Set status to Processing so ProcessValidatedRowsJob can claim it
                $upload->update([
                    'status' => CatalogUploadStatus::Processing,
                    'processing_completed_at' => null,
                ]);

                ProcessValidatedRowsJob::dispatch($upload->id);
            } else {
                // No valid rows — nothing to process, mark as completed with counts
                $upload->update([
                    'status' => CatalogUploadStatus::Completed,
                    'total_rows' => $successCount + $errorCount,
                    'success_rows' => $successCount,
                    'invalid_rows' => $errorCount,
                    'processing_completed_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('ProcessCatalogUploadJob: upload failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $upload->update([
                'status' => CatalogUploadStatus::Failed,
                'failure_reason' => \Illuminate\Support\Str::limit($e->getMessage(), 5000),
                'processing_completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * The distinct 0-based source column indexes that are actually mapped
     * for this upload (i.e. present as CatalogUploadColumnMapping rows).
     * Used to restrict the Excel reader to only the columns that matter,
     * so unmapped columns never get materialized into Cell objects.
     *
     * @return array<int, int>
     */
    private function requiredSourceColumnIndexes(CatalogUpload $upload): array
    {
        return $upload->columnMappings
            ->pluck('column_index')
            ->filter(fn($index) => $index !== null)
            ->map(fn($index) => (int) $index)
            ->unique()
            ->values()
            ->all();
    }

    private function rowIsBlank(array $values): bool
    {
        foreach ($values as $value) {
            if (! is_null($value) && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Map one row's raw cell values into the normalized mapped data and the
     * raw per-column data, using the upload's column mappings and the active
     * VIT field definitions.
     *
     * @param  array<int, mixed>  $rowCells
     * @param  \Illuminate\Support\Collection<int, string|null>  $columnToFieldKey
     * @param  \Illuminate\Support\Collection<string, object>  $activeFields
     * @param  \Illuminate\Support\Collection<int, object>  $columnMappings
     * @param  array{category: array<string, int>, unit_of_measure: array{description: array<string, int>, code: array<string, int>}}  $lookupMaps
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [mappedData, rawData]
     */
    private function mapRow(array $rowCells, $columnToFieldKey, $activeFields, $columnMappings, array $rangeFieldKeys, array $lookupMaps = []): array
    {
        $mappedData = [];
        $rawData = [];

        foreach ($rowCells as $colIndex => $rawValue) {
            $fieldKey = $columnToFieldKey->get($colIndex);
            $rawData["col_{$colIndex}"] = $rawValue;

            if (! $fieldKey) {
                continue; // vendor's column wasn't mapped to anything - ignore
            }

            $field = $activeFields->get($fieldKey);
            $mapping = $columnMappings->firstWhere('column_index', $colIndex);

            if ($field && $field->is_multi_value) {
                // Attribute-range mode: this field spans multiple source columns.
                if (in_array($fieldKey, $rangeFieldKeys, true) && $mapping && !empty($mapping->source_column_name)) {
                    $value = $this->sanitizeValue($rawValue);
                    $key = $this->sanitizeValue(trim($mapping->source_column_name));

                    if ($value !== null && $value !== '' && $key !== '') {
                        if ($field->is_key_value) {
                            // Specifications: column header -> key, cell value -> value
                            $mappedData[$fieldKey][] = [
                                'key' => $key,
                                'value' => $value,
                            ];
                        } else {
                            // Normal array field: just collect the value
                            $mappedData[$fieldKey][] = $value;
                        }
                    }
                    continue;
                }

                $vendorSeparator = $this->getVendorSourceSeparator($mapping, $field);
                if ($field->is_key_value) {
                    // Specifications: parse "key=value" entries
                    $mappedData[$fieldKey] = $this->parseMultiValueKeyValue((string) $rawValue, $vendorSeparator);
                } else {
                    // Normal array field: split by separator into simple array
                    $mappedData[$fieldKey] = $this->splitMultiValue((string) $rawValue, $vendorSeparator);
                }
            } elseif ($fieldKey === 'category') {
                // Both 'category' and the VIT field 'product_commodity_type'
                // store the resolved CommodityType ID in the 'category' DB column.
                $mappedData['category'] = $this->resolveLookupId(
                    (string) $rawValue,
                    $lookupMaps['category'] ?? []
                );
            } elseif ($fieldKey === 'product_commodity_type') {
                // product_commodity_type is the VIT field_key exposed in the mapper
                // UI but persist to the 'category' DB column (FK to commodity_types).
                // Reuses the same lookup map as 'category' rather than creating a
                // second lookup system. The exporter reads it back via
                // commodityType.name (model_attribute), keeping persistence and
                // export representations cleanly separated.
                $mappedData['category'] = $this->resolveLookupId(
                    (string) $rawValue,
                    $lookupMaps['category'] ?? []
                );
            } elseif ($fieldKey === 'unit_of_measure') {
                $mappedData[$fieldKey] = $this->resolveLookupId(
                    (string) $rawValue,
                    $lookupMaps['unit_of_measure']['description'] ?? [],
                    $lookupMaps['unit_of_measure']['code'] ?? []
                );
            } else {
                $mappedData[$fieldKey] = $this->normalizeScalar($rawValue);
            }
        }

        return [$mappedData, $rawData];
    }

    /**
     * Build a normalized "name => id" lookup map from a pluck()'d
     * id-keyed-by-name collection. Keys are lowercased and trimmed so
     * matching is case-insensitive and whitespace-tolerant. When the
     * source data has duplicate normalized names, the first id wins.
     *
     * @param  \Illuminate\Support\Collection<int|string, int>  $idsByName
     * @return array<string, int>
     */
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

    /**
     * Resolve the vendor's raw text to a database id by checking it against
     * one or more normalized lookup maps in order. Returns null (blank) if
     * the raw value is empty or no map contains a match.
     *
     * @param  array<string, int>  ...$maps
     */
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
     * Insert the accumulated batch of CatalogUploadRow records in a single
     * transaction, then reset the batch to an empty array.
     *
     * @param  array<int, array<string, mixed>>  $batch
     */
    private function flushBatch(array &$batch): void
    {
        DB::transaction(function () use ($batch) {
            CatalogUploadRow::insert($batch);
        });

        $batch = [];
    }

    private function normalizeScalar(mixed $value): mixed
    {
        return is_string($value) ? $this->sanitizeValue($value) : $value;
    }

    /**
     * Parse a vendor's multi-value string into canonical key/value objects.
     *
     * Each entry is split on the FIRST '=' only, so values containing '=' are
     * preserved intact. Empty keys and empty values are ignored.
     *
     * @return array<int, array{key: string, value: string}>
     */
    private function parseMultiValueKeyValue(string $rawValue, string $separator): array
    {
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

            // Split on the FIRST '=' only
            $equalsPos = strpos($trimmed, '=');
            if ($equalsPos === false) {
                continue;
            }

            $key = $this->sanitizeValue(trim(substr($trimmed, 0, $equalsPos)));
            $value = $this->sanitizeValue(trim(substr($trimmed, $equalsPos + 1)));

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

    /**
     * Split a vendor's multi-value string into a simple array of strings.
     *
     * Used for normal array fields like search_terms, classifications, etc.
     *
     * @return array<int, string>
     */
    private function splitMultiValue(string $rawValue, string $separator): array
    {
        if (trim($rawValue) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(fn($v) => $this->sanitizeValue($v), explode($separator, $rawValue)),
            fn($v) => $v !== ''
        ));
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        // Preserve the vendor's content; only normalize surrounding whitespace.
        // HTML is not stripped unless the VIT specification explicitly requires it.
        return trim($value);
    }

    /**
     * Get the vendor's source separator for a multi-value field.
     *
     * Returns the separator the vendor used in their uploaded file for this
     * specific column mapping. If not set, falls back to a safe default.
     *
     * @return string The separator character (e.g. ',', '|', ';')
     */
    private function getVendorSourceSeparator(?object $mapping, object $field): string
    {
        if ($mapping && !empty($mapping->source_separator)) {
            return $mapping->source_separator;
        }

        // Fallback: no separator configured. We cannot reliably guess the vendor's
        // separator, so we use a single-character default that minimizes damage.
        // This should be made explicit in the mapping UI instead of guessed here.
        return ',';
    }

    private function resolveLocalPath(string $disk, string $path): string
    {
        $diskConfig = config('filesystems.disks.' . $disk, []);
        if (($diskConfig['driver'] ?? null) === 'local') {
            return Storage::disk($disk)->path($path);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'catalog_process_');
        file_put_contents($tempPath, Storage::disk($disk)->get($path));

        return $tempPath;
    }
}
