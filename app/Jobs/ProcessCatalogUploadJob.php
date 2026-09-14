<?php

namespace App\Jobs;

use App\Enums\CatalogUploadStatus;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as OpenSpoutCsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as OpenSpoutXlsxReader;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class ProcessCatalogUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const READ_CHUNK_SIZE = 500;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(

        public int $catalogUploadId,

        public bool $isVitFileImport = false

    ) {

        $this->onQueue('imports');
    }

    public function handle(): void
    {

        $upload = $this->loadUpload();

        $this->markUploadAsProcessing($upload);

        CatalogUploadRow::where('catalog_upload_id', $upload->id)->delete();

        $temporaryPath = null;

        try {

            $context = $this->buildProcessingContext($upload);

            $temporaryPath = $context['temporary_path'];

            $dispatched = $this->dispatchChunks($upload, $context);

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

        // Reset result counters before child jobs begin processing.
        // total_rows is set after all rows have been staged.

        $upload->update([

            'status' => CatalogUploadStatus::Processing,

            'processing_started_at' => now(),

            'success_rows' => 0,

            'invalid_rows' => 0,

            'created_rows' => 0,

            'updated_rows' => 0,

            'unchanged_rows' => 0,

        ]);
    }

    private function buildProcessingContext(CatalogUpload $upload): array
    {

        $pathResult = $this->resolveLocalPath(

            $upload->disk,

            $upload->file_path

        );

        return [

            'localPath' => $pathResult['path'],

            'temporary_path' => $pathResult['temporary_path'],

        ];
    }

    private function dispatchChunks(CatalogUpload $upload, array $context): int
    {

        $filePath = $context['localPath'];

        $fileType = $upload->file_type;

        $dispatched = 0;

        $stagedTotal = 0;

        $rows = [];

        $sourceRowNumber = 0;

        if ($fileType === 'xls') {

            // XLS is a legacy BIFF format not supported by OpenSpout.

            $reader = IOFactory::createReader('Xls');

            $reader->setReadDataOnly(true);

            $spreadsheet = $reader->load($filePath);

            // Process only the first worksheet, matching the inspection service.

            $sheet = $spreadsheet->getSheet(0);

            $highestRow = $sheet->getHighestDataRow();

            try {

                for ($row = 2; $row <= $highestRow; $row++) {

                    $sourceRowNumber = $row;

                    $rowCells = $this->extractPhpSpreadsheetRow($sheet, $row);

                    if ($this->rowIsBlank($rowCells)) {

                        continue;
                    }

                    $rows[] = [

                        'cells' => $rowCells,

                        'row_number' => $sourceRowNumber,

                    ];

                    if (count($rows) >= self::READ_CHUNK_SIZE) {

                        $stagedTotal += $this->stageAndDispatch($upload, $rows);

                        $rows = [];

                        $dispatched++;
                    }
                }

                if (! empty($rows)) {

                    $stagedTotal += $this->stageAndDispatch($upload, $rows);

                    $dispatched++;
                }
            } finally {

                // Release PhpSpreadsheet resources before temporary-file cleanup.

                $spreadsheet->disconnectWorksheets();

                unset($sheet, $spreadsheet, $reader);
            }
        } else {

            $reader = $this->createReader($fileType);

            $reader->open($filePath);

            try {

                $isFirstRow = true;

                foreach ($reader->getSheetIterator() as $sheet) {

                    foreach ($sheet->getRowIterator() as $row) {

                        $sourceRowNumber++;

                        if ($isFirstRow) {

                            $isFirstRow = false;

                            continue;
                        }

                        $rowCells = $this->extractOpenSpoutRow($row);

                        if ($this->rowIsBlank($rowCells)) {

                            continue;
                        }

                        $rows[] = [

                            'cells' => $rowCells,

                            'row_number' => $sourceRowNumber,

                        ];

                        if (count($rows) >= self::READ_CHUNK_SIZE) {

                            $stagedTotal += $this->stageAndDispatch($upload, $rows);

                            $rows = [];

                            $dispatched++;
                        }
                    }

                    break;
                }

                if (! empty($rows)) {

                    $stagedTotal += $this->stageAndDispatch($upload, $rows);

                    $dispatched++;
                }
            } finally {

                // Release the OpenSpout reader before temporary-file cleanup.

                $reader->close();

                unset($sheet, $reader);
            }
        }

        // Set the staged-row count so child jobs can determine when processing is complete.

        CatalogUpload::whereKey($upload->id)

            ->update(['total_rows' => $stagedTotal]);

        if ($dispatched === 0) {

            // Complete header-only or empty uploads without waiting for child jobs.

            CatalogUpload::whereKey($upload->id)->update([

                'total_rows' => 0,

                'success_rows' => 0,

                'invalid_rows' => 0,

                'status' => CatalogUploadStatus::Completed,

                'processing_completed_at' => now(),

            ]);
        }

        return $dispatched;
    }

    private function stageAndDispatch(CatalogUpload $upload, array $rows): int
    {

        $firstRowId = null;

        $lastRowId = null;

        DB::beginTransaction();

        try {

            foreach ($rows as $row) {

                $catalogUploadRow = new CatalogUploadRow;

                $catalogUploadRow->catalog_upload_id = $upload->id;

                $catalogUploadRow->row_number = $row['row_number'];

                $catalogUploadRow->raw_data = $row['cells'];

                $catalogUploadRow->data = [];

                $catalogUploadRow->status = 'valid';

                $catalogUploadRow->save();

                if ($firstRowId === null) {

                    $firstRowId = $catalogUploadRow->id;
                }

                $lastRowId = $catalogUploadRow->id;
            }

            DB::commit();
        } catch (Throwable $e) {

            DB::rollBack();

            throw $e;
        }

        ProcessValidatedRowsJob::dispatch(

            $upload->id,

            $firstRowId,

            $lastRowId,

            $this->isVitFileImport

        )->onQueue('imports');

        return count($rows);
    }

    private function resolveLocalPath(string $disk, string $filePath): array
    {

        $temporaryPath = null;

        $localPath = $filePath;

        if ($disk !== 'local') {

            $temporaryPath = tempnam(sys_get_temp_dir(), 'catalog_upload_');

            file_put_contents($temporaryPath, Storage::disk($disk)->get($filePath));

            $localPath = $temporaryPath;
        }

        return [

            'path' => $localPath,

            'temporary_path' => $temporaryPath,

        ];
    }

    private function createReader(string $fileType): ReaderInterface
    {

        if ($fileType === 'csv') {

            return new OpenSpoutCsvReader;
        }

        return new OpenSpoutXlsxReader;
    }

    private function extractPhpSpreadsheetRow($sheet, int $row): array
    {

        $rowCells = [];

        $highestColumn = $sheet->getHighestColumn();

        $columnRange = range('A', $highestColumn);

        foreach ($columnRange as $column) {

            $cell = $sheet->getCell($column.$row);

            $value = $cell->getValue();

            if ($value instanceof \DateTimeInterface) {

                $value = $value->format('Y-m-d H\:i:s');
            }

            // Normalize PhpSpreadsheet's 1-based column indexes to the zero-based indexes used by OpenSpout.

            $rowCells[Coordinate::columnIndexFromString($column) - 1] = $value;
        }

        return $rowCells;
    }

    private function extractOpenSpoutRow($row): array
    {

        $rowCells = [];

        foreach ($row->getCells() as $colIndex => $cell) {

            $value = $cell->getValue();

            if ($value instanceof \DateTimeInterface) {

                $value = $value->format('Y-m-d H\:i:s');
            }

            $rowCells[$colIndex] = $value;
        }

        return $rowCells;
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

    private function handleFailure(CatalogUpload $upload, Throwable $e): void
    {

        Log::error('ProcessCatalogUploadJob: processing failed', [

            'upload_id' => $upload->id,

            'error' => $e->getMessage(),

        ]);

        $upload->update([

            'status' => CatalogUploadStatus::Failed,

            'failure_reason' => Str::limit($e->getMessage(), 5000),

            'processing_completed_at' => now(),

        ]);
    }

    private function cleanupTemporaryFile(?string $temporaryPath): void
    {

        if ($temporaryPath !== null && file_exists($temporaryPath)) {

            unlink($temporaryPath);
        }
    }
}
