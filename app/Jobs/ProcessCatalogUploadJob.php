<?php

namespace App\Jobs;

use App\Enums\CatalogUploadStatus;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
use App\Notifications\CatalogImportFailedNotification;
use App\Services\Catalog\OpenSpoutRowReader;
use Generator;
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

    public function handle(OpenSpoutRowReader $rowReader): void
    {
        $upload = $this->loadUpload();

        $this->markUploadAsProcessing($upload);

        CatalogUploadRow::where('catalog_upload_id', $upload->id)->delete();

        $temporaryPath = null;

        try {
            $context = $this->buildProcessingContext($upload);

            $temporaryPath = $context['temporary_path'];

            $this->dispatchChunks($upload, $context, $rowReader);
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
        $pathResult = $this->resolveLocalPath($upload->disk, $upload->file_path);

        return [
            'localPath' => $pathResult['path'],
            'temporary_path' => $pathResult['temporary_path'],
        ];
    }

    /**
     * Stream every non-blank data row from the upload (XLS, XLSX, or CSV),
     * staging it in READ_CHUNK_SIZE batches. Child jobs are dispatched only
     * after ALL rows have been staged and total_rows + ProcessingItems are
     * persisted — this invariant lets the parent job be retried without risk
     * of double-processing any rows.
     */
    private function dispatchChunks(CatalogUpload $upload, array $context, OpenSpoutRowReader $rowReader): int
    {
        $chunkRanges = [];
        $stagedTotal = 0;
        $rows = [];

        foreach ($this->iterateRows($upload->file_type, $context['localPath'], $rowReader) as $row) {
            $rows[] = $row;

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

        CatalogUpload::whereKey($upload->id)->update([
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
     * Dispatch to the right reader and yield ['cells' => ..., 'row_number' => ...]
     * for every non-blank data row, regardless of source format.
     */
    private function iterateRows(string $fileType, string $filePath, OpenSpoutRowReader $rowReader): Generator
    {
        if ($fileType === 'xls') {
            yield from $this->iterateXlsRows($filePath);
        } else {
            yield from $this->iterateOpenSpoutRows($fileType, $filePath, $rowReader);
        }
    }

    /**
     * XLS is a legacy BIFF format not supported by OpenSpout, so it goes
     * through PhpSpreadsheet instead. Processes only the first worksheet,
     * matching the inspection service.
     */
    private function iterateXlsRows(string $filePath): Generator
    {
        $reader = IOFactory::createReader('Xls');
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();

        try {
            for ($row = 2; $row <= $highestRow; $row++) {
                $rowCells = $this->extractPhpSpreadsheetRow($sheet, $row);

                if ($this->rowIsBlank($rowCells)) {
                    continue;
                }

                yield ['cells' => $rowCells, 'row_number' => $row];
            }
        } finally {
            // Release PhpSpreadsheet resources before temporary-file cleanup.
            $spreadsheet->disconnectWorksheets();
            unset($sheet, $spreadsheet, $reader);
        }
    }

    private function iterateOpenSpoutRows(string $fileType, string $filePath, OpenSpoutRowReader $rowReader): Generator
    {
        $reader = $rowReader->makeReader($fileType, $filePath);
        $reader->open($filePath);

        try {
            $sourceRowNumber = 0;

            foreach ($reader->getSheetIterator() as $sheet) {
                $isFirstRow = true;

                foreach ($sheet->getRowIterator() as $row) {
                    $sourceRowNumber++;

                    if ($isFirstRow) {
                        $isFirstRow = false;

                        continue;
                    }

                    $rowCells = $rowReader->extractRowCells($row);

                    if ($this->rowIsBlank($rowCells)) {
                        continue;
                    }

                    yield ['cells' => $rowCells, 'row_number' => $sourceRowNumber];
                }

                break; // first sheet only, matching the XLS path
            }
        } finally {
            // Release the OpenSpout reader before temporary-file cleanup.
            $reader->close();
            unset($reader);
        }
    }

    /**
     * Persist one staging chunk and return [rowCount, [firstId, lastId]].
     * Dispatch of the corresponding child job happens later, only after ALL
     * chunks are staged (see dispatchChunks()).
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

                $firstRowId ??= $catalogUploadRow->id;
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

    private function extractPhpSpreadsheetRow($sheet, int $row): array
    {
        $rowCells = [];
        $highestColumn = $sheet->getHighestColumn();

        foreach (range('A', $highestColumn) as $column) {
            $value = $sheet->getCell($column.$row)->getValue();

            // Normalize PhpSpreadsheet's 1-based column indexes to the zero-based indexes used by OpenSpout.
            $rowCells[Coordinate::columnIndexFromString($column) - 1] = $this->normalizeCellValue($value);
        }

        return $rowCells;
    }

    private function normalizeCellValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H\:i:s');
        }

        return $value;
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
