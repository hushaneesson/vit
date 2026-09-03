<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Reader\IReader;

class CatalogFileInspectionService
{
    /**
     * Read just the header row + a handful of sample rows, for the
     * column-mapping screen. Does NOT load the whole file into memory -
     * important for larger vendor exports.
     */
    public function inspect(string $disk, string $path, string $fileType, int $sampleRows = 5): array
    {
        // Large vendor files (e.g. multi-MB XLSX) can take longer than the
        // default 30s max_execution_time and 128M memory_limit to parse.
        // Raise both for this request only — the rest of the app keeps its
        // normal limits.
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $localPath = $this->resolveLocalPath($disk, $path);

        $reader = $this->makeReader($fileType, $localPath);
        $reader->setReadDataOnly(true);

        // Only read the first N+1 rows (header + samples) to keep this fast
        // even on large vendor files.
        $reader->setReadFilter(new class($sampleRows + 1) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
            public function __construct(private int $maxRow) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->maxRow;
            }
        });

        $spreadsheet = $reader->load($localPath);

        // Explicitly use the FIRST worksheet. Multi-sheet workbooks must be
        // processed from Sheet 1 only; getActiveSheet() could return a
        // different sheet if the workbook metadata marks another as active.
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, false);

        $headerRow = array_map(
            fn($value) => is_string($value) ? trim($value) : $value,
            $rows[0] ?? []
        );

        $sampleData = array_slice($rows, 1, $sampleRows);

        // Drop fully-empty trailing columns some Excel exports leave behind.
        $lastNonEmptyIndex = $this->lastNonEmptyColumnIndex($headerRow);
        $headerRow = array_slice($headerRow, 0, $lastNonEmptyIndex + 1);
        $sampleData = array_map(
            fn($row) => array_slice($row, 0, $lastNonEmptyIndex + 1),
            $sampleData
        );

        return [
            'columns' => $headerRow,
            'sample_rows' => $sampleData,
        ];
    }

        /**
     * Compute a stable signature for a file's column structure.
     *
     * The signature is an MD5 of the sorted, lowercased, trimmed non-empty
     * column headers. Two files with the same set of headers (regardless
     * of order or case) produce the same signature, which is exactly what
     * we want for matching mapping templates.
     *
     * If the file has no detectable columns, returns null.
     */
    public function computeFileSignature(string $disk, string $path, string $fileType, ?array $headerRow = null): ?string
    {
        // When headers are already available (e.g. from inspect()), compute the
        // signature directly without loading the workbook through PhpSpreadsheet.
        // This avoids materializing a multi-MB worksheet just to re-derive headers
        // we already have in memory.
        if ($headerRow !== null) {
            $headerRow = array_map(
                fn($value) => is_string($value) ? trim($value) : $value,
                $headerRow
            );

            return $this->signatureFromHeaders($headerRow);
        }

        $localPath = $this->resolveLocalPath($disk, $path);

        $reader = $this->makeReader($fileType, $localPath);
        $reader->setReadDataOnly(true);

        // Only the HEADER row (row 1) is needed for the signature. Reading
        // the whole workbook would materialize every cell of every row for
        // large vendor exports (tens of thousands of rows), which exhausts
        // PHP's memory_limit. Limit loading to the first row only — the same
        // constant-memory read-filter strategy used by inspect() above.
        $reader->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row === 1;
            }
        });

        $spreadsheet = $reader->load($localPath);

        // Use the first worksheet explicitly rather than getActiveSheet(), so
        // the signature is stable regardless of which sheet the workbook marks
        // as active (matches inspect()'s "Sheet 1 only" rule).
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, false);

        $headerRow = array_map(
            fn($value) => is_string($value) ? trim($value) : $value,
            $rows[0] ?? []
        );

        return $this->signatureFromHeaders($headerRow);
    }

    private function signatureFromHeaders(array $headerRow): ?string
    {
        // Drop fully-empty trailing columns
        $lastNonEmptyIndex = $this->lastNonEmptyColumnIndex($headerRow);
        $headerRow = array_slice($headerRow, 0, $lastNonEmptyIndex + 1);

        // Normalize: lowercase, trim, drop empties, sort for stability
        $normalized = collect($headerRow)
            ->filter(fn($v) => !is_null($v) && trim((string) $v) !== '')
            ->map(fn($v) => strtolower(trim((string) $v)))
            ->sort()
            ->values()
            ->all();

        if (empty($normalized)) {
            return null;
        }

        return md5(json_encode($normalized));
    }

    /**
     * Bug fix: previously called CsvReader::guessEncoding() with
     * $this->lastLocalPath before it had ever been set (resolveLocalPath
     * runs AFTER makeReader in the original code path), so encoding
     * detection silently ran against an empty string every time. Now the
     * local path is resolved first and passed straight in.
     */
    private function makeReader(string $fileType, ?string $localPath = null): IReader
    {
        $reader = match ($fileType) {
            'csv' => new CsvReader(),
            'xlsx', 'xls' => IOFactory::createReader($fileType === 'xls' ? 'Xls' : 'Xlsx'),
            default => throw new \InvalidArgumentException("Unsupported file type: {$fileType}"),
        };

        if ($reader instanceof CsvReader) {
            $reader->setDelimiter(',');
            $reader->setEnclosure('"');

            if ($localPath) {
                $reader->setInputEncoding(CsvReader::guessEncoding($localPath));
            }
        }

        return $reader;
    }

    private function resolveLocalPath(string $disk, string $path): string
    {
        // PhpSpreadsheet needs a local filesystem path. If the disk is
        // remote (DigitalOcean Spaces), pull the file down to a temp path
        // first rather than streaming, since PhpSpreadsheet's readers
        // expect seekable local files.
        if ((Storage::disk($disk)->getConfig()['driver'] ?? null) === 'local') {
            return Storage::disk($disk)->path($path);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'catalog_upload_');
        file_put_contents($tempPath, Storage::disk($disk)->get($path));

        return $tempPath;
    }

    private function lastNonEmptyColumnIndex(array $headerRow): int
    {
        for ($i = count($headerRow) - 1; $i >= 0; $i--) {
            if (! is_null($headerRow[$i]) && trim((string) $headerRow[$i]) !== '') {
                return $i;
            }
        }

        return 0;
    }
}
