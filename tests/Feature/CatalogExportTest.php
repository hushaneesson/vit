<?php

namespace Tests\Feature;

use App\Enums\CatalogSubmissionStatus;
use App\Enums\CatalogUploadStatus;
use App\Jobs\ProcessValidatedRowsJob;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\CatalogSubmission;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadColumnMapping;
use App\Models\CatalogUploadRow;
use App\Models\Client;
use App\Models\CommodityType;
use App\Models\ProductHierarchy;
use App\Models\UnitOfMeasure;
use App\Models\Vendor;
use App\Services\Catalog\CatalogExportService;
use App\Services\Catalog\CatalogItemProcessor;
use App\Services\Catalog\CatalogRowValidator;
use App\Services\VitFieldDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * VIT catalog export tests.
 *
 * These tests drive the real export path: database CatalogItems ->
 * CatalogExportService::generateFromSubmission() -> CSV file on the local
 * disk -> read back and verified against the stored data. The final test
 * covers the full round trip: mapped import -> database -> export -> CSV.
 */
class CatalogExportTest extends TestCase
{
    use RefreshDatabase;

    protected Vendor $vendor;
    protected Client $client;
    protected Catalog $catalog;
    protected CatalogSubmission $submission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create(['name' => 'Acme Supply']);
        $this->client = Client::factory()->create(['vendor_id' => $this->vendor->id]);
        $this->catalog = Catalog::create(['vendor_id' => $this->vendor->id, 'name' => 'Main']);

