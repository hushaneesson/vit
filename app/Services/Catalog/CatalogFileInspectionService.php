<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as OpenSpoutCsvReader;
use OpenSpout\Reader\XLSX\Reader as OpenSpoutXlsxReader;
use OpenSpout\Reader\ReaderInterface;

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
        // Raise both for this request only â€” the rest of the app keeps its
        // normal limits.
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $localPath = $this->resolveLocalPath($disk, $path);

        // All supported formats (XLSX, CSV) are read through OpenSpout's
        // streaming reader. Constant memory: only the header row + the
        // requested sample rows are ever read, then iteration stops.
        return $this->inspectOpenSpout($localPath, $fileType, $sampleRows);
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

        $headerRow = $this->readHeaderRowOpenSpout(
            $localPath,
            $fileType
        );

        return $this->signatureFromHeaders($headerRow);
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

        $reader = $this->makeOpenSpoutReader($fileType, $localPath);

        try {
            $reader->open($localPath);

            $sheetIterator = $reader->getSheetIterator();
            $sheetIterator->rewind();
            $sheet = $sheetIterator->current();

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
            'xls' => IOFactory::createReader('Xls'),
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
        $reader = $this->makeOpenSpoutReader($fileType, $localPath);

        try {
            $reader->open($localPath);

            $sheetIterator = $reader->getSheetIterator();
            $sheetIterator->rewind();
            $sheet = $sheetIterator->current();

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
     * Instantiate the correct OpenSpout reader for the file type and apply
     * the options needed to reproduce the previous PhpSpreadsheet behavior:
     *
     *   CSV: comma delimiter, double-quote enclosure, UTF-8 encoding.
     *        OpenSpout normalizes UTF-8 input, so the encoding is set
     *        explicitly here for clarity.
     *
     * @param string $fileType 'xlsx' | 'csv'
     * @param string $localPath
     * @return ReaderInterface
     *
     * @throws \InvalidArgumentException For unsupported file types.
     */
    private function makeOpenSpoutReader(
        string $fileType,
        ?string $localPath = null
    ): ReaderInterface {
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
