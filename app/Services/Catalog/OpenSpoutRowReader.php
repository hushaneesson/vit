<?php

namespace App\Services\Catalog;

use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as OpenSpoutCsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as OpenSpoutXlsxReader;

class OpenSpoutRowReader
{
    public function __construct(
        private readonly CsvEncodingDetector $encodingDetector = new CsvEncodingDetector(),
    ) {}

    /**
     * Instantiate the correct OpenSpout reader for the file type and apply
     * the options needed for the catalog import:
     *
     *   CSV: comma delimiter, double-quote enclosure, and the source encoding
     *        resolved by CsvEncodingDetector. OpenSpout transcodes each cell
     *        to UTF-8 as it streams, so every value handed to the mapper is
     *        valid UTF-8 regardless of the vendor's source encoding. A UTF-8
     *        file is detected as UTF-8, which makes that conversion a no-op.
     *
     * @param string $fileType 'xlsx' | 'csv'
     * @param string $localPath local path of the file being read (used to
     *                          detect the CSV source encoding)
     * @return ReaderInterface
     *
     * @throws \InvalidArgumentException For unsupported file types.
     * @throws \App\Exceptions\UnsupportedCsvEncodingException
     */
    public function makeReader(string $fileType, string $localPath): ReaderInterface
    {
        return match ($fileType) {
            'csv' => (function () use ($localPath) {
                $options = new CsvOptions();
                $options->FIELD_DELIMITER = ',';
                $options->FIELD_ENCLOSURE = '"';
                $options->ENCODING = $this->encodingDetector->detect($localPath);

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
    public function extractRowCells(object $row): array
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
