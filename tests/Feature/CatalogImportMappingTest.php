<?php

namespace Tests\Feature;

use App\Enums\CatalogUploadStatus;
use App\Jobs\ProcessValidatedRowsJob;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadColumnMapping;
use App\Models\CatalogUploadRow;
use App\Models\Client;
use App\Models\CommodityType;
use App\Models\UnitOfMeasure;
use App\Models\Vendor;
use App\Services\VitExportFileDetector;
use App\Services\VitFieldDefinition;
use App\Services\Catalog\CatalogItemProcessor;
use App\Services\Catalog\CatalogRowValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Catalog import/mapping pipeline tests.
 *
 * These tests drive the real import path: raw file cells staged in
 * catalog_upload_rows.raw_data, mapped through catalog_upload_column_mappings,
 * processed by ProcessValidatedRowsJob, and persisted as CatalogItems.
 * They also cover VIT export file detection (CSV + XLSX auto-mapping).
 */
class CatalogImportMappingTest extends TestCase
{
    use RefreshDatabase;

    protected Vendor $vendor;
    protected Client $client;
    protected Catalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();
        $this->client = Client::factory()->create(['vendor_id' => $this->vendor->id]);
        $this->catalog = Catalog::create(['vendor_id' => $this->vendor->id, 'name' => 'Main']);
    }

    /**
     * Create a Processing upload with column mappings. Each mapping entry is
     * [column_index, field_key]; the source separator is auto-populated for
     * multi-value fields when a separator string is given.
     *
     * @param array<int|string, string|array{0: string, 1: string}> $mappings field_key or [field_key, separator] keyed by column index
     */
    private function makeUpload(array $mappings): CatalogUpload
    {
        $upload = CatalogUpload::create([
            'vendor_id' => $this->vendor->id,
            'client_id' => $this->client->id,
            'catalog_id' => $this->catalog->id,
            'original_filename' => 'upload.csv',
            'file_path' => 'catalog-uploads/upload.csv',
            'disk' => 'local',
            'file_type' => 'csv',
            'status' => CatalogUploadStatus::Processing,
            'total_rows' => 0,
        ]);

        foreach ($mappings as $columnIndex => $entry) {
            $fieldKey = is_array($entry) ? $entry[0] : $entry;
            $separator = is_array($entry) ? $entry[1] : null;
            $field = VitFieldDefinition::find($fieldKey);

            CatalogUploadColumnMapping::create([
                'catalog_upload_id' => $upload->id,
                'field_key' => $fieldKey,
                'column_index' => $columnIndex,
                'source_column_name' => "Column $columnIndex",
                'source_separator' => $separator,
            ]);
        }

        return $upload;
    }

    private function addRow(CatalogUpload $upload, array $cells): CatalogUploadRow
    {
        return CatalogUploadRow::create([
            'catalog_upload_id' => $upload->id,
            'row_number' => $upload->rows()->count() + 1,
            'data' => [],
            'raw_data' => $cells,
            'status' => 'valid',
        ]);
    }

    private function runJob(CatalogUpload $upload): void
    {
        (new ProcessValidatedRowsJob($upload->id, 0, PHP_INT_MAX))->handle(app(CatalogItemProcessor::class), app(CatalogRowValidator::class));
        $upload->refresh();
    }

    private function itemBySku(string $sku): ?CatalogItem
    {
        return CatalogItem::where('vendor_id', $this->vendor->id)
            ->where('dealer_sku', $sku)
            ->first();
    }

    // ------------------------------------------------------------------
    // Import / mapping
    // ------------------------------------------------------------------

    public function test_basic_mapped_import_creates_catalog_item_with_expected_values(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'long_description',
        ]);

        $this->addRow($upload, ['SKU-BASE-1', 'Safety Gloves', 'Heavy duty gloves']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-BASE-1');

        $this->assertNotNull($item, 'A CatalogItem should have been created from the mapped row.');
        $this->assertSame('Safety Gloves', $item->name);
        $this->assertSame('Heavy duty gloves', $item->description);
        $this->assertSame($this->catalog->id, (int) $item->catalog_id);
        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        $this->assertEquals(1, $upload->created_rows);
        $this->assertEquals(1, $upload->success_rows);
    }

    public function test_multiple_mapped_fields_stored_in_correct_database_attributes(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'long_description',
            3 => 'manufacturer',
            4 => 'manufacturer_sku',
            5 => 'brand_name',
            6 => 'list_price',
            7 => 'selling_price',
        ]);

        $this->addRow($upload, [
            'SKU-FIELDS-1',
            'Pro Hammer',
            'Steel claw hammer',
            'Acme Tools',
            'MFR-9001',
            'Acme',
            '29.99',
            '19.99',
        ]);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-FIELDS-1');

        $this->assertNotNull($item);
        $this->assertSame('Pro Hammer', $item->name);
        $this->assertSame('Steel claw hammer', $item->description);
        $this->assertSame('Acme Tools', $item->manufacturer);
        $this->assertSame('MFR-9001', $item->manufacturer_sku);
        $this->assertSame('Acme', $item->brand_name);
        $this->assertSame(29.99, (float) $item->list_price);
        $this->assertSame(19.99, (float) $item->selling_price);
    }

    public function test_specifications_imported_and_stored_as_key_value_objects(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => ['specifications', '|'],
        ]);

        $this->addRow($upload, ['SKU-SPEC-1', 'Paint Brush', 'Color=White|Size=Large']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-SPEC-1');

        $this->assertNotNull($item);
        $this->assertIsArray($item->specifications);
        $this->assertSame([
            ['key' => 'Color', 'value' => 'White'],
            ['key' => 'Size', 'value' => 'Large'],
        ], $item->specifications);
    }

    public function test_classifications_imported_and_stored_as_key_value_objects(): void
    {
        // Country of Origin ("AX" = Åland Islands, seeded by the country_codes
        // migration) and UNSPSC must end up as key/value objects inside the
        // classifications JSON — never as bare strings — alongside mapper-
        // supplied classifications.
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => ['classifications', '|'],
            3 => 'country_of_origin',
            4 => 'unspsc_code',
        ]);

        $this->addRow($upload, ['SKU-CLASS-1', 'Widget', 'EPP=Y|Hazmat=Y', 'AX', '46181504']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-CLASS-1');

        $this->assertNotNull($item);
        $this->assertIsArray($item->classifications);

        foreach ($item->classifications as $entry) {
            $this->assertIsArray($entry, 'Every classification entry must be a key/value object, not a string.');
            $this->assertArrayHasKey('key', $entry);
            $this->assertArrayHasKey('value', $entry);
        }

        $byKey = collect($item->classifications)->keyBy('key');

        $this->assertSame(['key' => 'EPP', 'value' => 'Y'], $byKey->get('EPP'));
        $this->assertSame(['key' => 'Hazmat', 'value' => 'Y'], $byKey->get('Hazmat'));
        $this->assertSame(['key' => 'COUNTRY_OF_ORIGIN', 'value' => 'AX'], $byKey->get('COUNTRY_OF_ORIGIN'));
        $this->assertSame(['key' => 'UNSPSC', 'value' => '46181504'], $byKey->get('UNSPSC'));
    }

    public function test_multi_value_field_split_with_configured_separator(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => ['search_terms', '|'],
        ]);

        $this->addRow($upload, ['SKU-MV-1', 'Copy Paper', 'paper | laser paper | office paper']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-MV-1');

        $this->assertNotNull($item);
        $this->assertSame(['paper', 'laser paper', 'office paper'], $item->search_terms);
    }

    public function test_missing_required_name_is_blocking_error_and_valid_rows_still_process(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
        ]);
        $upload->update(['total_rows' => 2]);

        // Row 1: valid. Row 2: missing the field that maps to catalog_items.name.
        $this->addRow($upload, ['SKU-REQ-OK', 'Good Item']);
        $this->addRow($upload, ['SKU-REQ-BAD', '']);

        $this->runJob($upload);

        $this->assertNotNull($this->itemBySku('SKU-REQ-OK'), 'Valid rows must still be processed.');
        $this->assertNull($this->itemBySku('SKU-REQ-BAD'), 'A row without a name must not create a CatalogItem.');

        $badRow = CatalogUploadRow::where('catalog_upload_id', $upload->id)->get()
            ->first(fn ($row) => ($row->raw_data[0] ?? null) === 'SKU-REQ-BAD');

        $this->assertNotNull($badRow, 'The invalid row must be recorded.');
        $this->assertSame('invalid', $badRow->status);

        $errors = $badRow->errors['errors'] ?? [];
        $this->assertNotEmpty($errors, 'A validation message must be stored on the invalid row.');
        $this->assertSame('short_description', $errors[0]['field_key']);
        $this->assertStringContainsString('required', $errors[0]['message']);

        $this->assertEquals(1, $upload->success_rows);
        $this->assertEquals(1, $upload->invalid_rows);
        $this->assertEquals(1, $upload->created_rows);
    }

    public function test_mixed_valid_and_invalid_rows_produce_accurate_counts(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'list_price',
        ]);
        $upload->update(['total_rows' => 3]);

        $this->addRow($upload, ['SKU-MIX-OK', 'Valid Item', '12.50']);
        // Non-numeric price: blocking validation error.
        $this->addRow($upload, ['SKU-MIX-BAD-PRICE', 'Bad Price Item', 'not-a-number']);
        // Missing dealer_sku: blocking identity error.
        $this->addRow($upload, ['', 'No Sku Item', '5.00']);

        $this->runJob($upload);

        $this->assertNotNull($this->itemBySku('SKU-MIX-OK'));
        $this->assertNull($this->itemBySku('SKU-MIX-BAD-PRICE'));

        $this->assertEquals(1, $upload->created_rows);
        $this->assertEquals(1, $upload->success_rows);
        $this->assertEquals(2, $upload->invalid_rows);

        $invalidRows = CatalogUploadRow::where('catalog_upload_id', $upload->id)
            ->where('status', 'invalid')
            ->get();

        $this->assertCount(2, $invalidRows);

        foreach ($invalidRows as $row) {
            $this->assertNotEmpty($row->errors['errors'] ?? [], 'Each invalid row must record its validation message.');
        }
    }

    public function test_optional_empty_values_are_processed_without_corrupting_data(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'brand_name',      // optional, present but empty
            3 => 'list_price',      // optional, present but empty
        ]);

        $this->addRow($upload, ['SKU-OPT-1', 'Simple Item', '', '']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-OPT-1');

        $this->assertNotNull($item, 'Empty optional values must not block the row.');
        $this->assertNull($item->brand_name);
        $this->assertNull($item->list_price);
        // availability has an INTEGER NOT NULL column with a default of 999 —
        // an unmapped availability must not overwrite it with bad data.
        $this->assertSame(999, (int) $item->availability);
        $this->assertSame('Simple Item', $item->name);
    }

    public function test_unit_of_measure_resolved_to_lookup_id(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'unit_of_measure',
        ]);

        $this->addRow($upload, ['SKU-UOM-1', 'Loose Widget', 'EA']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-UOM-1');

        $this->assertNotNull($item);
        $expected = UnitOfMeasure::where('code', 'EA')->first();
        $this->assertNotNull($expected);
        $this->assertSame($expected->id, (int) $item->unit_of_measure);
    }

    // ------------------------------------------------------------------
    // VIT export file detection / auto-mapping
    // ------------------------------------------------------------------

    /**
     * Full current VIT export header signature (from the export service's own
     * source of truth), so detection tests always exercise a realistic file.
     *
     * @return list<string>
     */
    private function vitHeaders(): array
    {
        return VitFieldDefinition::exportableFields()
            ->pluck('vit_csv_column')
            ->map(fn ($c) => (string) $c)
            ->all();
    }

    public function test_current_vit_csv_is_detected_and_builds_correct_auto_mappings(): void
    {
        $result = (new VitExportFileDetector)
            ->detect('local', 'does/not/matter.csv', 'csv', $this->vitHeaders());

        $this->assertNotNull($result, 'A file carrying the full current VIT header signature must be detected.');

        $mappings = collect($result['mappings']);

        // The name column maps to the name field at its own column index.
        $name = $mappings->firstWhere('field_key', 'short_description');
        $this->assertNotNull($name);
        $this->assertSame(array_search('name', $this->vitHeaders(), true), $name['column_index']);

        // Search terms are multi-value and keep their configured separator.
        $searchTerms = $mappings->firstWhere('field_key', 'search_terms');
        $this->assertNotNull($searchTerms);
        $this->assertSame(',', $searchTerms['source_separator']);

        // Specifications are multi-value key/value with the pipe separator.
        $specs = $mappings->firstWhere('field_key', 'specifications');
        $this->assertNotNull($specs);
        $this->assertSame('|', $specs['source_separator']);

        // System-derived fields (vendor, catalog, type, SKU aliases) are
        // intentionally NOT auto-mapped.
        $mappedKeys = $mappings->pluck('field_key')->all();
        $this->assertNotContains('vendor_name', $mappedKeys);
        $this->assertNotContains('catalog_name', $mappedKeys);
        $this->assertNotContains('type', $mappedKeys);
        $this->assertNotContains('customer_sku', $mappedKeys);
    }

    public function test_vit_csv_with_missing_or_duplicate_headers_prevents_detection(): void
    {
        $headers = $this->vitHeaders();

        // Missing an expected header ("Category").
        $withoutCategory = collect($headers)
            ->reject(fn ($h) => mb_strtolower($h) === 'category')
            ->values()
            ->all();

        $this->assertNull(
            (new VitExportFileDetector)->detect('local', 'x.csv', 'csv', $withoutCategory),
            'A missing expected VIT header must prevent detection.'
        );

        // A duplicated header makes column identity ambiguous.
        $withDuplicate = $headers;
        $withDuplicate[] = 'name';

        $this->assertNull(
            (new VitExportFileDetector)->detect('local', 'x.csv', 'csv', $withDuplicate),
            'A duplicated VIT header must prevent detection.'
        );
    }

    public function test_vit_csv_extra_columns_are_tolerated(): void
    {
        $headers = $this->vitHeaders();
        $headers[] = 'Vendor Notes';
        $headers[] = 'Internal Reference';

        $result = (new VitExportFileDetector)
            ->detect('local', 'x.csv', 'csv', $headers);

        $this->assertNotNull($result, 'Extra non-VIT columns must not invalidate an otherwise valid VIT CSV.');
    }

    public function test_current_vit_xlsx_is_detected_with_marker(): void
    {
        $headers = $this->vitHeaders();
        $path = $this->writeVitXlsx($headers, withMarker: true);

        $result = (new VitExportFileDetector)
            ->detect('local', $path, 'xlsx', $headers);

        $this->assertNotNull($result, 'A current-version VIT XLSX (marker + headers) must be detected.');

        $name = collect($result['mappings'])->firstWhere('field_key', 'short_description');
        $this->assertNotNull($name, 'The XLSX auto-mapping must include the name field.');
        $this->assertSame('name', $name['source_column_name']);
    }

    public function test_xlsx_without_marker_is_not_detected(): void
    {
        $headers = $this->vitHeaders();
        $path = $this->writeVitXlsx($headers, withMarker: false);

        $this->assertNull(
            (new VitExportFileDetector)->detect('local', $path, 'xlsx', $headers),
            'An XLSX with matching headers but no VIT marker must fall back to the manual mapper.'
        );
    }

    private function writeVitXlsx(array $headers, bool $withMarker): string
    {
        $spreadsheet = new Spreadsheet();

        if ($withMarker) {
            $spreadsheet->getProperties()
                ->setCustomProperty(VitFieldDefinition::VIT_EXPORT_MARKER_KEY, VitFieldDefinition::VIT_EXPORT_MARKER_VALUE)
                ->setCustomProperty(VitFieldDefinition::VIT_EXPORT_VERSION_KEY, (string) VitFieldDefinition::VIT_EXPORT_VERSION);
        }

        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $index => $header) {
            $sheet->getCell([$index + 1, 1])->setValue($header);
        }

        Storage::disk('local')->makeDirectory('vit-detect');
        $path = 'vit-detect/vit-export-' . ($withMarker ? 'marker' : 'plain') . '.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save(Storage::disk('local')->path($path));

        return $path;
    }

    // ------------------------------------------------------------------
    // A. Field mapping and database persistence
    // ------------------------------------------------------------------

    public function test_casts_numeric_fields_from_mapped_input(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'list_price',
            3 => 'selling_price',
            4 => 'availability',
            5 => 'item_weight_in_pounds',
        ]);

        $this->addRow($upload, ['SKU-NUM-1', 'Numeric Item', '29.99', '19.49', '500', '2.5']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-NUM-1');

        $this->assertNotNull($item);
        $this->assertSame(29.99, (float) $item->list_price);
        $this->assertSame(19.49, (float) $item->selling_price);
        $this->assertSame(500, (int) $item->availability, 'availability must be coerced to an integer.');
        $this->assertSame(2.5, (float) $item->item_weight);
    }

    public function test_casts_boolean_discontinued_from_mapped_input(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'discontinued',
        ]);
        $upload->update(['total_rows' => 2]);

        $this->addRow($upload, ['SKU-BOOL-1', 'Discontinued Item', 'TRUE']);
        $this->addRow($upload, ['SKU-BOOL-2', 'Active Item', 'false']);
        $this->addRow($upload, ['SKU-BOOL-3', 'Also Active Item', 'no']);
        $this->runJob($upload);

        $this->assertTrue((bool) $this->itemBySku('SKU-BOOL-1')->is_discontinued);
        $this->assertFalse((bool) $this->itemBySku('SKU-BOOL-2')->is_discontinued);
        $this->assertFalse((bool) $this->itemBySku('SKU-BOOL-3')->is_discontinued);
    }

    public function test_persists_multiple_catalog_items_from_one_upload_independently(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'long_description',
        ]);
        $upload->update(['total_rows' => 2]);

        $this->addRow($upload, ['SKU-MULTI-1', 'First Item', 'First description']);
        $this->addRow($upload, ['SKU-MULTI-2', 'Second Item', 'Second description']);
        $this->runJob($upload);

        $first = $this->itemBySku('SKU-MULTI-1');
        $second = $this->itemBySku('SKU-MULTI-2');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('First Item', $first->name);
        $this->assertSame('Second Item', $second->name);
        $this->assertSame('Second description', $second->description);
        $this->assertEquals(2, $upload->created_rows);
    }

    // ------------------------------------------------------------------
    // B. Multi-value and structured fields
    // ------------------------------------------------------------------

    public function test_preserves_multi_value_order_when_persisting(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => ['selling_points', '|'],
        ]);

        $this->addRow($upload, ['SKU-ORD-1', 'Ordered Item', 'Durable|Lightweight|Reusable']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-ORD-1');

        $this->assertNotNull($item);
        $this->assertSame(['Durable', 'Lightweight', 'Reusable'], $item->selling_points);
    }

    public function test_splits_multi_value_with_the_default_comma_separator_when_none_is_configured(): void
    {
        // Mapping without a source_separator: the pipeline falls back to ','.
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'search_terms',
        ]);

        $this->addRow($upload, ['SKU-DEF-1', 'Default Separator Item', 'alpha, beta, gamma']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-DEF-1');

        $this->assertNotNull($item);
        $this->assertSame(['alpha', 'beta', 'gamma'], $item->search_terms);
    }

    public function test_normalizes_key_value_entries_with_whitespace_and_extra_equals_signs(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => ['specifications', '|'],
        ]);

        // The current implementation splits on the FIRST '=' only and trims
        // keys/values, so "A=B" remains a valid value and padding is removed.
        $this->addRow($upload, ['SKU-KV-1', 'Key/Value Item', 'Color = White|Note=A=B']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-KV-1');

        $this->assertNotNull($item);
        $this->assertSame([
            ['key' => 'Color', 'value' => 'White'],
            ['key' => 'Note', 'value' => 'A=B'],
        ], $item->specifications);
    }

    // ------------------------------------------------------------------
    // C. Validation and invalid rows
    // ------------------------------------------------------------------

    public function test_records_warning_for_missing_conditional_field_when_trigger_is_active(): void
    {
        // discontinued=TRUE activates the conditional requirement for
        // discontinued_date. The row must stay valid/non-blocking, and a
        // warning identifying the missing field must be recorded.
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'discontinued',
        ]);

        $this->addRow($upload, ['SKU-WARN-1', 'Soon Discontinued', 'TRUE']);
        $this->runJob($upload);

        $this->assertNotNull($this->itemBySku('SKU-WARN-1'), 'A missing conditional field must not block the row.');

        $row = CatalogUploadRow::where('catalog_upload_id', $upload->id)->first();

        $this->assertSame('valid', $row->status);
        $stored = $row->errors ?? [];

        $warnings = $stored['warnings'] ?? [];
        $this->assertNotEmpty($warnings, 'A warning must be recorded for the missing conditional field.');
        $this->assertSame('discontinued_date', $warnings[0]['field_key']);
        $this->assertStringContainsString('Discontinued Date', $warnings[0]['message']);
        $this->assertSame([], $stored['errors'] ?? [], 'No blocking errors may be recorded for this row.');
    }

    public function test_records_warning_for_hazmat_classification_without_msds_link(): void
    {
        // 'Hazmat' inside the classifications values activates the conditional
        // requirement for msds_link via the substring matching behavior of
        // matchesCondition(). The row must stay valid with a warning.
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => ['classifications', '|'],
        ]);

        $this->addRow($upload, ['SKU-HAZ-1', 'Hazmat Item', 'Hazmat=Y']);
        $this->runJob($upload);

        $this->assertNotNull($this->itemBySku('SKU-HAZ-1'), 'A missing msds_link must not block the row.');

        $row = CatalogUploadRow::where('catalog_upload_id', $upload->id)->first();

        $this->assertSame('valid', $row->status);
        $stored = $row->errors ?? [];

        $warnings = $stored['warnings'] ?? [];
        $this->assertNotEmpty($warnings, 'A warning must be recorded for the missing MSDS link.');
        $this->assertSame('msds_link', $warnings[0]['field_key']);
        $this->assertStringContainsString('Classifications', $warnings[0]['message']);
        $this->assertStringContainsString('MSDS Link', $warnings[0]['message']);
        $this->assertSame([], $stored['errors'] ?? [], 'No blocking errors may be recorded for this row.');
    }

    public function test_does_not_warn_when_conditional_trigger_is_inactive(): void
    {
        // discontinued=FALSE leaves the conditional requirements inactive:
        // a missing discontinued_date must produce no warning at all.
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'discontinued',
        ]);

        $this->addRow($upload, ['SKU-INACT-1', 'Active Item', 'FALSE']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-INACT-1');

        $this->assertNotNull($item);
        $this->assertFalse((bool) $item->is_discontinued);

        $row = CatalogUploadRow::where('catalog_upload_id', $upload->id)->first();

        $this->assertSame('valid', $row->status);
        $stored = $row->errors;
        $this->assertTrue(
            $stored === null || (empty($stored['warnings']) && empty($stored['errors'])),
            'An inactive conditional trigger must not produce warnings or errors.'
        );
    }

    public function test_does_not_warn_when_conditional_dependent_field_is_supplied(): void
    {
        // discontinued=TRUE with a supplied discontinued_date: the conditional
        // requirement is satisfied, so no missing-field warning may appear.
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'discontinued',
            3 => 'discontinued_date',
        ]);

        $this->addRow($upload, ['SKU-SUP-1', 'Discontinued Item', 'TRUE', '2026-01-15']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-SUP-1');

        $this->assertNotNull($item);
        $this->assertTrue((bool) $item->is_discontinued);
        // discontinue_date has no date cast on the model — the imported text
        // value is stored verbatim.
        $this->assertSame('2026-01-15', $item->discontinue_date);

        $row = CatalogUploadRow::where('catalog_upload_id', $upload->id)->first();

        $this->assertSame('valid', $row->status);
        $stored = $row->errors ?? [];

        // The discontinued_date requirement is satisfied — no warning for it.
        $warnedKeys = collect($stored['warnings'] ?? [])->pluck('field_key');
        $this->assertNotContains('discontinued_date', $warnedKeys);

        // replacement_sku is also conditional on discontinued=TRUE and is
        // empty here, so a warning for it is expected current behavior.
        $this->assertContains('replacement_sku', $warnedKeys);
    }

    // ------------------------------------------------------------------
    // D. Optional fields and defaults
    // ------------------------------------------------------------------

    public function test_does_not_overwrite_existing_values_with_missing_optional_fields(): void
    {
        CatalogItem::create([
            'vendor_id' => $this->vendor->id,
            'catalog_id' => $this->catalog->id,
            'dealer_sku' => 'SKU-UPD-1',
            'name' => 'Old Name',
            'brand_name' => 'Old Brand',
        ]);

        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
        ]);

        // The update row does not carry brand_name at all.
        $this->addRow($upload, ['SKU-UPD-1', 'New Name']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-UPD-1');

        $this->assertNotNull($item);
        $this->assertSame('New Name', $item->name, 'Mapped values must be updated.');
        $this->assertSame('Old Brand', $item->brand_name, 'Unmapped existing values must not be wiped.');
        $this->assertEquals(1, $upload->updated_rows);
        $this->assertEquals(0, $upload->created_rows);
    }

    public function test_handles_empty_multi_value_field_cleanly(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => ['search_terms', '|'],
        ]);

        $this->addRow($upload, ['SKU-EMV-1', 'No Search Terms', '']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-EMV-1');

        $this->assertNotNull($item, 'An empty multi-value field must not block the row.');
        $this->assertNull($item->search_terms);
    }

    public function test_handles_empty_key_value_field_cleanly(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => ['specifications', '|'],
        ]);

        $this->addRow($upload, ['SKU-EKV-1', 'No Specifications', '']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-EKV-1');

        $this->assertNotNull($item, 'An empty key/value field must not block the row.');
        $this->assertNull($item->specifications);
    }

    // ------------------------------------------------------------------
    // E. Lookup and field transformation behavior
    // ------------------------------------------------------------------

    public function test_resolves_unknown_unit_of_measure_to_null(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'unit_of_measure',
        ]);

        $this->addRow($upload, ['SKU-UOM-BAD', 'Widget With Bad UOM', 'ZZZ']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-UOM-BAD');

        $this->assertNotNull($item, 'An unknown UOM must not block the row under current behavior.');
        $this->assertNull($item->unit_of_measure, 'An unknown UOM must not be persisted as an id.');
    }

    public function test_maps_country_of_origin_by_country_name(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'country_of_origin',
        ]);

        // Country names are accepted in addition to ISO codes (seeded by the
        // country_codes migration: Canada -> CA).
        $this->addRow($upload, ['SKU-COO-1', 'Canadian Item', 'Canada']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-COO-1');

        $this->assertNotNull($item);
        $origin = collect($item->classifications)->firstWhere('key', 'COUNTRY_OF_ORIGIN');
        $this->assertNotNull($origin, 'Country of Origin must become a classification entry.');
        $this->assertSame('CA', $origin['value']);
    }

    public function test_resolves_commodity_type_lookup_to_category_id(): void
    {
        $upload = $this->makeUpload([
            0 => 'dealer_sku',
            1 => 'short_description',
            2 => 'product_commodity_type',
        ]);

        $this->addRow($upload, ['SKU-CAT-1', 'Categorized Item', 'Writing Instruments']);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-CAT-1');

        $this->assertNotNull($item);
        $expected = CommodityType::where('name', 'Writing Instruments')->first();
        $this->assertNotNull($expected);
        $this->assertSame($expected->id, (int) $item->category);
    }

    public function test_imports_auto_mapped_vit_export_columns_while_skipping_system_derived_fields(): void
    {
        // Detect a VIT export, then feed its auto-mappings straight into the
        // real import pipeline. System-derived columns (vendor, catalog, type,
        // customer SKU) must be skipped, not imported as item data.
        $headers = $this->vitHeaders();
        $result = (new VitExportFileDetector)->detect('local', 'x.csv', 'csv', $headers);
        $this->assertNotNull($result);

        $upload = CatalogUpload::create([
            'vendor_id' => $this->vendor->id,
            'client_id' => $this->client->id,
            'catalog_id' => $this->catalog->id,
            'original_filename' => 'vit-export.csv',
            'file_path' => 'catalog-uploads/vit-export.csv',
            'disk' => 'local',
            'file_type' => 'csv',
            'status' => CatalogUploadStatus::Processing,
            'total_rows' => 1,
        ]);

        foreach ($result['mappings'] as $mapping) {
            CatalogUploadColumnMapping::create([
                'catalog_upload_id' => $upload->id,
                'field_key' => $mapping['field_key'],
                'column_index' => $mapping['column_index'],
                'source_column_name' => $mapping['source_column_name'],
                'source_separator' => $mapping['source_separator'],
            ]);
        }

        $cells = [];
        $cells[array_search('vendor', $headers, true)] = 'Some Vendor';
        $cells[array_search('catalog', $headers, true)] = 'Some Catalog';
        $cells[array_search('type', $headers, true)] = 'ELINK';
        $cells[array_search('customer sku', $headers, true)] = 'IGNORED-SKU';
        $cells[array_search('dealer sku', $headers, true)] = 'SKU-VIT-AUTO-1';
        $cells[array_search('name', $headers, true)] = 'VIT Reimported Item';
        $cells[array_search('description', $headers, true)] = 'From a VIT export';
        $cells[array_search('Protected Search Terms', $headers, true)] = 'gloves, safety gloves';
        $cells[array_search('specifications', $headers, true)] = 'Color=Red|Size=Medium';

        $this->addRow($upload, $cells);
        $this->runJob($upload);

        $item = $this->itemBySku('SKU-VIT-AUTO-1');

        $this->assertNotNull($item, 'The auto-mapped VIT row must import.');
        $this->assertSame('VIT Reimported Item', $item->name);
        $this->assertSame('From a VIT export', $item->description);
        $this->assertSame(['gloves', 'safety gloves'], $item->search_terms);
        $this->assertSame([
            ['key' => 'Color', 'value' => 'Red'],
            ['key' => 'Size', 'value' => 'Medium'],
        ], $item->specifications);
        // The system-derived customer-SKU value must not have replaced the
        // real dealer SKU.
        $this->assertSame('SKU-VIT-AUTO-1', $item->dealer_sku);
    }

    // ------------------------------------------------------------------
    // VIT file detection: header normalization
    // ------------------------------------------------------------------

    public function test_detects_vit_csv_case_insensitively_and_in_any_column_order(): void
    {
        $headers = $this->vitHeaders();

        // Reverse the column order and uppercase every header: detection is
        // documented as case-insensitive and order-independent.
        $shuffled = array_map('mb_strtoupper', array_reverse($headers));

        $result = (new VitExportFileDetector)->detect('local', 'x.csv', 'csv', $shuffled);

        $this->assertNotNull($result, 'Header order and casing must not prevent detection.');

        // Auto-mapped column indexes must follow the file's actual layout.
        $name = collect($result['mappings'])->firstWhere('field_key', 'short_description');
        $this->assertSame(
            array_search('NAME', $shuffled, true),
            $name['column_index']
        );
        $this->assertSame('NAME', $name['source_column_name']);
    }
}
