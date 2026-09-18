<?php

namespace App\Services\Catalog;

use App\Exceptions\UnsupportedCsvEncodingException;

/**
 * Decides how a vendor CSV must be decoded before it enters the ETL pipeline.
 *
 * This is the single encoding boundary for the catalog import: both the
 * upload-time inspection (CatalogFileInspectionService) and the background
 * import (ProcessCatalogUploadJob) ask this class which OpenSpout encoding to
 * use, so encoding logic is never duplicated and invalid bytes can never reach
 * Livewire JSON, the database or application-level data structures.
 *
 * Only two outcomes are possible:
 *
 *   UTF-8          - the whole byte stream is valid UTF-8 (BOM or not). The
 *                    stream is never rewritten or converted, so every valid
 *                    Unicode character is preserved exactly.
 *   Windows-1252   - the stream is not valid UTF-8, but every byte in it is a
 *                    defined Windows-1252 byte and no valid UTF-8 multibyte
 *                    sequence occurs anywhere. OpenSpout then transcodes each
 *                    cell losslessly (TM, EUR, curly quotes, en/em dashes,
 *                    accented characters).
 *
 * Anything else is rejected with an UnsupportedCsvEncodingException: UTF-16 /
 * UTF-32, binary data, mixed encoding, and malformed byte streams. Nothing is
 * ever substituted, truncated or dropped with //IGNORE or a replacement
 * character.
 *
 * Decision order is fixed and deterministic (never mb_detect_encoding(), which
 * depends on a global detection order and only guesses):
 *
 *   1. BOM detection            (UTF-8 BOM accepted; UTF-16/32 rejected)
 *   2. NUL detection            (binary / UTF-16 without BOM)
 *   3. strict whole-file UTF-8  (no invalid byte anywhere)
 *   4. mixed / malformed        (valid UTF-8 + stray bytes; undefined bytes)
 *   5. Windows-1252 fallback    (only when the safety rules above hold)
 *
 * Detection streams the whole file in bounded chunks. It never loads the file
 * into memory and never samples only a prefix, so a stray byte far into a large
 * file is still found.
 */
class CsvEncodingDetector
{
    public const ENCODING_UTF8 = 'UTF-8';

    public const ENCODING_WINDOWS_1252 = 'Windows-1252';

    /** Bounded read size: memory stays O(chunk), independent of file size. */
    private const CHUNK_SIZE = 1048576;

    private const UTF8_BOM = "\xEF\xBB\xBF";

    private const UTF16_LE_BOM = "\xFF\xFE";

    private const UTF16_BE_BOM = "\xFE\xFF";

    private const UTF32_LE_BOM = "\xFF\xFE\x00\x00";

    private const UTF32_BE_BOM = "\x00\x00\xFE\xFF";

    /**
     * The five C1 bytes Windows-1252 leaves undefined. iconv() cannot convert
     * them, so a stream containing them is not safely classifiable.
     */
    private const UNDEFINED_CP1252_C1 = [0x81, 0x8D, 0x8F, 0x90, 0x9D];

    /**
     * @param  int  $chunkSize  Bytes per read. Overridable so tests can force
     *                          UTF-8 sequences to be split across chunk
     *                          boundaries.
     */
    public function __construct(
        private readonly int $chunkSize = self::CHUNK_SIZE,
    ) {}

