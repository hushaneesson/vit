<?php

namespace Tests\Feature;

use App\Exceptions\UnsupportedCsvEncodingException;
use App\Jobs\ProcessCatalogUploadJob;
use App\Models\Catalog;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
use App\Models\Client;
use App\Models\Vendor;
use App\Services\Catalog\CatalogFileInspectionService;
use App\Services\Catalog\OpenSpoutRowReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CSV encoding boundary tests at the application level.
 *
 * Verifies that:
 *  - Windows-1252 vendor CSVs are transcoded to valid UTF-8 by the inspection
 *    service (the data that reaches Livewire state / the mapper preview),
 *  - UTF-8 BOMs are stripped and never become part of the first header,
 *  - un-normalizable files are rejected with a vendor-facing error,
 *  - the background staging job writes valid UTF-8 raw_data.
 */
class CsvEncodingInspectionTest extends TestCase
{
    use RefreshDatabase;

    protected Vendor $vendor;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();
        $this->client = Client::factory()->create(['vendor_id' => $this->vendor->id]);
        Catalog::create(['vendor_id' => $this->vendor->id, 'name' => 'Main']);

        Storage::fake('local');
    }

    #[Test]
    public function a_real_windows_1252_csv_is_inspected_as_valid_utf8(): void
    {
        // The real vendor file that produced the production failure.
        $realPath = 'storage/app/testing/Sample VMM catalog for ETL.csv';

        $this->assertTrue(file_exists(base_path($realPath)), 'Sample fixture must exist.');

        $bytes = file_get_contents(base_path($realPath));

        $this->assertStringContainsString("\x99", $bytes, 'Fixture should contain the CP1252 TM byte.');
        $this->assertStringNotContainsString("\xEF\xBB\xBF", $bytes, 'Fixture should have no BOM.');
        $this->assertFalse(mb_check_encoding($bytes, 'UTF-8'), 'Fixture should not be valid UTF-8.');

        $this->putCsv($bytes);

        $inspection = app(CatalogFileInspectionService::class)->inspect('local', 'catalog-uploads/test.csv', 'csv');

        $this->assertNotEmpty($inspection['columns']);
        $this->assertNotEmpty($inspection['sample_rows']);

        // Every value reaching the mapper/Livewire state must be valid UTF-8.
        $allValues = array_merge($inspection['columns'], ...$inspection['sample_rows']);

        foreach ($allValues as $value) {
            $this->assertTrue(
                mb_check_encoding((string) $value, 'UTF-8'),
                'Non-UTF-8 value reached the mapper: '.bin2hex(substr((string) $value, 0, 32))
            );
        }

        // The trademark byte must be transcoded losslessly to ™ (U+2122).
        $containsTrademark = false;

        foreach ($inspection['sample_rows'] as $row) {
            foreach ($row as $value) {
                if (str_contains((string) $value, "\u{2122}")) {
                    $containsTrademark = true;
                }
            }
        }

        $this->assertTrue($containsTrademark, 'The ™ character was not preserved through transcode.');
    }


    #[Test]
    public function a_utf8_bom_is_not_part_of_the_first_header(): void
    {
        $this->putCsv("\xEF\xBB\xBFid,name\xE2\x84\xA2\n1,Widget™\n");

        $inspection = app(CatalogFileInspectionService::class)->inspect('local', 'catalog-uploads/test.csv', 'csv');

        $this->assertSame('id', $inspection['columns'][0]);
        $this->assertSame('name™', $inspection['columns'][1]);
    }

    #[Test]
    public function a_plain_ascii_csv_behaves_unchanged(): void
    {
        $this->putCsv("id,name\n1,Widget\n2,Gadget\n");

        $inspection = app(CatalogFileInspectionService::class)->inspect('local', 'catalog-uploads/test.csv', 'csv');

        $this->assertSame(['id', 'name'], $inspection['columns']);
        $this->assertSame('Widget', $inspection['sample_rows'][0][1]);
    }

    #[Test]
    public function a_mixed_encoding_csv_is_rejected(): void
    {
        // Valid UTF-8 text + one stray CP1252 byte cannot be normalized.
        $this->putCsv("id,name\n1,café\x99 widget\n");

        try {
            app(CatalogFileInspectionService::class)->inspect('local', 'catalog-uploads/test.csv', 'csv');
            $this->fail('Expected UnsupportedCsvEncodingException.');
        } catch (UnsupportedCsvEncodingException $e) {
            $this->assertSame(UnsupportedCsvEncodingException::REASON_MIXED, $e->reason());
            // The message must be vendor-safe: no PHP/OpenSpout internals.
            $this->assertStringContainsString('CSV UTF-8', $e->getMessage());
        }
    }

    #[Test]
    public function a_utf16_csv_is_rejected(): void
    {
        $this->putCsv("\xFF\xFEi\x00d\x00,\x00n\x00a\x00m\x00e\x00\n\x001\x00");

        try {
            app(CatalogFileInspectionService::class)->inspect('local', 'catalog-uploads/test.csv', 'csv');
            $this->fail('Expected UnsupportedCsvEncodingException.');
        } catch (UnsupportedCsvEncodingException $e) {
            $this->assertSame(UnsupportedCsvEncodingException::REASON_UNICODE, $e->reason());
        }
    }

    #[Test]
        public function the_staging_job_writes_valid_utf8_raw_data_for_windows_1252_input(): void
    {
        $catalog = Catalog::where('vendor_id', $this->vendor->id)->firstOrFail();

                $absolutePath = $this->putCsv(
            "id,name,manufacturer\n"
            ."SKU-1,Curity\x99 Gauze 4\xB0,Acme\xAE Tools\n"
            ."SKU-2,S\xE9parateur \x96 dash,\x80 29.99\n"
        );

        // file_path is stored as the absolute local path (the form the job's
        // resolveLocalPath() resolves for local disks), mirroring production's
        // tempnam copy for remote disks.
        $upload = CatalogUpload::create([
            'vendor_id' => $this->vendor->id,
            'client_id' => $this->client->id,
            'catalog_id' => $catalog->id,
            'original_filename' => 'cp1252.csv',
            'file_path' => $absolutePath,
            'disk' => 'local',
            'file_type' => 'csv',
        ]);

        (new ProcessCatalogUploadJob($upload->id))->handle(app(OpenSpoutRowReader::class));

        $rows = CatalogUploadRow::where('catalog_upload_id', $upload->id)->orderBy('row_number')->get();

        $this->assertCount(2, $rows);

        $allCells = $rows->flatMap(fn ($row) => $row->raw_data)->all();

        $this->assertTrue(
            collect($allCells)->every(fn ($cell) => mb_check_encoding((string) $cell, 'UTF-8')),
            'raw_data contains invalid UTF-8.'
        );

        $flat = implode('|', array_map('strval', $allCells));

                $this->assertStringContainsString("Curity\u{2122} Gauze 4\u{B0}", $flat);
        $this->assertStringContainsString('Acme® Tools', $flat);
                // ™ -> U+2122 (\xE2\x84\xA2) contains no 0x99 byte, so its absence
        // plus the positive UTF-8 checks above confirm a clean transcode.
        $this->assertStringNotContainsString("\x99", $flat);
    }

        // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function putCsv(string $bytes): string
    {
        Storage::disk('local')->put('catalog-uploads/test.csv', $bytes);

        // Resolve to the absolute on-disk path. This mirrors how
        // CatalogFileInspectionService::resolveLocalPath() turns a local-disk
        // relative path into an absolute one, and how the job copies remote
        // files to a tempnam — i.e. OpenSpout always sees a real local file.
        return Storage::disk('local')->path('catalog-uploads/test.csv');
    }
}

