<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Vendor;
use App\Services\CatalogExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class CatalogExportHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function readRowByColumns($sheet, int $rowNumber, int $maxColumn = 30): array
    {
        $values = [];
        for ($i = 1; $i <= $maxColumn; $i++) {
            $columnLetter = Coordinate::stringFromColumnIndex($i);
            $cell = $sheet->getCell("{$columnLetter}{$rowNumber}");
            $values[] = $cell->getValue();
        }
        return $values;
    }

    public function test_exported_excel_headers_match_vit_spec(): void
    {
        $vendor = Vendor::create(['name' => 'Test Vendor', 'status' => 'active']);

        $item = CatalogItem::create([
            'vendor_id' => $vendor->id,
            'name' => 'Test Item',
            'description' => 'A test item',
            'dealer_sku' => 'SKU-001',
            'unit_of_measure' => 'EA',
            'quantity_per_unit' => 10,
            'unit_word' => 'Each',
            'manufacturer_sku' => 'MFG-001',
            'manufacturer' => 'Test Mfg',
            'brand_name' => 'Test Brand',
            'hierarchy' => 'Test/Category/Path',
            'unspsc_code' => '14111507',
            'country_of_origin' => 'US',
            'list_price' => 10.00,
            'selling_price' => 8.00,
            'item_weight' => 1.5,
            'search_terms' => ['pen', 'paper'],
            'specifications' => ['Color=Red'],
            'selling_points' => ['Durable'],
            'classifications' => ['EPP', 'Recyclable'],
            'msds_link' => 'https://msds.example.com/test',
        ]);

        $service = new CatalogExportService();
        $spreadsheet = $service->generate($vendor, 'Test Catalog', collect([$item]));

        $tempPath = tempnam(sys_get_temp_dir(), 'catalog_test_') . '.xlsx';
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($tempPath);

        $reader = IOFactory::createReader('Xlsx');
        $reopened = $reader->load($tempPath);
        $sheet = $reopened->getActiveSheet();

        $headers = [];
        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $headers[] = $cell->getValue();
            }
        }

        $rowData = $this->readRowByColumns($sheet, 2, count($headers));
        unlink($tempPath);

        $expectedHeaders = [
            'vendor',
            'catalog',
            'dealer sku',
            'customer sku',
            'vendor sku',
            'search sku',
            'type',
            'category',
            'manufacturer sku',
            'manufacturer',
            'name',
            'description',
            'search terms',
            'hierarchy',
            'brandName',
            'list price',
            'APDcost',
            'unit of measure',
            'specifications',
            'classifications',
            'item weight',
            'selling points',
            'minimum',
            'multiples',
            'maximum',
            'image URLs',
            'discontinued',
            'discontinuedDate',
            'replacement Sku',
            'lead_time',
            'availability',
        ];

        $this->assertEquals($expectedHeaders, $headers, 'Exported Excel headers must match VIT spec exactly');

        $this->assertNotContains('UNSPSC', $headers, 'UNSPSC should not be its own column');
        $this->assertNotContains('Country of Origin', $headers, 'Country of Origin should not be its own column');
        $this->assertNotContains('MSDS Link', $headers, 'MSDS Link should not be its own column');
        $this->assertNotContains('quantity per unit', $headers, 'Quantity per Unit should not be its own column');
        $this->assertNotContains('unit_word', $headers, 'unit_word should not be its own column');
        $this->assertNotContains('Quantity Unit Type', $headers, 'unit_word should not appear as a column header');

        $classificationsIndex = array_search('classifications', $headers);
        $this->assertNotFalse($classificationsIndex, 'classifications column must exist');
        $classificationsValue = $rowData[$classificationsIndex];

        $this->assertStringContainsString('UNSPSC=14111507', $classificationsValue);
        $this->assertStringContainsString('Country of Origin=US', $classificationsValue);
        $this->assertContains('brandName', $headers, 'brand_name should export as "brandName" per VIT spec');
        $this->assertContains('APDcost', $headers, 'selling_price should export as "APDcost" per VIT spec');
        $this->assertContains('category', $headers, 'product_commodity_type should export as "category" per VIT spec');
    }

    public function test_export_contains_availability_lead_time_and_selected_hierarchy_values(): void
    {
        $vendor = Vendor::create(['name' => 'Test Vendor', 'status' => 'active']);

        $item = CatalogItem::create([
            'vendor_id' => $vendor->id,
            'name' => 'Export Trace Item',
            'description' => 'A trace item',
            'dealer_sku' => 'SKU-TRACE',
            'unit_of_measure' => 'EA',
            'quantity_per_unit' => 1,
            'unit_word' => 'Each',
            'manufacturer_sku' => 'MFG-TRACE',
            'manufacturer' => 'Trace Mfg',
            'brand_name' => 'Trace Brand',
            'hierarchy' => '10001',
            'unspsc_code' => '14111507',
            'country_of_origin' => 'US',
            'list_price' => 10.00,
            'selling_price' => 8.00,
            'item_weight' => 1.5,
            'search_terms' => ['trace'],
            'specifications' => ['Color=Blue'],
            'selling_points' => ['Audited'],
            'classifications' => ['EPP'],
            'availability' => 12,
            'lead_time' => '2 days',
        ]);

        $service = new CatalogExportService();
        $spreadsheet = $service->generate($vendor, 'Test Catalog', collect([$item]));

        $tempPath = tempnam(sys_get_temp_dir(), 'catalog_test_') . '.xlsx';
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($tempPath);

        $reader = IOFactory::createReader('Xlsx');
        $reopened = $reader->load($tempPath);
        $sheet = $reopened->getActiveSheet();

        $headers = [];
        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $headers[] = $cell->getValue();
            }
        }

        $rowData = $this->readRowByColumns($sheet, 2, count($headers));
        unlink($tempPath);

        $leadIndex = array_search('lead_time', $headers);
        $availabilityIndex = array_search('availability', $headers);
        $hierarchyIndex = array_search('hierarchy', $headers);

        $this->assertNotFalse($leadIndex, 'lead_time column must exist');
        $this->assertNotFalse($availabilityIndex, 'availability column must exist');
        $this->assertNotFalse($hierarchyIndex, 'hierarchy column must exist');

        $this->assertSame('2 days', (string) $rowData[$leadIndex]);
        $this->assertSame('12', (string) $rowData[$availabilityIndex]);
        $this->assertSame('10001', (string) $rowData[$hierarchyIndex]);
    }

    public function test_quantity_per_unit_appended_to_name_with_unit_word(): void
    {
        $vendor = Vendor::create(['name' => 'Test Vendor', 'status' => 'active']);

        $item = CatalogItem::create([
            'vendor_id' => $vendor->id,
            'name' => 'Test Item',
            'dealer_sku' => 'SKU-002',
            'unit_of_measure' => 'CS',
            'unit_word' => 'Reams',
            'quantity_per_unit' => 10,
            'item_weight' => 1.0,
        ]);

        $service = new CatalogExportService();
        $spreadsheet = $service->generate($vendor, 'Test Catalog', collect([$item]));

        $tempPath = tempnam(sys_get_temp_dir(), 'catalog_test_') . '.xlsx';
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($tempPath);

        $reader = IOFactory::createReader('Xlsx');
        $reopened = $reader->load($tempPath);
        $sheet = $reopened->getActiveSheet();

        unlink($tempPath);

        $headers = [];
        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $headers[] = $cell->getValue();
            }
        }

        $rowData = $this->readRowByColumns($sheet, 2, count($headers));
        $nameIndex = array_search('name', $headers);
        $this->assertNotFalse($nameIndex, 'name column must exist');

        $nameValue = $rowData[$nameIndex] ?? '';

        // VIT spec format: "Test Item, 10 Reams/CS"
        // (quantity + unit_word + "/" + UOM)
        $this->assertStringContainsString('Test Item', $nameValue);
        $this->assertStringContainsString('10 Reams/CS', $nameValue, 'Quantity per unit should be formatted as "10 Reams/CS"');
    }

    public function test_quantity_per_unit_fallback_without_unit_word(): void
    {
        $vendor = Vendor::create(['name' => 'Test Vendor', 'status' => 'active']);

        $item = CatalogItem::create([
            'vendor_id' => $vendor->id,
            'name' => 'Fallback Item',
            'dealer_sku' => 'SKU-005',
            'unit_of_measure' => 'BX',
            'quantity_per_unit' => 12,
            'item_weight' => 1.0,
        ]);

        $service = new CatalogExportService();
        $spreadsheet = $service->generate($vendor, 'Test Catalog', collect([$item]));

        $tempPath = tempnam(sys_get_temp_dir(), 'catalog_test_') . '.xlsx';
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($tempPath);

        $reader = IOFactory::createReader('Xlsx');
        $reopened = $reader->load($tempPath);
        $sheet = $reopened->getActiveSheet();

        unlink($tempPath);

        $headers = [];
        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $headers[] = $cell->getValue();
            }
        }

        $rowData = $this->readRowByColumns($sheet, 2, count($headers));
        $nameIndex = array_search('name', $headers);
        $this->assertNotFalse($nameIndex, 'name column must exist');

        $nameValue = $rowData[$nameIndex] ?? '';

        // Without unit_word, falls back to "12 BX" (quantity + UOM only)
        $this->assertStringContainsString('Fallback Item', $nameValue);
        $this->assertStringContainsString('12 BX', $nameValue, 'Should fallback to "quantity UOM" when unit_word is missing');
    }

    public function test_packed_fields_format_in_classifications(): void
    {
        $vendor = Vendor::create(['name' => 'Test Vendor', 'status' => 'active']);

        $item = CatalogItem::create([
            'vendor_id' => $vendor->id,
            'name' => 'Packed Item',
            'dealer_sku' => 'SKU-003',
            'unit_of_measure' => 'BX',
            'item_weight' => 2.5,
            'unspsc_code' => '14111507',
            'country_of_origin' => 'CA',
            'msds_link' => 'https://msds.example.com/packed',
            'classifications' => ['EPP', 'Hazmat'],
        ]);

        $service = new CatalogExportService();
        $spreadsheet = $service->generate($vendor, 'Test Catalog', collect([$item]));

        $tempPath = tempnam(sys_get_temp_dir(), 'catalog_test_') . '.xlsx';
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($tempPath);

        $reader = IOFactory::createReader('Xlsx');
        $reopened = $reader->load($tempPath);
        $sheet = $reopened->getActiveSheet();

        unlink($tempPath);

        $headers = [];
        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $headers[] = $cell->getValue();
            }
        }

        $rowData = $this->readRowByColumns($sheet, 2, count($headers));

        $classificationsIndex = array_search('classifications', $headers);
        $this->assertNotFalse($classificationsIndex, 'classifications column must exist');

        $classificationsValue = $rowData[$classificationsIndex];

        $this->assertStringContainsString('UNSPSC=14111507', $classificationsValue);
        $this->assertStringContainsString('Country of Origin=CA', $classificationsValue);
        $this->assertStringContainsString('MSDS URL=https://msds.example.com/packed', $classificationsValue);
        $this->assertStringContainsString('EPP', $classificationsValue);
        $this->assertStringContainsString('Hazmat', $classificationsValue);

        $parts = explode('|', $classificationsValue);
        $this->assertGreaterThan(4, count($parts), 'classifications should contain 5 parts: EPP, Hazmat, UNSPSC, Country of Origin, MSDS');
    }

    public function test_mds_link_excluded_when_not_hazmat(): void
    {
        $vendor = Vendor::create(['name' => 'Test Vendor', 'status' => 'active']);

        $item = CatalogItem::create([
            'vendor_id' => $vendor->id,
            'name' => 'Non-Hazmat Item',
            'dealer_sku' => 'SKU-004',
            'unit_of_measure' => 'EA',
            'item_weight' => 1.0,
            'unspsc_code' => '14111507',
            'country_of_origin' => 'US',
            'msds_link' => 'https://msds.example.com/non-hazmat',
            'classifications' => ['EPP'],
        ]);

        $service = new CatalogExportService();
        $spreadsheet = $service->generate($vendor, 'Test Catalog', collect([$item]));

        $tempPath = tempnam(sys_get_temp_dir(), 'catalog_test_') . '.xlsx';
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($tempPath);

        $reader = IOFactory::createReader('Xlsx');
        $reopened = $reader->load($tempPath);
        $sheet = $reopened->getActiveSheet();

        unlink($tempPath);

        $headers = [];
        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $headers[] = $cell->getValue();
            }
        }

        $rowData = $this->readRowByColumns($sheet, 2, count($headers));

        $classificationsIndex = array_search('classifications', $headers);
        $this->assertNotFalse($classificationsIndex, 'classifications column must exist');

        $classificationsValue = $rowData[$classificationsIndex];

        $this->assertStringContainsString('UNSPSC=14111507', $classificationsValue);
        $this->assertStringContainsString('Country of Origin=US', $classificationsValue);
        $this->assertStringNotContainsString('MSDS URL', $classificationsValue, 'MSDS link should NOT be in classifications when not Hazmat');
    }
}
