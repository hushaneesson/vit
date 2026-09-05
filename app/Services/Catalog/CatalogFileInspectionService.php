<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as OpenSpoutCsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as OpenSpoutXlsxReader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
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
            $headerRow = array_map(
                fn($value) => is_string($value) ? trim($value) : $value,
                $headerRow
            );

            return $this->signatureFromHeaders($headerRow);
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
        $headers = [];
        $sampleData = [];
        $headerWidth = 0;
        $rowCount = 0;

        $reader = $this->makeOpenSpoutReader($fileType);

        try {
            $reader->open($localPath);

            $sheet = $this->openFirstSheet($reader);

            if ($sheet === null) {
                return ['columns' => [], 'sample_rows' => []];
            }

            foreach ($sheet->getRowIterator() as $row) {
                $rowCells = $this->extractOpenSpoutRowCells($row);

                if ($rowCount === 0) {
                    // Header row.
                    $headers = array_map(
                        fn($value) => is_string($value) ? trim($value) : $value,
                        $rowCells
                    );
                    $headerWidth = count($headers);
                } elseif ($rowCount <= $sampleRows) {
                    $sampleData[] = $rowCells;
                } else {
                    // Already collected header + requested samples.
                    break;
                }

                $rowCount++;
            }
        } finally {
            $reader->close();
        }

        // Drop fully-empty trailing columns some exports leave behind.
        $lastNonEmptyIndex = $this->lastNonEmptyColumnIndex($headers);
        $headers = array_values(
            array_slice($headers, 0, $lastNonEmptyIndex + 1)
        );

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
        $reader = $this->makeReader('xls');
        $reader->setReadDataOnly(true);

        // Only read the first N+1 rows (header + samples) to keep this fast
        // even on large .xls files.
        $reader->setReadFilter(
            $this->createRowRangeFilter(1, $sampleRows + 1)
        );

        $spreadsheet = $reader->load($localPath);

        // Explicitly use the FIRST worksheet. Multi-sheet workbooks must be
        // processed from Sheet 1 only; getActiveSheet() could return a
        // different sheet.
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
     * Read only the header row (row 1) of a legacy .xls file using
     * PhpSpreadsheet's read-filter (only that row is ever loaded).
     *
     * @param string $localPath
     * @return list<mixed>
     */
    private function readHeaderRowLegacy(string $localPath): array
    {
        $reader = $this->makeReader('xls');
        $reader->setReadDataOnly(true);

        $reader->setReadFilter(
            $this->createRowRangeFilter(1, 1)
        );

        $spreadsheet = $reader->load($localPath);

        // Use the first worksheet explicitly rather than getActiveSheet(),
        // so the signature is stable regardless of which sheet the workbook
        // marks as active.
        $sheet = $spreadsheet->getSheet(0);
        $row = $sheet->toArray(null, true, true, false)[0] ?? [];

        return array_map(
            fn($value) => is_string($value) ? trim($value) : $value,
            $row
        );
    }

    /**
     * Build a PhpSpreadsheet read filter limited to an inclusive row range.
     * Shared by inspectLegacy() (header + sample rows) and
     * readHeaderRowLegacy() (row 1 only), so there's a single filter
     * implementation for "only read these rows" rather than two
     * hand-written copies.
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
        $reader = $this->makeOpenSpoutReader($fileType);

        try {
            $reader->open($localPath);

            $sheet = $this->openFirstSheet($reader);

            if ($sheet === null) {
                return [];
            }

            $rowIterator = $sheet->getRowIterator();
            $rowIterator->rewind();

            if (! $rowIterator->valid()) {
                return [];
            }

            $header = $this->extractOpenSpoutRowCells(
                $rowIterator->current()
            );
        } finally {
            $reader->close();
        }

        return array_map(
            fn($value) => is_string($value) ? trim($value) : $value,
            $header
        );
    }

    /**
     * Return the first worksheet of an already-open OpenSpout reader, or
     * null if the workbook has no worksheets. Centralizes the "always
     * Sheet 1" rule so inspectOpenSpout() and readHeaderRowOpenSpout()
     * don't each re-implement the sheet-iterator boilerplate.
     */
    private function openFirstSheet(ReaderInterface $reader): ?object
    {
        $sheetIterator = $reader->getSheetIterator();
        $sheetIterator->rewind();

        return $sheetIterator->current();
    }

    /**
     * Instantiate the correct OpenSpout reader for the file type and apply
     * the options needed to reproduce the previous PhpSpreadsheet behavior:
     *
     *   CSV: comma delimiter, double-quote enclosure, UTF-8 encoding.
     *        OpenSpout normalizes UTF-8 input, so the encoding is set
     *        explicitly here for clarity.
     *
     * @param string $fileType 'xlsx' | 'csv'
     * @return ReaderInterface
     *
     * @throws \InvalidArgumentException For unsupported file types.
     */
    private function makeOpenSpoutReader(string $fileType): ReaderInterface
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
                "Unsupported file type: {$fileType}"
            ),
        };
    }


    /**
     * Extract a row's cells preserving their zero-based column indexes (the
     * keys OpenSpout uses, matching A=0,B=1...). Dates are formatted
     * to strings, following the same convention as the streaming job..
     *
     * @return array<int,mixed>
     */
    private function extractOpenSpoutRowCells(object $row): array
    {
        $cells = [];

        foreach ($row->getCells() as $colIndex => $cell) {
            $value = $cell->getValue();

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }

            $cells[$colIndex] = $value;
        }

        return $cells;
    }
}