        $this->submission = CatalogSubmission::create([
            'vendor_id' => $this->vendor->id,
            'catalog_id' => $this->catalog->id,
            'requested_by_client_id' => $this->client->id,
            'status' => CatalogSubmissionStatus::ReadyForReview,
            'total_items' => 0,
            'requested_at' => now(),
            'disk' => 'local',
        ]);
    }

    /**
     * Create an eligible (acceptable) catalog item with realistic data.
     * Fields left out stay empty and only affect the completeness score.
     */
    private function createItem(string $sku, array $overrides = []): CatalogItem
    {
        $data = array_merge([
            'vendor_id' => $this->vendor->id,
            'catalog_id' => $this->catalog->id,
            'dealer_sku' => $sku,
            'name' => 'Default Item',
            'description' => 'Default description',
            'manufacturer_sku' => 'MFR-1',
            'manufacturer' => 'Acme Mfg',
            'unit_of_measure' => \App\Models\UnitOfMeasure::where('code', 'EA')->first()->id,
            'quantity_per_unit' => 1,
            'item_weight' => 1.5,
            'selling_price' => 9.99,
            'specifications' => [
                ['key' => 'Color', 'value' => 'White'],
            ],
            'selling_points' => ['Durable'],
            'category' => CommodityType::where('name', 'Safety')->first()->id,
            'hierarchy' => ProductHierarchy::where('level', 3)->first()->id,
        ], $overrides);

        return CatalogItem::create($data);
    }

    /**
     * Generate the export for the submission and return
     * [headers, rows] read back from the generated CSV file.
     *
     * @return array{headers: list<string>, rows: list<list<string>>, path: string}
     */
    private function generateAndRead(): array
    {
        $path = (new CatalogExportService)->generateFromSubmission($this->submission);

        $this->assertNotNull($path);
        $this->assertTrue(Storage::disk('local')->exists($path), 'The generated CSV must exist on the disk.');

        $handle = fopen(Storage::disk('local')->path($path), 'rb');
        $rows = [];
        $first = true;
        while (($row = fgetcsv($handle)) !== false) {
            // OpenSpout writes a UTF-8 BOM at the start of the file; strip it
            // from the first header cell so values can be compared verbatim.
            if ($first && isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                $first = false;
            }
            $rows[] = $row;
        }
        fclose($handle);

        $this->assertNotEmpty($rows, 'The CSV must contain at least a header row.');

        return [
            'headers' => $rows[0],
            'rows' => array_slice($rows, 1),
            'path' => $path,
        ];
    }

    /**
     * Look up the value of one header column in one data row.
     */
    private function columnValue(array $headers, array $row, string $header): string
    {
        $index = array_search($header, $headers, true);
        $this->assertNotFalse($index, "Header '$header' must exist in the exported CSV.");

        return $row[$index] ?? '';
    }

    // ------------------------------------------------------------------
    // Export generation, headers, values
    // ------------------------------------------------------------------

    public function test_export_creates_a_csv_file(): void
    {
        $this->createItem('SKU-EXP-1');

        $result = $this->generateAndRead();

        $this->assertStringEndsWith('.csv', $result['path']);
        $this->assertCount(1, $result['rows'], 'One eligible item must produce exactly one data row.');
    }

    public function test_export_uses_current_vit_headers(): void
    {
        $this->createItem('SKU-EXP-H');

        $result = $this->generateAndRead();

        // Headers must exactly match the current VitFieldDefinition export set.
        $expected = VitFieldDefinition::exportableFields()
            ->pluck('vit_csv_column')
            ->map(fn ($c) => (string) $c)
            ->all();

        $this->assertSame($expected, $result['headers']);

        // Spot-check the important current headers.
        $this->assertContains('Category', $result['headers']);
        $this->assertContains('Protected Search Terms', $result['headers']);
        $this->assertContains('name', $result['headers']);
        $this->assertContains('specifications', $result['headers']);
        $this->assertContains('classifications', $result['headers']);

        // Obsolete headers must be gone.
        $this->assertNotContains('Product Commodity Type', $result['headers']);
        $this->assertNotContains('Title Search Terms', $result['headers']);
    }

    public function test_important_field_values_appear_in_correct_columns(): void
    {
        $this->createItem('SKU-EXP-V', [
            'name' => 'Pro Hammer',
            'description' => 'Steel claw hammer',
            'brand_name' => 'Acme',
            'list_price' => 29.99,
            'item_weight' => 2.25,
        ]);

        $result = $this->generateAndRead();
        $row = $result['rows'][0];

        $this->assertSame('Pro Hammer', $this->columnValue($result['headers'], $row, 'name'));
        $this->assertSame('Steel claw hammer', $this->columnValue($result['headers'], $row, 'description'));
        $this->assertSame('Acme Supply', $this->columnValue($result['headers'], $row, 'vendor'));
        $this->assertSame('SKU-EXP-V', $this->columnValue($result['headers'], $row, 'dealer sku'));
        $this->assertSame('Acme', $this->columnValue($result['headers'], $row, 'brandName'));
        $this->assertSame('29.99', $this->columnValue($result['headers'], $row, 'list price'));
        $this->assertSame('2.25', $this->columnValue($result['headers'], $row, 'item weight'));
        $this->assertSame('ELINK', $this->columnValue($result['headers'], $row, 'type'));
        // category is stored as a CommodityType id; the export must resolve
        // it back to the human-readable name.
        $this->assertSame('Safety', $this->columnValue($result['headers'], $row, 'Category'));
    }

    public function test_name_is_exported_exactly_without_quantity_or_uom_appended(): void
    {
        $this->createItem('SKU-EXP-N', [
            'name' => 'Safety Gloves',
            'quantity_per_unit' => 12,
            'unit_of_measure' => \App\Models\UnitOfMeasure::where('code', 'EA')->first()->id,
        ]);

        $result = $this->generateAndRead();

        $name = $this->columnValue($result['headers'], $result['rows'][0], 'name');

        $this->assertSame('Safety Gloves', $name, 'Name must be exported verbatim — no quantity/UOM suffix.');
        $this->assertStringNotContainsString('12', $name);
        $this->assertStringNotContainsString('EA', $name);
    }

    public function test_specifications_are_exported_as_key_value_pairs(): void
    {
        $this->createItem('SKU-EXP-S', [
            'specifications' => [
                ['key' => 'Color', 'value' => 'White'],
                ['key' => 'Size', 'value' => 'Large'],
            ],
        ]);

        $result = $this->generateAndRead();

        $specs = $this->columnValue($result['headers'], $result['rows'][0], 'specifications');

        $this->assertSame('Color=White|Size=Large', $specs);
    }

    public function test_classifications_are_exported_as_key_value_pairs(): void
    {
        $this->createItem('SKU-EXP-C', [
            'classifications' => [
                ['key' => 'Country of Origin', 'value' => 'AX'],
                ['key' => 'UNSPSC', 'value' => '46181504'],
                ['key' => 'EPP', 'value' => 'Y'],
            ],
        ]);

        $result = $this->generateAndRead();

        $classifications = $this->columnValue($result['headers'], $result['rows'][0], 'classifications');

        $parts = explode('|', $classifications);

        $this->assertContains('Country of Origin=AX', $parts);
        $this->assertContains('UNSPSC=46181504', $parts);
        $this->assertContains('EPP=Y', $parts);
        $this->assertCount(3, $parts);
    }

    public function test_multi_value_fields_export_with_configured_separators(): void
    {
        $this->createItem('SKU-EXP-M', [
            'search_terms' => ['paper', 'laser paper', 'office paper'],
            'selling_points' => ['Durable', 'Lightweight'],
            'images' => ['https://example.com/a.jpg', 'https://example.com/b.jpg'],
        ]);

        $result = $this->generateAndRead();
        $row = $result['rows'][0];

        // search_terms: comma separator ("Protected Search Terms").
        $this->assertSame(
            'paper,laser paper,office paper',
            $this->columnValue($result['headers'], $row, 'Protected Search Terms')
        );

        // selling_points / image_urls: pipe separator.
        $this->assertSame(
            'Durable|Lightweight',
            $this->columnValue($result['headers'], $row, 'selling points')
        );
        $this->assertSame(
            'https://example.com/a.jpg|https://example.com/b.jpg',
            $this->columnValue($result['headers'], $row, 'image URLs')
        );
    }

    public function test_csv_special_characters_round_trip_correctly(): void
    {
        $this->createItem('SKU-EXP-CSV', [
            'name' => 'Gloves, Large "Heavy Duty" 5-pack — Ünïcodé ✓',
            'description' => 'Contains, comma "quotes" and unicode: café, naïve, 日本語',
            'specifications' => [
                ['key' => 'Note, with comma', 'value' => 'Value "quoted"'],
            ],
        ]);

        $result = $this->generateAndRead();
        $row = $result['rows'][0];

        // The values must survive the CSV quoting exactly as stored.
        $this->assertSame(
            'Gloves, Large "Heavy Duty" 5-pack — Ünïcodé ✓',
            $this->columnValue($result['headers'], $row, 'name')
        );
        $this->assertSame(
            'Contains, comma "quotes" and unicode: café, naïve, 日本語',
            $this->columnValue($result['headers'], $row, 'description')
        );
        $this->assertSame(
            'Note, with comma=Value "quoted"',
            $this->columnValue($result['headers'], $row, 'specifications')
        );
    }

    public function test_only_eligible_items_are_exported(): void
    {
        // Eligible: acceptable status, never submitted.
        $this->createItem('SKU-EXP-YES');
        // Eligible: excellent status (all fields filled by the factory-less
        // base data + excellent-only fields).
        $this->createItem('SKU-EXP-EXC', [
            'brand_name' => 'Acme',
            'images' => ['https://example.com/a.jpg'],
            'search_terms' => ['hammer'],
            'classifications' => [['key' => 'UNSPSC', 'value' => '46181504']],
            'min_qty_per_order' => 1,
            'max_qty_per_order' => 10,
            'multiples' => 1,
            'list_price' => 15.00,
            'availability' => 999,
            'lead_time' => '3-5 days',
        ]);
        // Ineligible: incomplete (missing required fields).
        $this->createItem('SKU-EXP-INCOMPLETE', [
            'name' => 'Broken Item',
            'description' => null,
            'manufacturer_sku' => null,
            'manufacturer' => null,
            'unit_of_measure' => null,
            'quantity_per_unit' => null,
            'item_weight' => null,
            'selling_price' => null,
            'specifications' => null,
            'selling_points' => null,
        ]);
        // Ineligible: already submitted to VIT.
        $submitted = $this->createItem('SKU-EXP-SUBMITTED');
        $submitted->forceFill(['last_submitted_at' => now()])->save();

        $result = $this->generateAndRead();

        $skus = array_map(
            fn ($row) => $this->columnValue($result['headers'], $row, 'dealer sku'),
            $result['rows']
        );

        $this->assertContains('SKU-EXP-YES', $skus);
        $this->assertContains('SKU-EXP-EXC', $skus);
        $this->assertNotContains('SKU-EXP-INCOMPLETE', $skus, 'Incomplete items must not be exported.');
        $this->assertNotContains('SKU-EXP-SUBMITTED', $skus, 'Already-submitted items must not be re-exported.');
        $this->assertCount(2, $skus);
    }

    // ------------------------------------------------------------------
    // End-to-end round trip: mapped import -> database -> export -> CSV
    // ------------------------------------------------------------------

    public function test_round_trip_mapped_import_to_export_preserves_data(): void
    {
        // --- Step 1: import a small mapped catalog through the real pipeline. ---
        $upload = CatalogUpload::create([
            'vendor_id' => $this->vendor->id,
            'client_id' => $this->client->id,
            'catalog_id' => $this->catalog->id,
            'original_filename' => 'roundtrip.csv',
            'file_path' => 'catalog-uploads/roundtrip.csv',
            'disk' => 'local',
            'file_type' => 'csv',
            'status' => CatalogUploadStatus::Processing,
            'total_rows' => 1,
        ]);

        $mappings = [
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'long_description',
            3 => ['specifications', '|'],
            4 => 'country_of_origin',
            5 => 'unspsc_code',
            6 => 'product_commodity_type',
            7 => 'manufacturer',
            8 => 'manufacturer_sku',
            9 => 'unit_of_measure',
            10 => 'quantity_per_unit',
            11 => 'item_weight_in_pounds',
            12 => 'selling_price',
            13 => ['selling_points', '|'],
            14 => 'hierarchy',
        ];

        foreach ($mappings as $columnIndex => $entry) {
            $fieldKey = is_array($entry) ? $entry[0] : $entry;
            $separator = is_array($entry) ? $entry[1] : null;

            CatalogUploadColumnMapping::create([
                'catalog_upload_id' => $upload->id,
                'field_key' => $fieldKey,
                'column_index' => $columnIndex,
                'source_column_name' => "Column $columnIndex",
                'source_separator' => $separator,
            ]);
        }

        CatalogUploadRow::create([
            'catalog_upload_id' => $upload->id,
            'row_number' => 1,
            'data' => [],
            'raw_data' => [
                'SKU-RT-1',
                'Safety Gloves',
                'Protective work gloves',
                'Color=White|Size=Large',
                'AX',
                '46181504',
                'Safety',
                'Acme Safety Co',
                'MFR-RT-1',
                'EA',
                '12',
                '1.2',
                '9.99',
                'Durable|Cut resistant',
                ProductHierarchy::where('level', 3)->first()->hierarchy_number,
            ],
            'status' => 'valid',
        ]);

        (new ProcessValidatedRowsJob($upload->id, 0, PHP_INT_MAX))->handle(app(CatalogItemProcessor::class), app(CatalogRowValidator::class));

        // Verify the database actually holds the mapped structured data.
        $item = CatalogItem::where('vendor_id', $this->vendor->id)
            ->where('dealer_sku', 'SKU-RT-1')
            ->first();

        $this->assertNotNull($item, 'The mapped import must create the catalog item.');
        $this->assertSame('Safety Gloves', $item->name);
        $this->assertSame(
            [['key' => 'Color', 'value' => 'White'], ['key' => 'Size', 'value' => 'Large']],
            $item->specifications
        );
        $this->assertSame('AX', collect($item->classifications)->firstWhere('key', 'COUNTRY_OF_ORIGIN')['value'] ?? null);
        $this->assertSame('46181504', collect($item->classifications)->firstWhere('key', 'UNSPSC')['value'] ?? null);
        $this->assertContains($item->status, ['acceptable', 'excellent'], 'The imported item must be export-eligible.');

        // --- Step 2: export the catalog and verify the CSV values. ---
        $export = (new CatalogExportService)->generateFromSubmission($this->submission);
        $this->assertNotNull($export);

        $handle = fopen(Storage::disk('local')->path($export), 'rb');
        $rows = [];
        $first = true;
        while (($row = fgetcsv($handle)) !== false) {
            if ($first && isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                $first = false;
            }
            $rows[] = $row;
        }
        fclose($handle);

        $headers = $rows[0];
        $this->assertCount(1, array_slice($rows, 1), 'Exactly one eligible item must be exported.');
        $dataRow = $rows[1];

        $value = fn (string $header) => $dataRow[array_search($header, $headers, true)];

        $this->assertSame('Acme Supply', $value('vendor'));
        $this->assertSame('Main', $value('catalog'));
        $this->assertSame('SKU-RT-1', $value('dealer sku'));
        $this->assertSame('Safety Gloves', $value('name'));
        $this->assertSame('Protective work gloves', $value('description'));
        $this->assertSame('Acme Safety Co', $value('manufacturer'));
        $this->assertSame('MFR-RT-1', $value('manufacturer sku'));
        $this->assertSame('EA', $value('unit of measure'));
        $this->assertSame('1.2', $value('item weight'));
        $this->assertSame('9.99', $value('APDcost'));
        $this->assertSame('Durable|Cut resistant', $value('selling points'));
        $this->assertSame('Color=White|Size=Large', $value('specifications'));
        $this->assertSame('Safety', $value('Category'));

        $classificationParts = explode('|', $value('classifications'));
        $this->assertContains('COUNTRY_OF_ORIGIN=AX', $classificationParts);
        $this->assertContains('UNSPSC=46181504', $classificationParts);
    }

    // ------------------------------------------------------------------
    // Shared helpers for the expanded coverage below
    // ------------------------------------------------------------------

    /**
     * Import one mapped row through the real pipeline and return the
     * resulting CatalogItem (used by the round-trip tests).
     *
     * @param array<int|string, string|array{0: string, 1: string}> $mappings
     */
    private function importMappedRow(array $mappings, array $cells): ?CatalogItem
    {
        $upload = CatalogUpload::create([
            'vendor_id' => $this->vendor->id,
            'client_id' => $this->client->id,
            'catalog_id' => $this->catalog->id,
            'original_filename' => 'rt.csv',
            'file_path' => 'catalog-uploads/rt.csv',
            'disk' => 'local',
            'file_type' => 'csv',
            'status' => CatalogUploadStatus::Processing,
            'total_rows' => 1,
        ]);

        foreach ($mappings as $columnIndex => $entry) {
            $fieldKey = is_array($entry) ? $entry[0] : $entry;
            $separator = is_array($entry) ? $entry[1] : null;

            CatalogUploadColumnMapping::create([
                'catalog_upload_id' => $upload->id,
                'field_key' => $fieldKey,
                'column_index' => $columnIndex,
                'source_column_name' => "Column $columnIndex",
                'source_separator' => $separator,
            ]);
        }

        CatalogUploadRow::create([
            'catalog_upload_id' => $upload->id,
            'row_number' => 1,
            'data' => [],
            'raw_data' => $cells,
            'status' => 'valid',
        ]);

        (new ProcessValidatedRowsJob($upload->id, 0, PHP_INT_MAX))
            ->handle(app(CatalogItemProcessor::class), app(CatalogRowValidator::class));

        $sku = $cells[0];

        return CatalogItem::where('vendor_id', $this->vendor->id)
            ->where('dealer_sku', $sku)
            ->first();
    }

    /**
     * Create an item that belongs to a different vendor + catalog.
     */
    private function createForeignItem(string $sku): CatalogItem
    {
        $otherVendor = Vendor::factory()->create();
        $otherCatalog = Catalog::create(['vendor_id' => $otherVendor->id, 'name' => 'Foreign']);

        return CatalogItem::create([
            'vendor_id' => $otherVendor->id,
            'catalog_id' => $otherCatalog->id,
            'dealer_sku' => $sku,
            'name' => 'Foreign Item',
            'description' => 'Foreign description',
            'manufacturer_sku' => 'MFR-F',
            'manufacturer' => 'Foreign Mfg',
            'unit_of_measure' => UnitOfMeasure::where('code', 'EA')->first()->id,
            'quantity_per_unit' => 1,
            'item_weight' => 1.0,
            'selling_price' => 5.00,
            'specifications' => [['key' => 'Color', 'value' => 'Black']],
            'selling_points' => ['Foreign'],
            'category' => CommodityType::where('name', 'Safety')->first()->id,
            'hierarchy' => ProductHierarchy::where('level', 3)->first()->id,
        ]);
    }

    // ------------------------------------------------------------------
    // G. Export field values
    // ------------------------------------------------------------------

    public function test_exports_numeric_and_quantity_fields_from_stored_values(): void
    {
        $this->createItem('SKU-NUM-EXP', [
            'selling_price' => 12.50,
            'min_qty_per_order' => 2,
            'multiples' => 5,
            'max_qty_per_order' => 100,
            'availability' => 999,
        ]);

        $result = $this->generateAndRead();
        $row = $result['rows'][0];

        $this->assertSame('12.5', $this->columnValue($result['headers'], $row, 'APDcost'));
        $this->assertSame('2', $this->columnValue($result['headers'], $row, 'minimum'));
        $this->assertSame('5', $this->columnValue($result['headers'], $row, 'multiples'));
        $this->assertSame('100', $this->columnValue($result['headers'], $row, 'maximum'));
        $this->assertSame('999', $this->columnValue($result['headers'], $row, 'availability'));
    }

    public function test_exports_sku_alias_columns_from_the_dealer_sku(): void
    {
        $this->createItem('SKU-ALIAS-1');

        $result = $this->generateAndRead();
        $row = $result['rows'][0];

        $expected = ['dealer sku', 'customer sku', 'vendor sku', 'search sku'];

        foreach ($expected as $header) {
            $this->assertSame(
                'SKU-ALIAS-1',
                $this->columnValue($result['headers'], $row, $header),
                "The '$header' column must be populated from the dealer SKU under current behavior."
            );
        }
    }

    public function test_exports_the_unit_of_measure_code(): void
    {
        $this->createItem('SKU-UOM-EXP', [
            'unit_of_measure' => UnitOfMeasure::where('code', 'CS')->first()->id,
        ]);

        $result = $this->generateAndRead();

        $this->assertSame(
            'CS',
            $this->columnValue($result['headers'], $result['rows'][0], 'unit of measure'),
            'The unit of measure column must export the lookup code, not the id.'
        );
    }

    public function test_exports_empty_structured_and_multi_value_fields_cleanly(): void
    {
        // specifications/selling_points are required for an acceptable status,
        // so they keep values; the remaining structured/multi-value fields
        // are empty and must export as clean empty strings.
        $this->createItem('SKU-EMPTY-EXP', [
            'classifications' => null,
            'search_terms' => null,
            'images' => null,
        ]);

        $result = $this->generateAndRead();
        $row = $result['rows'][0];

        // Every row must still line up with the header columns.
        $this->assertCount(count($result['headers']), $row);

        foreach (['classifications', 'Protected Search Terms', 'image URLs'] as $header) {
            $this->assertSame(
                '',
                $this->columnValue($result['headers'], $row, $header),
                "Empty structured field '$header' must export as an empty string, not malformed output."
            );
        }
    }

    // ------------------------------------------------------------------
    // I. CSV correctness: newlines and column alignment
    // ------------------------------------------------------------------

    public function test_exports_newlines_within_quoted_csv_values(): void
    {
        $this->createItem('SKU-NL-EXP', [
            'description' => "First line\nSecond line",
            'selling_points' => ["Point one\ncontinued", 'Point two'],
        ]);

        $result = $this->generateAndRead();
        $row = $result['rows'][0];

        // The embedded newline must survive inside the quoted value and the
        // row must still parse back to exactly one logical row aligned with
        // the header columns.
        $this->assertCount(count($result['headers']), $row);
        $this->assertSame(
            "First line\nSecond line",
            $this->columnValue($result['headers'], $row, 'description')
        );
        $this->assertSame(
            "Point one\ncontinued|Point two",
            $this->columnValue($result['headers'], $row, 'selling points')
        );
    }

    // ------------------------------------------------------------------
    // J. Export eligibility
    // ------------------------------------------------------------------

    public function test_excludes_items_from_other_vendors_and_catalogs(): void
    {
        $this->createItem('SKU-OWN-1');
        $this->createForeignItem('SKU-FOREIGN-1');

        $result = $this->generateAndRead();

        $skus = array_map(
            fn ($row) => $this->columnValue($result['headers'], $row, 'dealer sku'),
            $result['rows']
        );

        $this->assertContains('SKU-OWN-1', $skus);
        $this->assertNotContains('SKU-FOREIGN-1', $skus, 'Items belonging to another vendor/catalog must not be exported.');
        $this->assertCount(1, $skus);
    }

    // ------------------------------------------------------------------
    // K. Submission export behavior
    // ------------------------------------------------------------------

    public function test_scopes_the_export_to_the_submissions_catalog(): void
    {
        // Same vendor, two catalogs. The submission is for $this->catalog.
        $otherCatalog = Catalog::create(['vendor_id' => $this->vendor->id, 'name' => 'Other']);
        $this->createItem('SKU-CAT-A');
        CatalogItem::create([
            'vendor_id' => $this->vendor->id,
            'catalog_id' => $otherCatalog->id,
            'dealer_sku' => 'SKU-CAT-B',
            'name' => 'Other Catalog Item',
            'description' => 'Belongs to the other catalog',
            'manufacturer_sku' => 'MFR-B',
            'manufacturer' => 'Acme Mfg',
            'unit_of_measure' => UnitOfMeasure::where('code', 'EA')->first()->id,
            'quantity_per_unit' => 1,
            'item_weight' => 1.0,
            'selling_price' => 5.00,
            'specifications' => [['key' => 'Color', 'value' => 'Blue']],
            'selling_points' => ['Other'],
            'category' => CommodityType::where('name', 'Safety')->first()->id,
            'hierarchy' => ProductHierarchy::where('level', 3)->first()->id,
        ]);

        $result = $this->generateAndRead();

        $skus = array_map(
            fn ($row) => $this->columnValue($result['headers'], $row, 'dealer sku'),
            $result['rows']
        );

        $this->assertContains('SKU-CAT-A', $skus);
        $this->assertNotContains('SKU-CAT-B', $skus, 'Items from other catalogs of the same vendor must not be exported.');
    }

    public function test_stamps_last_submitted_at_only_on_exported_items(): void
    {
        $eligible = $this->createItem('SKU-STAMP-YES');
        $incomplete = $this->createItem('SKU-STAMP-NO', [
            'name' => 'Broken',
            'description' => null,
            'manufacturer_sku' => null,
            'manufacturer' => null,
            'unit_of_measure' => null,
            'quantity_per_unit' => null,
            'item_weight' => null,
            'selling_price' => null,
            'specifications' => null,
            'selling_points' => null,
        ]);
        $before = now()->subSecond();

        $this->generateAndRead();

        $this->assertNotNull(
            $eligible->fresh()->last_submitted_at,
            'Exported eligible items must be stamped with last_submitted_at.'
        );
        $this->assertTrue(
            $eligible->fresh()->last_submitted_at->greaterThanOrEqualTo($before),
            'The stamp must reflect the export time.'
        );
        $this->assertNull(
            $incomplete->fresh()->last_submitted_at,
            'Items excluded from the export must not be stamped.'
        );
    }

    // ------------------------------------------------------------------
    // Round-trip / integration: mapped import -> database -> export -> CSV
    // ------------------------------------------------------------------

    /**
     * Baseline mapping + cells that produce an export-eligible item. Extra
     * round-trip columns start at index 12.
     *
     * @return array{mappings: array<int|string, string|array{0: string, 1: string}>, cells: list<string>}
     */
    private function baselineRoundTrip(string $sku): array
    {
        return [
            'mappings' => [
                0 => 'dealer_sku',
                1 => 'short_description',
                2 => 'long_description',
                3 => 'manufacturer',
                4 => 'manufacturer_sku',
                5 => 'unit_of_measure',
                6 => 'quantity_per_unit',
                7 => 'item_weight_in_pounds',
                8 => 'selling_price',
                9 => ['selling_points', '|'],
                10 => 'product_commodity_type',
                11 => 'hierarchy',
                12 => ['specifications', '|'],
            ],
            'cells' => [
                $sku,
                'RT Item',
                'RT description',
                'Acme Mfg',
                'MFR-RT',
                'EA',
                '1',
                '1.0',
                '5.00',
                'Durable',
                'Safety',
                ProductHierarchy::where('level', 3)->first()->hierarchy_number,
                'Color=Gray',
            ],
        ];
    }

    private function rowFor(array $result, int $index = 0): array
    {
        return $result['rows'][$index];
    }

    public function test_round_trips_specifications_from_import_to_export(): void
    {
        $baseline = $this->baselineRoundTrip('SKU-RT-SPEC');
        $baseline['cells'][12] = 'Color=Teal|Size=Small';

        $item = $this->importMappedRow($baseline['mappings'], $baseline['cells']);
        $this->assertNotNull($item, 'The round-trip row must import.');
        $this->assertSame([
            ['key' => 'Color', 'value' => 'Teal'],
            ['key' => 'Size', 'value' => 'Small'],
        ], $item->specifications);

        $result = $this->generateAndRead();
        $this->assertSame(
            'Color=Teal|Size=Small',
            $this->columnValue($result['headers'], $this->rowFor($result), 'specifications')
        );
    }

    public function test_round_trips_classifications_from_import_to_export(): void
    {
        $baseline = $this->baselineRoundTrip('SKU-RT-CLASS');
        $baseline['mappings'][13] = ['classifications', '|'];
        $baseline['mappings'][14] = 'country_of_origin';
        $baseline['mappings'][15] = 'unspsc_code';
        $baseline['cells'][] = 'EPP=Y|Hazmat=Y';
        $baseline['cells'][] = 'AX';
        $baseline['cells'][] = '46181504';

        $item = $this->importMappedRow($baseline['mappings'], $baseline['cells']);
        $this->assertNotNull($item, 'The round-trip row must import.');

        // Database: canonical key/value objects.
        $this->assertSame(
            [
                ['key' => 'EPP', 'value' => 'Y'],
                ['key' => 'Hazmat', 'value' => 'Y'],
                ['key' => 'COUNTRY_OF_ORIGIN', 'value' => 'AX'],
                ['key' => 'UNSPSC', 'value' => '46181504'],
            ],
            $item->classifications
        );

        // Export: canonical key=value pairs.
        $result = $this->generateAndRead();
        $parts = explode('|', $this->columnValue($result['headers'], $this->rowFor($result), 'classifications'));

        $this->assertSame(
            ['EPP=Y', 'Hazmat=Y', 'COUNTRY_OF_ORIGIN=AX', 'UNSPSC=46181504'],
            $parts
        );
    }

    public function test_round_trips_multi_value_fields_from_import_to_export(): void
    {
        $baseline = $this->baselineRoundTrip('SKU-RT-MV');
        // selling_points stays on its baseline column 9; only search_terms is
        // added on an extra column. Mapping the same field on two columns
        // would switch the pipeline into multi-column range mode.
        $baseline['mappings'][13] = ['search_terms', '|'];
        $baseline['cells'][9] = 'Durable|Lightweight|Reusable';
        $baseline['cells'][] = 'paper|laser paper|office paper';

        $item = $this->importMappedRow($baseline['mappings'], $baseline['cells']);
        $this->assertNotNull($item, 'The round-trip row must import.');
        $this->assertSame(['paper', 'laser paper', 'office paper'], $item->search_terms);
        $this->assertSame(['Durable', 'Lightweight', 'Reusable'], $item->selling_points);

        $result = $this->generateAndRead();
        $row = $this->rowFor($result);

        // Export uses the configured join separators: comma for search terms,
        // pipe for selling points.
        $this->assertSame(
            'paper,laser paper,office paper',
            $this->columnValue($result['headers'], $row, 'Protected Search Terms')
        );
        $this->assertSame(
            'Durable|Lightweight|Reusable',
            $this->columnValue($result['headers'], $row, 'selling points')
        );
    }

    public function test_round_trips_special_characters_from_import_to_export(): void
    {
        $baseline = $this->baselineRoundTrip('SKU-RT-CHARS');
        $baseline['cells'][1] = 'Gloves, Large "Heavy Duty" — Ünïcodé ✓';
        $baseline['cells'][2] = 'Contains, commas "quotes" café 日本語';
        $baseline['cells'][12] = 'Note, with comma=Value "quoted"';

        $item = $this->importMappedRow($baseline['mappings'], $baseline['cells']);
        $this->assertNotNull($item, 'The round-trip row must import.');

        $result = $this->generateAndRead();
        $row = $this->rowFor($result);

        // The parsed CSV values must be byte-identical to the imported data.
        $this->assertSame(
            'Gloves, Large "Heavy Duty" — Ünïcodé ✓',
            $this->columnValue($result['headers'], $row, 'name')
        );
        $this->assertSame(
            'Contains, commas "quotes" café 日本語',
            $this->columnValue($result['headers'], $row, 'description')
        );
        $this->assertSame(
            'Note, with comma=Value "quoted"',
            $this->columnValue($result['headers'], $row, 'specifications')
        );
    }
}
