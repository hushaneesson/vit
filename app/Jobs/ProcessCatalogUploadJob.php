<?php

namespace App\Jobs;

use App\Enums\CatalogUploadStatus;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
use App\Notifications\CatalogImportFailedNotification;
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

    /**
     * Queue failure lifecycle: runs when the parent job exhausts its attempts
     * (tries=1, so on the first exception — including exceptions thrown before
     * the handle() try block, e.g. loadUpload/markUploadAsProcessing, timeouts,
     * or worker kills where the inline catch never runs).
     *
     * Only the parent marks the whole upload Failed. Idempotent and never
     * overwrites a Completed upload. No validation-report email is sent here.
     */
    public function failed(Throwable $exception): void
    {
        $upload = CatalogUpload::find($this->catalogUploadId);

        if (! $upload) {
            return;
        }

        // Never overwrite a Completed upload (e.g. late failure callback).
        if ($upload->status === CatalogUploadStatus::Completed) {
            return;
        }

        // Already handled by the inline catch in handle().
        if ($upload->status === CatalogUploadStatus::Failed) {
            return;
        }

        $failureReason = Str::limit($exception->getMessage(), 5000);

        $upload->update([
            'status' => CatalogUploadStatus::Failed,
            'failure_reason' => $failureReason,
            'processing_completed_at' => now(),
        ]);

        Log::error('ProcessCatalogUploadJob: processing failed', [
            'upload_id' => $upload->id,
            'error' => $exception->getMessage(),
        ]);

        $this->sendFailureNotification($upload, $failureReason);
    }

    private function sendFailureNotification(CatalogUpload $upload, string $failureReason): void
    {
        try {
            $client = $upload->client;

            if (! $client || ! $client->email) {
                return;
            }

            $client->notify(new CatalogImportFailedNotification(
                catalogId: $upload->catalog_id,
                catalogUploadId: $upload->id,
                failureReason: Str::limit($failureReason, 500),
            ));
        } catch (Throwable $e) {
            Log::warning(
                'Failed to send catalog import failure email for upload '
                .$upload->id.': '.$e->getMessage()
            );
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

        // child jobs are dispatched only after all rows have been staged and total_rows + ProcessingItems are persisted.
        // This invariant ensures that the parent job can be retried without risk of double-processing any rows.

        $filePath = $context['localPath'];

        $fileType = $upload->file_type;

        $chunkRanges = [];

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

                        [$stagedCount, $range] = $this->stageRows($upload, $rows);

                        $stagedTotal += $stagedCount;

                        if ($range !== null) {
                            $chunkRanges[] = $range;
                        }

                        $rows = [];
                    }
                }

                if (! empty($rows)) {

                    [$stagedCount, $range] = $this->stageRows($upload, $rows);

                    $stagedTotal += $stagedCount;

                    if ($range !== null) {
                        $chunkRanges[] = $range;
                    }
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

                            [$stagedCount, $range] = $this->stageRows($upload, $rows);

                            $stagedTotal += $stagedCount;

                            if ($range !== null) {
                                $chunkRanges[] = $range;
                            }

                            $rows = [];
                        }
                    }

                    break;
                }

                if (! empty($rows)) {

                    [$stagedCount, $range] = $this->stageRows($upload, $rows);

                    $stagedTotal += $stagedCount;

                    if ($range !== null) {
                        $chunkRanges[] = $range;
                    }
                }
            } finally {

                // Release the OpenSpout reader before temporary-file cleanup.

                $reader->close();

                unset($sheet, $reader);
            }
        }

        CatalogUpload::whereKey($upload->id)

            ->update([
                'total_rows' => $stagedTotal,
                'status' => CatalogUploadStatus::ProcessingItems,
            ]);

        if ($stagedTotal === 0) {

            // Complete header-only or empty uploads without waiting for child jobs.

            CatalogUpload::whereKey($upload->id)->update([

                'total_rows' => 0,

                'success_rows' => 0,

                'invalid_rows' => 0,

                'status' => CatalogUploadStatus::Completed,

                'processing_completed_at' => now(),

            ]);

            return 0;
        }

        $dispatched = 0;

        foreach ($chunkRanges as [$firstRowId, $lastRowId]) {

            ProcessValidatedRowsJob::dispatch(

                $upload->id,

                $firstRowId,

                $lastRowId,

                $this->isVitFileImport

            )->onQueue('imports');

            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Persist one 500-row staging chunk and return [rowCount, [firstId, lastId]].
     * Dispatch of the corresponding child job happens later, only after ALL
     * chunks are staged and total_rows + ProcessingItems are persisted.
     *
     * @return array{0: int, 1: array{0: int, 1: int}|null}
     */
    private function stageRows(CatalogUpload $upload, array $rows): array
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

        if ($firstRowId === null || $lastRowId === null) {
            return [0, null];
        }

        return [count($rows), [$firstRowId, $lastRowId]];
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

        $failureReason = Str::limit($e->getMessage(), 5000);

        $upload->update([

            'status' => CatalogUploadStatus::Failed,

            'failure_reason' => $failureReason,

            'processing_completed_at' => now(),

        ]);

        // Parent-staging failure email. Intentionally separate from the
        // >10-row validation report: a staging failure never reaches the
        // completion path, so no validation report or completion
        // notification is sent. failed() skips re-sending because the
        // status is already Failed by then.
        $this->sendFailureNotification($upload, $failureReason);
    }

    private function cleanupTemporaryFile(?string $temporaryPath): void
    {

        if ($temporaryPath !== null && file_exists($temporaryPath)) {

            unlink($temporaryPath);
        }
    }
}