    /**
     * @return string self::ENCODING_UTF8 or self::ENCODING_WINDOWS_1252
     *
     * @throws UnsupportedCsvEncodingException When the file cannot be safely
     *                                         normalised to UTF-8.
     */
    public function detect(string $localPath): string
    {
        $handle = @fopen($localPath, 'rb');

        if ($handle === false) {
            throw UnsupportedCsvEncodingException::unreadable();
        }

        try {
            return $this->detectFromStream($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     *
     * @throws UnsupportedCsvEncodingException
     */
    private function detectFromStream($handle): string
    {
        $flags = [
            'nul' => false,
            'invalid' => false,
            'validMultibyte' => false,
            'undefinedC1' => false,
        ];

        $carry = '';
        $isFirstChunk = true;
        $declaredUtf8 = false;

        while (! feof($handle)) {
            $chunk = fread($handle, $this->chunkSize);

            if ($chunk === false || $chunk === '') {
                break;
            }

            if ($isFirstChunk) {
                $isFirstChunk = false;
                $this->assertSupportedBom($chunk);
                $declaredUtf8 = str_starts_with($chunk, self::UTF8_BOM);
            }

            $carry = $this->scanBuffer($carry.$chunk, $flags);
        }

        // A partial sequence left over at EOF is a truncated sequence.
        if ($carry !== '') {
            $flags['invalid'] = true;
        }

        return $this->decide($flags, $declaredUtf8);
    }

    /**
     * Reject UTF-16 / UTF-32 outright. Longest BOMs are not tested first here
     * because all of them lead to the same rejection.
     *
     * @throws UnsupportedCsvEncodingException
     */
    private function assertSupportedBom(string $chunk): void
    {
        foreach ([
            self::UTF32_LE_BOM,
            self::UTF32_BE_BOM,
            self::UTF16_LE_BOM,
            self::UTF16_BE_BOM,
        ] as $bom) {
            if (str_starts_with($chunk, $bom)) {
                throw UnsupportedCsvEncodingException::unicodeFormat();
            }
        }
    }

    /**
     * Walk one buffer of bytes, recording the four decision flags.
     *
     * Bytes that can never affect the decision (plain ASCII) are skipped in C
     * via a single PCRE scan, so large, mostly-ASCII files stay fast.
     *
     * @param  array<string,bool>  $flags
     * @return string Bytes carried into the next chunk (0-3), holding an
     *                incomplete multibyte sequence so it is validated as one
     *                sequence rather than as two broken pieces.
     */
    private function scanBuffer(string $buffer, array &$flags): string
    {
        $length = strlen($buffer);

        if ($length === 0) {
            return '';
        }

        $offset = 0;

        while (true) {
            // Jump straight to the next interesting byte (NUL or >= 0x80).
            if (preg_match('/[\x00\x80-\xFF]/', $buffer, $match, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                return '';
            }

            $offset = $match[0][1];
            $byte = ord($buffer[$offset]);

            if ($byte === 0x00) {
                $flags['nul'] = true;
                $offset++;
                continue;
            }

            $sequenceLength = match (true) {
                $byte >= 0xC2 && $byte <= 0xDF => 2,
                $byte >= 0xE0 && $byte <= 0xEF => 3,
                $byte >= 0xF0 && $byte <= 0xF4 => 4,
                default => 0,
            };

            // Not a valid UTF-8 lead byte: lone continuation, 0xC0/0xC1 or 0xF5-0xFF.
            if ($sequenceLength === 0) {
                $flags['invalid'] = true;

                if ($byte >= 0x80 && $byte <= 0x9F && in_array($byte, self::UNDEFINED_CP1252_C1, true)) {
                    $flags['undefinedC1'] = true;
                }

                $offset++;
                continue;
            }

            // Incomplete at the end of this chunk: carry it forward (< 4 bytes).
            if ($offset + $sequenceLength > $length) {
                return substr($buffer, $offset);
            }

            if (mb_check_encoding(substr($buffer, $offset, $sequenceLength), 'UTF-8')) {
                $flags['validMultibyte'] = true;
                $offset += $sequenceLength;
            } else {
                // Complete but invalid: overlong, surrogate or bad continuation.
                $flags['invalid'] = true;
                $offset++;
            }
        }
    }

    /**
     * @param  array<string,bool>  $flags
     *
     * @throws UnsupportedCsvEncodingException
     */
    private function decide(array $flags, bool $declaredUtf8): string
    {
        if ($flags['nul']) {
            throw UnsupportedCsvEncodingException::binaryData();
        }

        if ($declaredUtf8) {
            if ($flags['invalid']) {
                throw UnsupportedCsvEncodingException::mixedEncoding();
            }

            return self::ENCODING_UTF8;
        }

        // Strict whole-file UTF-8 (plain ASCII is a valid subset).
        if (! $flags['invalid']) {
            return self::ENCODING_UTF8;
        }

        // Valid UTF-8 coexisting with stray bytes: genuinely mixed.
        if ($flags['validMultibyte']) {
            throw UnsupportedCsvEncodingException::mixedEncoding();
        }

        if ($flags['undefinedC1']) {
            throw UnsupportedCsvEncodingException::undefinedCharacters();
        }

        // Not UTF-8, no valid multibyte sequence, every byte defined in
        // Windows-1252 => safe, lossless single-byte legacy input.
        return self::ENCODING_WINDOWS_1252;
    }
}