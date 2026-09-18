<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a vendor CSV cannot be safely normalised to UTF-8.
 *
 * Every factory returns a message that is safe to show to a vendor: it never
 * leaks raw PHP, iconv, OpenSpout or JSON internals.
 */
class UnsupportedCsvEncodingException extends RuntimeException
{
    public const REASON_UNICODE = 'unicode';

    public const REASON_MIXED = 'mixed';

    public const REASON_UNDEFINED = 'undefined';

    public const REASON_BINARY = 'binary';

    public const REASON_UNREADABLE = 'unreadable';

    private function __construct(
        private readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * UTF-16 / UTF-32 input. OpenSpout can decode it, but its CSV reader
     * silently mis-parses quoted fields containing delimiters for multi-byte
     * encodings, so we refuse rather than risk corrupting catalog data.
     */
    public static function unicodeFormat(): self
    {
        return new self(
            self::REASON_UNICODE,
            'This file is saved in a Unicode (UTF-16 or UTF-32) format, which isn\'t supported. '
            .'Please open it in Excel and save it as "CSV UTF-8 (Comma delimited)", then upload it again.'
        );
    }

    /**
     * The byte stream mixes valid UTF-8 with bytes from another encoding.
     */
    public static function mixedEncoding(): self
    {
        return new self(
            self::REASON_MIXED,
            'This file mixes more than one text encoding, so we couldn\'t read its characters reliably. '
            .'Please save it as "CSV UTF-8 (Comma delimited)" and upload it again.'
        );
    }

    /**
     * The stream contains bytes that are not representable in UTF-8 or
     * Windows-1252 (e.g. undefined CP1252 control bytes).
     */
    public static function undefinedCharacters(): self
    {
        return new self(
            self::REASON_UNDEFINED,
            'This file contains characters that aren\'t valid UTF-8 or Windows-1252, '
            .'so we couldn\'t read it reliably. Please save it as "CSV UTF-8 (Comma delimited)" and upload it again.'
        );
    }

    /**
     * Binary content (NUL bytes) — e.g. UTF-16 without a BOM, or a non-CSV file.
     */
    public static function binaryData(): self
    {
        return new self(
            self::REASON_BINARY,
            'This file doesn\'t look like a text CSV file (it contains binary data). '
            .'Please upload a CSV, XLSX or XLS file, saved as "CSV UTF-8 (Comma delimited)".'
        );
    }

    /**
     * The stored file could not be opened for reading.
     */
    public static function unreadable(): self
    {
        return new self(
            self::REASON_UNREADABLE,
            'We couldn\'t read the uploaded file. Please try uploading it again.'
        );
    }
}
