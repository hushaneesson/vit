<?php

namespace App\Jobs;

use App\Enums\CatalogUploadStatus;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
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
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;

class ProcessCatalogUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800; // 30 min ceiling for very large vendor files

    private const BATCH_SIZE = 200;

    public function __construct(public int $catalogUploadId) {}

    public function handle(CatalogRowValidator $validator): void
    {
        $upload = CatalogUpload::with('columnMappings')->findOrFail($this->catalogUploadId);

        Log::info('CATALOG DEBUG: loaded column mappings', [
            'upload_id' => $upload->id,
            'mappings' => $upload->columnMappings->map(fn($mapping) => [
                'column_index' => $mapping->column_index,
                'field_key' => $mapping->field_key,
                'source_column_name' => $mapping->source_column_name,
                'source_separator' => $mapping->source_separator,
            ])->values()->all(),
        ]);

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

            $localPath = $this->resolveLocalPath($upload->disk, $upload->file_path);
            $reader = $upload->file_type === 'csv'
                ? new CsvReader()
                : IOFactory::createReader($upload->file_type === 'xls' ? 'Xls' : 'Xlsx');

            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($localPath);
            $sheet = $spreadsheet->getActiveSheet();

            $highestRow = $sheet->getHighestDataRow();
            $successCount = 0;
            $errorCount = 0;
            $batch = [];
            $batchSize = self::BATCH_SIZE;

            // Row 1 is the header - data starts at row 2.
            for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
                $rowCells = [];
                foreach ($sheet->getRowIterator($rowIndex, $rowIndex)->current()->getCellIterator() as $cell) {
                    $rowCells[] = $cell->getValue();
                }

                if ($this->rowIsBlank($rowCells)) {
                    continue;
                }

                [$mappedData, $rawData] = $this->mapRow($rowCells, $columnToFieldKey, $activeFields, $upload->columnMappings, $rangeFieldKeys);

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

            if (! empty($batch)) {
                $this->flushBatch($batch);
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
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [mappedData, rawData]
     */
    private function mapRow(array $rowCells, $columnToFieldKey, $activeFields, $columnMappings, array $rangeFieldKeys): array
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
                    // $key = $this->sanitizeValue(trim($mapping->source_column_name));
                    $key = trim($mapping->source_column_name);

                    Log::info('SPEC RANGE DEBUG', [
                        // 'upload_id' => $upload->id,
                        'column_index' => $colIndex,
                        'field_key' => $fieldKey,
                        'raw_value' => $rawValue,
                        'mapping_source_column_name' => $mapping->source_column_name,
                        'resolved_key' => $key,
                        'resolved_value' => $value,
                    ]);


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
            } else {
                $mappedData[$fieldKey] = $this->normalizeScalar($rawValue);
            }
        }

        return [$mappedData, $rawData];
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
