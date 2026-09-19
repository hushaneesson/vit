<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\ReaderInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\IReader;

class CatalogFileInspectionService
{
    public function __construct(
        private readonly OpenSpoutRowReader $rowReader = new OpenSpoutRowReader()
    ) {}

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
        // normal limits. previousMemoryLimit is restored in the finally
        // block below since this service can run inside a long-lived queue
        // worker process, where ini_set() would otherwise persist across
        // unrelated jobs instead of resetting the way it would per-request
        // under PHP-FPM.
        $previousMemoryLimit = ini_get('memory_limit');

        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $pathResult = $this->resolveLocalPath($disk, $path);
        $localPath = $pathResult['path'];

        try {
            return match ($fileType) {
                'xlsx', 'csv' => $this->inspectOpenSpout($localPath, $fileType, $sampleRows),
                'xls' => $this->inspectLegacy($localPath, $sampleRows),
                default => throw new \InvalidArgumentException(
                    "Unsupported file type: {$fileType}"
                ),
            };
        } finally {
            $this->cleanupTemporaryFile($pathResult['temporary_path']);
            ini_set('memory_limit', $previousMemoryLimit);
        }
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
            return $this->signatureFromHeaders($this->trimHeaderValues($headerRow));
        }

        $pathResult = $this->resolveLocalPath($disk, $path);
        $localPath = $pathResult['path'];

        try {
            // Reader selection by format:
            //   xlsx, csv → OpenSpout (streaming, constant memory)
            //   xls       → PhpSpreadsheet (binary BIFF)
            $headerRow = match ($fileType) {
                'xlsx', 'csv' => $this->readHeaderRowOpenSpout($localPath, $fileType),
                'xls' => $this->readHeaderRowLegacy($localPath),
                default => throw new \InvalidArgumentException(
                    "Unsupported file type: {$fileType}"
                ),
            };

            return $this->signatureFromHeaders($headerRow);
        } finally {
            $this->cleanupTemporaryFile($pathResult['temporary_path']);
        }
    }

    /**
     * Inspect a catalog file using OpenSpout's streaming reader.
     *
     * Only the header row + the requested sample rows are read, then
     * iteration stops — large workbooks are never materialized in memory.
     * Cell indexes come from OpenSpout's `getCells()` keys (zero-based
     * A=0, B=1, ..., TI=528 for a 529-column workbook), so the shape is
     * identical to the previous array-based result.
     *
     * For XLSX: only the first worksheet is read.
     * For CSV: OpenSpout treats the single CSV as the equivalent of one
     * sheet, so the same "first worksheet" semantics apply.
     *
     * @param string $localPath
     * @param string $fileType 'xlsx' | 'csv'
     * @param int $sampleRows
     * @return array{columns: list<mixed>, sample_rows: list<array<int,mixed>>}
     */
    private function inspectOpenSpout(
        string $localPath,
        string $fileType,
        int $sampleRows
    ): array {
        $read = $this->withOpenSpoutSheet(
            $fileType,
            $localPath,
            fn (object $sheet) => $this->readOpenSpoutRows($sheet, $sampleRows)
        );

        if ($read === null) {
            return ['columns' => [], 'sample_rows' => []];
        }

        ['headers' => $headers, 'sample_data' => $sampleData, 'header_width' => $headerWidth] = $read;

        // Drop fully-empty trailing columns some exports leave behind.
        [$headers, $lastNonEmptyIndex] = $this->trimHeaderAndGetLastIndex($headers);

        // Pad each sample row to the header width, then trim to the same range.
        $sampleData = array_map(
            function ($row) use ($headerWidth, $lastNonEmptyIndex): array {
                $padded = [];

                for ($i = 0; $i < $headerWidth; $i++) {
                    $padded[$i] = $row[$i] ?? null;
                }

                return array_slice($padded, 0, $lastNonEmptyIndex + 1);
            },
            $sampleData
        );

        return [
            'columns' => $headers,
            'sample_rows' => $sampleData,
        ];
    }

    /**
     * Read the header row + up to $sampleRows data rows from an
     * already-opened OpenSpout sheet. Row-collection logic only, no
     * trimming — inspectOpenSpout() does that once the reader is closed.
     *
     * @return array{headers: list<mixed>, sample_data: list<array<int,mixed>>, header_width: int}
     */
    private function readOpenSpoutRows(object $sheet, int $sampleRows): array
    {
        $headers = [];
        $sampleData = [];
        $headerWidth = 0;
        $rowCount = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $rowCells = $this->rowReader->extractRowCells($row);

            if ($rowCount === 0) {
                // Header row.
                $headers = $this->trimHeaderValues($rowCells);
                $headerWidth = count($headers);
            } elseif ($rowCount <= $sampleRows) {
                $sampleData[] = $rowCells;
            } else {
                // Already collected header + requested samples.
                break;
            }

            $rowCount++;
        }

        return ['headers' => $headers, 'sample_data' => $sampleData, 'header_width' => $headerWidth];
    }

    /**
     * Inspect a legacy .xls file using PhpSpreadsheet (the same read-filter
     * strategy as before; only the first N+1 rows are loaded).
     *
     * .xls is the binary BIFF format — OpenSpout has no reader for it.
     *
     * @param string $localPath
     * @param int $sampleRows
     * @return array{columns: list<mixed>, sample_rows: list<array<int,mixed>>}
     */
    private function inspectLegacy(string $localPath, int $sampleRows): array
    {
        $sheet = $this->loadLegacySheet($localPath, $sampleRows + 1);
        $rows = $sheet->toArray(null, true, true, false);

        $headerRow = $this->trimHeaderValues($rows[0] ?? []);
        $sampleData = array_slice($rows, 1, $sampleRows);

        // Drop fully-empty trailing columns some Excel exports leave behind.
        [$headerRow, $lastNonEmptyIndex] = $this->trimHeaderAndGetLastIndex($headerRow);
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
     * Read only the header row (row 1) of a legacy .xls file using
     * PhpSpreadsheet's read-filter (only that row is ever loaded).
     *
     * @param string $localPath
     * @return list<mixed>
     */
    private function readHeaderRowLegacy(string $localPath): array
    {
        $sheet = $this->loadLegacySheet($localPath, 1);
        $row = $sheet->toArray(null, true, true, false)[0] ?? [];

        return $this->trimHeaderValues($row);
    }

    /**
     * Load a legacy .xls file with a read filter limited to rows [1, $maxRow]
     * (PhpSpreadsheet only parses the allowed rows, so this stays fast even
     * on large .xls files). Shared by inspectLegacy() (header + sample rows)
     * and readHeaderRowLegacy() (row 1 only), which previously each built
     * their own reader/filter/load sequence.
     *
     * Always returns the FIRST worksheet explicitly (never
     * getActiveSheet()), so multi-sheet workbooks are read consistently
     * regardless of which sheet the workbook marks as active.
     */
    private function loadLegacySheet(string $localPath, int $maxRow)
    {
        $reader = $this->makeReader('xls');
        $reader->setReadDataOnly(true);
        $reader->setReadFilter($this->createRowRangeFilter(1, $maxRow));

        $spreadsheet = $reader->load($localPath);

        return $spreadsheet->getSheet(0);
    }

    /**
     * Build a PhpSpreadsheet read filter limited to an inclusive row range.
     * Shared by loadLegacySheet(), so there's a single filter
     * implementation for "only read these rows" rather than hand-written
     * copies per caller.
     */
    private function createRowRangeFilter(int $minRow, int $maxRow): IReadFilter
    {
        return new class($minRow, $maxRow) implements IReadFilter {
            public function __construct(
                private int $minRow,
                private int $maxRow
            ) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row >= $this->minRow && $row <= $this->maxRow;
            }
        };
    }

    private function signatureFromHeaders(array $headerRow): ?string
    {
        // Drop fully-empty trailing columns
        [$headerRow, ] = $this->trimHeaderAndGetLastIndex($headerRow);

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
     * Instantiate the PhpSpreadsheet reader for .xls (binary BIFF).
     *
     * CSV and XLSX are handled by OpenSpout (see makeOpenSpoutReader).
     * OpenSpout has no .xls reader, so PhpSpreadsheet is required here.
     *
     * @param string $fileType 'xls'
     * @return IReader
     *
     * @throws \InvalidArgumentException For unsupported file types.
     */
    private function makeReader(string $fileType): IReader
    {
        return match ($fileType) {
            'xls' => IOFactory::createReader('Xls'),
            default => throw new \InvalidArgumentException(
                "Unsupported file type: {$fileType}"
            ),
        };
    }

    /**
     * Resolve a disk-relative path to a local filesystem path.
     *
     * PhpSpreadsheet and OpenSpout both need a local, seekable file.
     * If the disk is remote (e.g. DigitalOcean Spaces), the file is copied
     * to a temp path first. Callers MUST pass 'temporary_path' to
     * cleanupTemporaryFile() when done, or the temp file is leaked.
     *
     * @return array{path: string, temporary_path: ?string}
     */
    private function resolveLocalPath(string $disk, string $path): array
    {
        if ((Storage::disk($disk)->getConfig()['driver'] ?? null) === 'local') {
            return [
                'path' => Storage::disk($disk)->path($path),
                'temporary_path' => null,
            ];
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'catalog_upload_');
        file_put_contents($tempPath, Storage::disk($disk)->get($path));

        return [
            'path' => $tempPath,
            'temporary_path' => $tempPath,
        ];
    }

    private function cleanupTemporaryFile(?string $temporaryPath): void
    {
        if ($temporaryPath !== null && is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
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

    /**
     * Trim trailing fully-empty columns from a header row and return both
     * the trimmed row and the index it was cut at. Several callers
     * (inspectOpenSpout, inspectLegacy) need that index again afterward to
     * trim their sample rows to the same width, so it's returned alongside
     * the trimmed row instead of being recomputed.
     *
     * @return array{0: list<mixed>, 1: int}
     */
    private function trimHeaderAndGetLastIndex(array $headerRow): array
    {
        $lastNonEmptyIndex = $this->lastNonEmptyColumnIndex($headerRow);

        return [array_slice($headerRow, 0, $lastNonEmptyIndex + 1), $lastNonEmptyIndex];
    }

    /**
     * Trim each string cell in a row; non-string values pass through
     * unchanged. Every header-reading path normalizes the same way, so this
     * replaces what used to be five identical inline array_map closures.
     */
    private function trimHeaderValues(array $row): array
    {
        return array_map(fn($value) => is_string($value) ? trim($value) : $value, $row);
    }

    /**
     * Read only the header row (row 1) using OpenSpout's streaming reader,
     * stopping immediately after the first row. Uses the first worksheet
     * explicitly, matching the "Sheet 1 only" rule.
     *
     * @param string $localPath
     * @param string $fileType 'xlsx' | 'csv'
     * @return list<mixed>
     */
    private function readHeaderRowOpenSpout(
        string $localPath,
        string $fileType
    ): array {
        $header = $this->withOpenSpoutSheet($fileType, $localPath, function (object $sheet) {
            $rowIterator = $sheet->getRowIterator();
            $rowIterator->rewind();

            if (! $rowIterator->valid()) {
                return [];
            }

            return $this->rowReader->extractRowCells($rowIterator->current());
        });

        return $this->trimHeaderValues($header ?? []);
    }

    /**
     * Open the given file with the right OpenSpout reader, hand its first
     * worksheet to $callback, and guarantee the reader is closed afterward
     * (even if $callback throws). Returns null without invoking $callback
     * if the workbook has no worksheets. Centralizes the open/close
     * lifecycle shared by inspectOpenSpout() and readHeaderRowOpenSpout().
     */
    private function withOpenSpoutSheet(string $fileType, string $localPath, callable $callback): mixed
    {
        $reader = $this->rowReader->makeReader($fileType, $localPath);

        try {
            $reader->open($localPath);

            $sheet = $this->openFirstSheet($reader);

            return $sheet === null ? null : $callback($sheet);
        } finally {
            $reader->close();
        }
    }

    /**
     * Return the first worksheet of an already-open OpenSpout reader, or
     * null if the workbook has no worksheets. Centralizes the "always
     * Sheet 1" rule so withOpenSpoutSheet() doesn't re-implement the
     * sheet-iterator boilerplate itself.
     */
    private function openFirstSheet(ReaderInterface $reader): ?object
    {
        $sheetIterator = $reader->getSheetIterator();
        $sheetIterator->rewind();

        return $sheetIterator->current();
    }
}
