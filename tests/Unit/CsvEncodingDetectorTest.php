<?php

namespace Tests\Unit;

use App\Exceptions\UnsupportedCsvEncodingException;
use App\Services\Catalog\CsvEncodingDetector;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CsvEncodingDetector unit tests.
 *
 * The detector is the single encoding boundary for the CSV import pipeline:
 * UTF-8 (with or without BOM) passes through untouched, Windows-1252 is
 * transcoded losslessly by OpenSpout, and everything else is rejected rather
 * than silently corrupted.
 */
class CsvEncodingDetectorTest extends TestCase
{
    private CsvEncodingDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new CsvEncodingDetector;
    }

    #[Test]
    public function ascii_only_csv_is_utf8(): void
    {
        $this->assertEncoding(CsvEncodingDetector::ENCODING_UTF8, "id,name\n1,Widget\n");
    }

    #[Test]
    public function valid_utf8_multibyte_characters_are_utf8(): void
    {
        // é (2-byte), ™ € (3-byte), curly quotes, en/em dashes, emoji (4-byte).
        $this->assertEncoding(CsvEncodingDetector::ENCODING_UTF8, "id,name\n1,café™€ “smart” – — 🎉\n");
    }

    #[Test]
    public function utf8_bom_is_detected_as_utf8(): void
    {
        // The BOM alone must not be treated as evidence the file needs
        // conversion — the body is validated as UTF-8 and reported as UTF-8.
        $this->assertEncoding(CsvEncodingDetector::ENCODING_UTF8, "\xEF\xBB\xBFid,name\n1,café™\n");
    }

    #[Test]
    public function windows_1252_trademark_byte_is_windows_1252(): void
    {
        // The exact failure case from production: ™ encoded as 0x99.
        $this->assertEncoding(
            CsvEncodingDetector::ENCODING_WINDOWS_1252,
            "id,name\n1,Curity\x99 USP Type VII\n"
        );
    }

    #[Test]
    public function windows_1252_special_characters_are_windows_1252(): void
    {
        // € (0x80), ’ (0x92), “ (0x93), ” (0x94), – (0x96), — (0x97),
        // ™ (0x99), é (0xE9), ® (0xAE), ° (0xB0). Space-separated so no
        // adjacent bytes accidentally form a valid UTF-8 sequence.
        $this->assertEncoding(
            CsvEncodingDetector::ENCODING_WINDOWS_1252,
            "id,name\n1,\x80 \x92 \x93 \x94 \x96 \x97 \x99 \xE9z \xAE \xB0\n"
        );
    }

    #[Test]
    public function iso_8859_1_style_file_resolves_to_windows_1252(): void
    {
        // Only bytes in the shared 0xA0-0xFF range: the two encodings decode
        // identically there, so the Windows-1252 label is safe (not ambiguous
        // in outcome).
        $this->assertEncoding(
            CsvEncodingDetector::ENCODING_WINDOWS_1252,
            "id,name\n1,caf\xE9 r\xE9sum\xE9 5\xB0\n"
        );
    }

    #[Test]
    public function cp1252_bytes_are_not_mistaken_for_utf8_sequences(): void
    {
        // Byte pairs that superficially resemble UTF-8 (a lead byte in the
        // 0xC2-0xF4 range followed by a non-continuation byte) are not valid
        // UTF-8: 0xC2a, 0xE0A, 0xF0B, the overlong lead 0xC1 0x80, the lone
        // continuation byte 0x80, and 0xF5. No valid multibyte sequence
        // exists, so this is safely CP1252.
        $this->assertEncoding(
            CsvEncodingDetector::ENCODING_WINDOWS_1252,
            "id,name\n1,\xC2a \xE0A \xF0B \xC1\x80 \x80 \xF5\n"
        );
    }

    #[Test]
    public function a_mixed_file_with_valid_utf8_and_a_cp1252_byte_is_rejected(): void
    {
        // Valid UTF-8 text + one stray byte cannot be confidently normalized.
        foreach (["\x99", "\xFF"] as $stray) {
            $path = $this->writeCsv("id,name\n1,café{$stray} widget\n");

            try {
                $this->detector->detect($path);
                $this->fail('Expected UnsupportedCsvEncodingException for stray byte '.bin2hex($stray));
            } catch (UnsupportedCsvEncodingException $e) {
                $this->assertSame(UnsupportedCsvEncodingException::REASON_MIXED, $e->reason());
            }
        }
    }

    #[Test]
    public function undefined_cp1252_c1_bytes_are_rejected(): void
    {
        foreach ([0x81, 0x8D, 0x8F, 0x90, 0x9D] as $byte) {
            $path = $this->writeCsv("id,name\n1,bad".chr($byte)." byte\n");

            try {
                $this->detector->detect($path);
                $this->fail('Expected UnsupportedCsvEncodingException for C1 byte '.dechex($byte));
            } catch (UnsupportedCsvEncodingException $e) {
                $this->assertSame(UnsupportedCsvEncodingException::REASON_UNDEFINED, $e->reason());
            }
        }
    }

    #[Test]
    public function a_utf8_bom_file_with_corrupt_body_is_rejected(): void
    {
        $path = $this->writeCsv("\xEF\xBB\xBFid,name\n1,bad\x99 byte\n");

        try {
            $this->detector->detect($path);
            $this->fail('Expected UnsupportedCsvEncodingException');
        } catch (UnsupportedCsvEncodingException $e) {
            $this->assertSame(UnsupportedCsvEncodingException::REASON_MIXED, $e->reason());
        }
    }

    #[Test]
    public function utf16_and_utf32_bom_files_are_rejected_as_unicode(): void
    {
        $boms = [
            "\xFF\xFE",            // UTF-16 LE
            "\xFE\xFF",            // UTF-16 BE
            "\xFF\xFE\x00\x00",    // UTF-32 LE
            "\x00\x00\xFE\xFF",    // UTF-32 BE
        ];

        foreach ($boms as $bom) {
            $path = $this->writeCsv($bom."i\x00d\x00,\x00n\x00a\x00m\x00e\x00\n\x001\x00");

            try {
                $this->detector->detect($path);
                $this->fail('Expected UnsupportedCsvEncodingException for BOM '.bin2hex($bom));
            } catch (UnsupportedCsvEncodingException $e) {
                $this->assertSame(UnsupportedCsvEncodingException::REASON_UNICODE, $e->reason());
            }
        }
    }

    #[Test]
    public function utf16_without_bom_is_rejected_as_binary(): void
    {
        // NUL bytes are valid UTF-8, so the NUL check must run before UTF-8
        // acceptance — this file would otherwise be misread as UTF-8.
        $path = $this->writeCsv("i\x00d\x00,\x00n\x00a\x00m\x00e\x00\n\x001\x00,\x00W\x00i\x00d\x00g\x00e\x00t\x00\n");

        try {
            $this->detector->detect($path);
            $this->fail('Expected UnsupportedCsvEncodingException');
        } catch (UnsupportedCsvEncodingException $e) {
            $this->assertSame(UnsupportedCsvEncodingException::REASON_BINARY, $e->reason());
        }
    }

    #[Test]
    public function binary_data_is_rejected(): void
    {
        $path = $this->writeCsv("\x50\x4B\x03\x04\x00\x00\x00\x00");

        try {
            $this->detector->detect($path);
            $this->fail('Expected UnsupportedCsvEncodingException');
        } catch (UnsupportedCsvEncodingException $e) {
            $this->assertSame(UnsupportedCsvEncodingException::REASON_BINARY, $e->reason());
        }
    }

    #[Test]
    public function an_unreadable_file_is_rejected(): void
    {
        $this->expectException(UnsupportedCsvEncodingException::class);

        $this->detector->detect(sys_get_temp_dir().'/does-not-exist-'.bin2hex(random_bytes(4)));
    }

    #[Test]
    public function detection_scans_the_whole_file_not_just_a_prefix(): void
    {
        // Non-ASCII bytes appear only after 2 MB of ASCII. A prefix sampler
        // would report UTF-8 (the real vendor file's first 4 KB is ASCII).
        $body = "id,name\n1,Widget\n".str_repeat('x', 2 * 1024 * 1024)."\x99\n";

        $this->assertEncoding(CsvEncodingDetector::ENCODING_WINDOWS_1252, $body);
    }

    #[Test]
    public function utf8_sequences_split_across_chunk_boundaries_are_still_detected(): void
    {
        // Small chunk size forces every multibyte sequence to straddle a
        // boundary. The <=3-byte carry must join the halves into one
        // sequence, or the file would be misclassified as mixed/CP1252.
        $detector = new CsvEncodingDetector(chunkSize: 8);

        $cases = [
            '2-byte e-acute' => "caf\xC3\xA9\n1,x\n",
            '3-byte TM EUR' => "\xE2\x84\xA2\xE2\x82\xAC\n1,x\n",
            '3-byte dashes quotes' => "\xE2\x80\x93\xE2\x80\x9C\xE2\x80\x9D\n1,x\n",
            '4-byte emoji' => "\xF0\x9F\x8E\x89\n1,x\n",
        ];

        foreach ($cases as $label => $body) {
            $this->assertSame(
                CsvEncodingDetector::ENCODING_UTF8,
                $detector->detect($this->writeCsv($body)),
                "Failed for case: {$label}"
            );
        }
    }

    #[Test]
    public function a_truncated_utf8_sequence_at_eof_falls_back_when_cp1252_defined(): void
    {
        // \xC3 with no continuation byte: the carry reaches EOF with an
        // incomplete sequence. 0xC3 is a defined CP1252 byte, so the stream
        // falls through to Windows-1252 rather than being labelled UTF-8.
        $detector = new CsvEncodingDetector(chunkSize: 4);

        $this->assertEncoding(
            CsvEncodingDetector::ENCODING_WINDOWS_1252,
            "id,name\n1,caf\xC3\n",
            $detector
        );
    }

    #[Test]
    public function a_4_byte_sequence_split_at_a_boundary_is_validated_as_one(): void
    {
        // 0xF0 0x9F 0x92 0xA9 is only valid when the halves are rejoined. If
        // the carry were dropped, the first chunk would flag an invalid
        // sequence and the file would be rejected as mixed.
        $detector = new CsvEncodingDetector(chunkSize: 2);

        $this->assertEncoding(
            CsvEncodingDetector::ENCODING_UTF8,
            "id,name\n1,\xF0\x9F\x92\xA9\n",
            $detector
        );
    }

    #[Test]
    public function classification_is_deterministic(): void
    {
        // Same input twice -> same answer (no global detection order).
        // é is CP1252-encoded here (0xE9), not UTF-8 (0xC3 0xA9), so the
        // stream has no valid multibyte sequence.
        $path = $this->writeCsv("id,name\n1,caf\xE9\x99\n");

        $this->assertSame(
            $this->detector->detect($path),
            $this->detector->detect($path)
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function writeCsv(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_detect_test_');

        file_put_contents($path, $bytes);

        register_shutdown_function(function () use ($path) {
            @unlink($path);
        });

        return $path;
    }

    private function assertEncoding(string $expected, string $bytes, ?CsvEncodingDetector $detector = null): void
    {
        $detected = ($detector ?? $this->detector)->detect($this->writeCsv($bytes));

        $this->assertSame($expected, $detected);
    }
}

