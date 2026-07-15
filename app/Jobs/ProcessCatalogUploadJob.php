<?php

namespace App\Jobs;

use App\Models\CatalogField;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
use App\Jobs\ProcessValidatedRowsJob;
use App\Services\CatalogRowValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;

class ProcessCatalogUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800; // 30 min ceiling for very large vendor files

    public function __construct(public int $catalogUploadId) {}

    public function handle(CatalogRowValidator $validator): void
    {
        $upload = CatalogUpload::with('columnMappings.catalogField')->findOrFail($this->catalogUploadId);

        $upload->update([
            'status' => 'processing',
            'processing_started_at' => now(),
        ]);

        try {
            // column_index (int) => field_key (string|null - null means "unmapped, skip")
            $columnToFieldKey = $upload->columnMappings
                ->mapWithKeys(fn ($mapping) => [
                    $mapping->column_index => $mapping->catalogField?->field_key,
                ]);

            // System-derived fields (e.g. seller from vendor.name) aren't mapped
            // from file columns — they're populated from the vendor record later.
            $activeFields = CatalogField::where('active', true)
                ->where('is_system_derived', false)
                ->get()
                ->keyBy('field_key');

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
            $batchSize = 200;

            // Row 1 is the header - data starts at row 2.
            for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
                $rawValues = [];
                foreach ($sheet->getRowIterator($rowIndex, $rowIndex)->current()->getCellIterator() as $cell) {
                    $rawValues[] = $cell->getValue();
                }

                if ($this->rowIsBlank($rawValues)) {
                    continue;
                }

                $mappedData = [];
                $rawData = [];

                foreach ($rawValues as $colIndex => $rawValue) {
                    $fieldKey = $columnToFieldKey->get($colIndex);
                    $rawData["col_{$colIndex}"] = $rawValue;

                    if (! $fieldKey) {
                        continue; // vendor's column wasn't mapped to anything - ignore
                    }

                    $field = $activeFields->get($fieldKey);
                    $mappedData[$fieldKey] = $field && $field->is_multi_value
                        ? $this->splitMultiValue((string) $rawValue, $field->join_separator ?? ',')
                        : $this->normalizeScalar($rawValue);
                }

                $result = $validator->validate($activeFields, $mappedData);
                $status = empty($result['errors']) ? 'valid' : 'invalid';

                $status === 'valid' ? $successCount++ : $errorCount++;

                $batch[] = [
                    'catalog_upload_id' => $upload->id,
                    'row_number' => $rowIndex - 1,
                    'data' => json_encode($mappedData),
                    'raw_data' => json_encode($rawData),
                    'status' => $status,
                    'errors' => empty($result['errors']) ? null : json_encode($result['errors']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($batch) >= $batchSize) {
                    CatalogUploadRow::insert($batch);
                    $batch = [];
                }
            }

            if (! empty($batch)) {
                CatalogUploadRow::insert($batch);
            }

            $upload->update([
                'status' => 'completed',
                'total_rows' => $successCount + $errorCount,
                'success_rows' => $successCount,
                'error_rows' => $errorCount,
                'processing_completed_at' => now(),
            ]);

            // Phase 8B: convert validated rows into CatalogItem records
            if ($successCount > 0) {
                ProcessValidatedRowsJob::dispatch($upload->id);
            }
        } catch (\Throwable $e) {
            $upload->update([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
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

    private function normalizeScalar(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    private function splitMultiValue(string $rawValue, string $separator): array
    {
        if (trim($rawValue) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode($separator, $rawValue)), fn ($v) => $v !== ''));
    }

    private function resolveLocalPath(string $disk, string $path): string
    {
        if ((Storage::disk($disk)->getConfig()['driver'] ?? null) === 'local') {
            return Storage::disk($disk)->path($path);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'catalog_process_');
        file_put_contents($tempPath, Storage::disk($disk)->get($path));

        return $tempPath;
    }
}
