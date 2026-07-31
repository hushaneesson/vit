<?php

namespace Tests\Feature;

use App\Enums\CatalogUploadStatus;
use App\Jobs\ProcessCatalogUploadJob;
use App\Jobs\ProcessValidatedRowsJob;
use App\Models\CatalogItem;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
use App\Models\Client;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogUploadEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected Vendor $vendor;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create(['status' => 'active']);
        $this->client = Client::factory()->create([
            'vendor_id' => $this->vendor->id,
            'status' => 'active',
        ]);
    }

    private function createValidRow(CatalogUpload $upload, string $sku, string $name, array $overrides = []): CatalogUploadRow
    {
        return CatalogUploadRow::create([
            'catalog_upload_id' => $upload->id,
            'row_number' => $upload->rows()->max('row_number') + 1,
            'data' => array_merge([
                'seller_sku' => $sku,
                'name' => $name,
                'description' => 'Test description',
                'unit_of_measure' => 'EA',
                'list_price' => 10.00,
                'selling_price_per_unit' => 8.00,
                'unspsc_code' => '14111507',
            ], $overrides),
            'raw_data' => json_encode([]),
            'status' => 'valid',
        ]);
    }

    private function createUpload(array $attributes = []): CatalogUpload
    {
        return CatalogUpload::create(array_merge([
            'vendor_id' => $this->vendor->id,
            'client_id' => $this->client->id,
            'original_filename' => 'test-upload.csv',
            'file_path' => 'catalog-uploads/test.csv',
            'disk' => 'local',
            'file_type' => 'csv',
        ], $attributes));
    }

    public function test_status_transitions_through_pipeline(): void
    {
        $upload = $this->createUpload(['status' => CatalogUploadStatus::Processing]);
        $this->createValidRow($upload, 'SKU-001', 'Item One');
        $this->createValidRow($upload, 'SKU-002', 'Item Two');

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();
        $upload->refresh();

        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        $this->assertNotNull($upload->processing_completed_at);
        $this->assertEquals(2, $upload->created_rows);
        $this->assertEquals(0, $upload->updated_rows);
        $this->assertEquals(2, $upload->success_rows);
        $this->assertEquals(2, $upload->total_rows);
        $this->assertCount(2, CatalogItem::where('vendor_id', $this->vendor->id)->get());
    }

    public function test_failed_upload_guard_prevents_processing(): void
    {
        $upload = $this->createUpload([
            'status' => CatalogUploadStatus::Failed,
            'processing_started_at' => now(),
            'processing_completed_at' => now(),
            'failure_reason' => 'Previous processing failed',
        ]);
        $this->createValidRow($upload, 'SKU-SKIP', 'Should Skip');
        $initialCount = CatalogItem::count();

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();

        $this->assertEquals($initialCount, CatalogItem::count());
    }

    public function test_stale_processing_job_is_recovered(): void
    {
        $upload = $this->createUpload([
            'status' => CatalogUploadStatus::ProcessingItems,
            'processing_started_at' => now()->subHours(2),
        ]);
        $this->createValidRow($upload, 'SKU-STALE', 'Stale Item');

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();
        $upload->refresh();

        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        $this->assertCount(1, CatalogItem::where('vendor_id', $this->vendor->id)->get());
    }

    public function test_failed_upload_sets_status_and_logs_reason(): void
    {
        $upload = $this->createUpload([
            'original_filename' => 'missing-file.csv',
            'file_path' => '/nonexistent/path/to/file.csv',
            'status' => CatalogUploadStatus::Queued,
        ]);
        $job = new ProcessCatalogUploadJob($upload->id);
        try {
            $job->handle(app(\App\Services\CatalogRowValidator::class));
        } catch (\Throwable $e) {
        }
        $upload->refresh();

        $this->assertEquals(CatalogUploadStatus::Failed, $upload->status);
        $this->assertNotNull($upload->failure_reason);
        $this->assertNotNull($upload->processing_completed_at);
    }

    public function test_mixed_valid_and_invalid_rows(): void
    {
        $upload = $this->createUpload(['status' => CatalogUploadStatus::Processing]);
        $this->createValidRow($upload, 'SKU-VALID', 'Valid Item');
        CatalogUploadRow::create([
            'catalog_upload_id' => $upload->id,
            'row_number' => 2,
            'data' => ['seller_sku' => 'SKU-INVALID', 'list_price' => 'not-a-number'],
            'raw_data' => json_encode([]),
            'status' => 'invalid',
            'errors' => [['field_key' => 'list_price', 'message' => 'List Price must be a decimal number.']],
        ]);

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();
        $upload->refresh();

        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        $this->assertEquals(1, $upload->created_rows);
        $this->assertEquals(0, $upload->updated_rows);
        $this->assertEquals(1, $upload->success_rows);
        $this->assertEquals(1, $upload->invalid_rows);
        $this->assertEquals(2, $upload->total_rows);
    }

    public function test_existing_item_with_same_sku_is_updated(): void
    {
        $upload = $this->createUpload(['status' => CatalogUploadStatus::Processing]);
        $existingItem = CatalogItem::create([
            'vendor_id' => $this->vendor->id,
            'seller_sku' => 'SKU-UPDATE',
            'name' => 'Original Name',
            'description' => 'Original description',
            'unit_of_measure' => 'EA',
            'item_weight' => 1.0,
            'list_price' => 10.00,
            'selling_price_per_unit' => 8.00,
            'unspsc_code' => '14111507',
        ]);
        $this->createValidRow($upload, 'SKU-UPDATE', 'Updated Name', ['list_price' => 15.99]);

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();
        $upload->refresh();

        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        $this->assertEquals(0, $upload->created_rows);
        $this->assertEquals(1, $upload->updated_rows);
        $this->assertEquals(0, $upload->unchanged_rows);
        $existingItem->refresh();
        $this->assertEquals('Updated Name', $existingItem->name);
    }

    public function test_unchanged_item_with_same_sku_is_not_recreated(): void
    {
        $upload = $this->createUpload(['status' => CatalogUploadStatus::Processing]);
        CatalogItem::create([
            'vendor_id' => $this->vendor->id,
            'seller_sku' => 'SKU-DUP',
            'name' => 'Duplicate Widget',
            'description' => 'Test description',
            'unit_of_measure' => 'EA',
            'item_weight' => 1.0,
            'list_price' => 10.00,
            'selling_price_per_unit' => 8.00,
            'unspsc_code' => '14111507',
        ]);
        $this->createValidRow($upload, 'SKU-DUP', 'Duplicate Widget', ['item_weight' => 1.0]);

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();
        $upload->refresh();

        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        $this->assertCount(1, CatalogItem::where('vendor_id', $this->vendor->id)->get());
        $this->assertEquals(0, $upload->created_rows);
        $this->assertEquals(0, $upload->updated_rows);
        $this->assertEquals(1, $upload->success_rows);
        $this->assertEquals(1, $upload->unchanged_rows);
    }

    public function test_race_condition_handling(): void
    {
        $upload = $this->createUpload(['status' => CatalogUploadStatus::Processing]);
        CatalogItem::create([
            'vendor_id' => $this->vendor->id,
            'seller_sku' => 'SKU-RACE',
            'name' => 'Original Race Item',
            'description' => 'Completely different data',
            'unit_of_measure' => 'BX',
            'item_weight' => 5.0,
            'list_price' => 100.00,
            'selling_price_per_unit' => 90.00,
            'unspsc_code' => '99999999',
        ]);
        $this->createValidRow($upload, 'SKU-RACE', 'Race Test Item');

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();
        $upload->refresh();

        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        $this->assertEquals(1, CatalogItem::where('vendor_id', $this->vendor->id)->count());
        $this->assertEquals(0, $upload->created_rows);
        $this->assertEquals(1, $upload->updated_rows);
        $this->assertEquals(0, $upload->unchanged_rows);
    }

    public function test_no_valid_rows_results_in_completed_status(): void
    {
        $upload = $this->createUpload(['status' => CatalogUploadStatus::Processing]);
        CatalogUploadRow::create([
            'catalog_upload_id' => $upload->id,
            'row_number' => 1,
            'data' => ['seller_sku' => 'SKU-BAD', 'list_price' => 'not-a-number'],
            'raw_data' => json_encode([]),
            'status' => 'invalid',
            'errors' => [['field_key' => 'list_price', 'message' => 'List Price must be a decimal number.']],
        ]);

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();
        $upload->refresh();

        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        $this->assertEquals(0, $upload->created_rows);
        $this->assertEquals(0, $upload->updated_rows);
        $this->assertEquals(0, $upload->success_rows);
        $this->assertEquals(1, $upload->invalid_rows);
    }

    public function test_repeated_identical_upload_reports_unchanged(): void
    {
        $uploadOne = $this->createUpload(['status' => CatalogUploadStatus::Processing]);
        $this->createValidRow($uploadOne, 'SKU-A', 'Item A');
        $this->createValidRow($uploadOne, 'SKU-B', 'Item B');
        $this->createValidRow($uploadOne, 'SKU-C', 'Item C');
        $this->createValidRow($uploadOne, 'SKU-D', 'Item D');
        $this->createValidRow($uploadOne, 'SKU-E', 'Item E');

        (new ProcessValidatedRowsJob($uploadOne->id))->handle();
        $uploadOne->refresh();

        $this->assertEquals(5, $uploadOne->created_rows);
        $this->assertEquals(0, $uploadOne->updated_rows);
        $this->assertEquals(0, $uploadOne->unchanged_rows);
        $this->assertCount(5, CatalogItem::where('vendor_id', $this->vendor->id)->get());

        $uploadTwo = $this->createUpload(['status' => CatalogUploadStatus::Processing]);
        $this->createValidRow($uploadTwo, 'SKU-A', 'Item A');
        $this->createValidRow($uploadTwo, 'SKU-B', 'Item B');
        $this->createValidRow($uploadTwo, 'SKU-C', 'Item C');
        $this->createValidRow($uploadTwo, 'SKU-D', 'Item D');
        $this->createValidRow($uploadTwo, 'SKU-E', 'Item E');

        (new ProcessValidatedRowsJob($uploadTwo->id))->handle();
        $uploadTwo->refresh();

        $this->assertEquals(0, $uploadTwo->created_rows, 'No new items should be created');
        $this->assertEquals(0, $uploadTwo->updated_rows, 'No items should be updated');
        $this->assertEquals(5, $uploadTwo->unchanged_rows, 'All 5 items should be unchanged');
        $this->assertCount(
            5,
            CatalogItem::where('vendor_id', $this->vendor->id)->get(),
            'Total items should remain 5'
        );
    }

    public function test_repeated_upload_with_price_change_reports_update(): void
    {
        $uploadOne = $this->createUpload(['status' => CatalogUploadStatus::Processing]);
        $this->createValidRow($uploadOne, 'SKU-A', 'Item A', ['list_price' => 10.00]);
        $this->createValidRow($uploadOne, 'SKU-B', 'Item B', ['list_price' => 20.00]);
        $this->createValidRow($uploadOne, 'SKU-C', 'Item C', ['list_price' => 30.00]);
        $this->createValidRow($uploadOne, 'SKU-D', 'Item D', ['list_price' => 40.00]);
        $this->createValidRow($uploadOne, 'SKU-E', 'Item E', ['list_price' => 50.00]);

        (new ProcessValidatedRowsJob($uploadOne->id))->handle();
        $uploadOne->refresh();

        $this->assertEquals(5, $uploadOne->created_rows);

        $uploadTwo = $this->createUpload(['status' => CatalogUploadStatus::Processing]);
        $this->createValidRow($uploadTwo, 'SKU-A', 'Item A', ['list_price' => 10.00]);
        $this->createValidRow($uploadTwo, 'SKU-B', 'Item B', ['list_price' => 20.00]);
        $this->createValidRow($uploadTwo, 'SKU-C', 'Item C', ['list_price' => 35.00]);
        $this->createValidRow($uploadTwo, 'SKU-D', 'Item D', ['list_price' => 40.00]);
        $this->createValidRow($uploadTwo, 'SKU-E', 'Item E', ['list_price' => 50.00]);

        (new ProcessValidatedRowsJob($uploadTwo->id))->handle();
        $uploadTwo->refresh();

        $this->assertEquals(0, $uploadTwo->created_rows, 'No new items should be created');
        $this->assertEquals(1, $uploadTwo->updated_rows, 'One item should be updated (SKU-C price changed)');
        $this->assertEquals(4, $uploadTwo->unchanged_rows, 'Four items should be unchanged');
        $this->assertCount(
            5,
            CatalogItem::where('vendor_id', $this->vendor->id)->get(),
            'Total items should remain 5'
        );

        $itemC = CatalogItem::where('vendor_id', $this->vendor->id)
            ->where('seller_sku', 'SKU-C')
            ->first();
        $this->assertNotNull($itemC);
        $this->assertEquals(35.00, (float) $itemC->list_price);
    }

    public function test_incomplete_csv_imports_successfully_with_null_fields(): void
    {
        $upload = $this->createUpload(['status' => CatalogUploadStatus::Processing]);

        CatalogUploadRow::create([
            'catalog_upload_id' => $upload->id,
            'row_number' => 1,
            'data' => [
                'seller_sku' => 'SKU-INCOMPLETE',
                'name' => 'Incomplete Item',
                'list_price' => 10.00,
            ],
            'raw_data' => json_encode([]),
            'status' => 'valid',
        ]);

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();
        $upload->refresh();

        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        $this->assertEquals(1, $upload->created_rows);
        $this->assertEquals(0, $upload->updated_rows);
        $this->assertEquals(0, $upload->unchanged_rows);
        $this->assertEquals(1, $upload->success_rows);
        $this->assertEquals(0, $upload->invalid_rows);

        $item = CatalogItem::where('vendor_id', $this->vendor->id)
            ->where('seller_sku', 'SKU-INCOMPLETE')
            ->first();

        $this->assertNotNull($item);
        $this->assertSame('Incomplete Item', $item->name);
        $this->assertSame('SKU-INCOMPLETE', $item->seller_sku);
        $this->assertNull($item->description);
        $this->assertNull($item->manufacturer);
        $this->assertNull($item->brand_name);
        $this->assertNull($item->unspsc_code);
        $this->assertSame('incomplete', $item->status);
    }
}
