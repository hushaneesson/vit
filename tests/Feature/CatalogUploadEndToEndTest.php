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

    /**
     * Helper: Create a valid row with all required fields.
     */
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

    /**
     * Helper: Create an upload with all required fields.
     */
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

    /**
     * Test 1: Status transitions through the pipeline.
     * Tests that ProcessCatalogUploadJob and ProcessValidatedRowsJob properly transition statuses.
     */
    public function test_status_transitions_through_pipeline(): void
    {
        $upload = $this->createUpload([
            'status' => CatalogUploadStatus::Processing,
        ]);

        // Create valid rows for the job to process
        $this->createValidRow($upload, 'SKU-001', 'Item One');
        $this->createValidRow($upload, 'SKU-002', 'Item Two');

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();

        $upload->refresh();

        // Status should be Completed after processing
        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        $this->assertNotNull($upload->processing_completed_at);
        $this->assertEquals(2, $upload->success_rows);
        $this->assertEquals(2, $upload->total_rows);
        $this->assertCount(2, CatalogItem::where('vendor_id', $this->vendor->id)->get());
    }

    /**
     * Test 2: Failed upload guard - ProcessValidatedRowsJob exits early on Failed status.
     */
    public function test_failed_upload_guard_prevents_processing(): void
    {
        $upload = $this->createUpload([
            'status' => CatalogUploadStatus::Failed,
            'processing_started_at' => now(),
            'processing_completed_at' => now(),
            'failure_reason' => 'Previous processing failed',
        ]);

        // Create a valid row (should be ignored due to guard)
        $this->createValidRow($upload, 'SKU-SKIP', 'Should Skip');

        $initialCount = CatalogItem::count();

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();

        // No items created due to status guard
        $this->assertEquals($initialCount, CatalogItem::count());
    }

    /**
     * Test 3: Stale job recovery - ProcessingItems status older than timeout is recoverable.
     */
    public function test_stale_processing_job_is_recovered(): void
    {
        $upload = $this->createUpload([
            'status' => CatalogUploadStatus::ProcessingItems,
            'processing_started_at' => now()->subHours(2), // Stale - started 2 hours ago
        ]);

        $this->createValidRow($upload, 'SKU-STALE', 'Stale Item');

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();

        $upload->refresh();

        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        $this->assertCount(1, CatalogItem::where('vendor_id', $this->vendor->id)->get());
    }

    /**
     * Test 4: ProcessCatalogUploadJob fails gracefully with bad file path.
     */
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
            // Expected - file doesn't exist
        }

        $upload->refresh();

        $this->assertEquals(CatalogUploadStatus::Failed, $upload->status);
        $this->assertNotNull($upload->failure_reason);
        $this->assertNotNull($upload->processing_completed_at);
    }

    /**
     * Test 5: Mixed valid and invalid rows are processed correctly.
     */
    public function test_mixed_valid_and_invalid_rows(): void
    {
        $upload = $this->createUpload([
            'status' => CatalogUploadStatus::Processing,
        ]);

        $this->createValidRow($upload, 'SKU-VALID', 'Valid Item');

        // Create invalid row
        CatalogUploadRow::create([
            'catalog_upload_id' => $upload->id,
            'row_number' => 2,
            'data' => [
                'seller_sku' => 'SKU-INVALID',
            ],
            'raw_data' => json_encode([]),
            'status' => 'invalid',
            'errors' => [['field_key' => 'name', 'message' => 'Item Name is required.']],
        ]);

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();

        $upload->refresh();

        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        $this->assertEquals(1, $upload->success_rows);
        $this->assertEquals(1, $upload->error_rows);
        $this->assertEquals(2, $upload->total_rows);
    }

    /**
     * Test 6: Existing item with same SKU is updated when data differs.
     */
    public function test_existing_item_with_same_sku_is_updated(): void
    {
        $upload = $this->createUpload([
            'status' => CatalogUploadStatus::Processing,
        ]);

        // Pre-create an item with the same SKU
        $existingItem = CatalogItem::create([
            'vendor_id' => $this->vendor->id,
            'vendor_sku' => 'SKU-UPDATE',
            'name' => 'Original Name',
            'description' => 'Original description',
            'unit_of_measure' => 'EA',
            'weight' => 1.0,
            'list_price' => 10.00,
            'selling_price' => 8.00,
            'unspsc_code' => '14111507',
        ]);

        // Create a row with UPDATED data (different name)
        $this->createValidRow($upload, 'SKU-UPDATE', 'Updated Name', [
            'list_price' => 15.99,
        ]);

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();

        $upload->refresh();

        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        $this->assertEquals(1, $upload->updated_rows);

        $existingItem->refresh();
        $this->assertEquals('Updated Name', $existingItem->name);
    }

    /**
     * Test 7: Duplicate fingerprint detection - same content skips creation.
     */
    public function test_duplicate_fingerprint_skips_creation(): void
    {
        $upload = $this->createUpload([
            'status' => CatalogUploadStatus::Processing,
        ]);

        // Pre-create an item - the job will compute fingerprint on these fields
        $existingItem = CatalogItem::create([
            'vendor_id' => $this->vendor->id,
            'vendor_sku' => 'SKU-DUP',
            'name' => 'Duplicate Widget',
            'description' => 'Test description',
            'unit_of_measure' => 'EA',
            'weight' => 1.0,
            'list_price' => 10.00,
            'selling_price' => 8.00,
            'unspsc_code' => '14111507',
        ]);

        // Create a row with EXACT same content data (will compute to same fingerprint)
        $this->createValidRow($upload, 'SKU-DUP', 'Duplicate Widget', [
            'item_weight' => 1.0,
        ]);

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();

        $upload->refresh();

        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        // Item count unchanged (skipped due to duplicate fingerprint)
        $this->assertCount(1, CatalogItem::where('vendor_id', $this->vendor->id)->get());
        $this->assertEquals(0, $upload->success_rows);
        $this->assertEquals(1, $upload->skipped_rows);
    }

    /**
     * Test 8: Race condition handling - SKU exists between fingerprint check and insert.
     */
    public function test_race_condition_handling(): void
    {
        $upload = $this->createUpload([
            'status' => CatalogUploadStatus::Processing,
        ]);

        // Pre-create an item with different content (won't match fingerprint) but same SKU
        $existingItem = CatalogItem::create([
            'vendor_id' => $this->vendor->id,
            'vendor_sku' => 'SKU-RACE',
            'name' => 'Original Race Item',
            'description' => 'Completely different data',
            'unit_of_measure' => 'BX',
            'weight' => 5.0,
            'list_price' => 100.00,
            'selling_price' => 90.00,
            'unspsc_code' => '99999999',
        ]);

        $this->createValidRow($upload, 'SKU-RACE', 'Race Test Item');

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();

        $upload->refresh();

        // Should update the existing item
        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        $this->assertEquals(1, CatalogItem::where('vendor_id', $this->vendor->id)->count());
        $this->assertEquals(1, $upload->updated_rows);
    }

    /**
     * Test 9: No valid rows results in Completed status with zero success.
     */
    public function test_no_valid_rows_results_in_completed_status(): void
    {
        $upload = $this->createUpload([
            'status' => CatalogUploadStatus::Processing,
        ]);

        // Create only invalid rows
        CatalogUploadRow::create([
            'catalog_upload_id' => $upload->id,
            'row_number' => 1,
            'data' => [
                'seller_sku' => 'SKU-BAD',
            ],
            'raw_data' => json_encode([]),
            'status' => 'invalid',
            'errors' => [['field_key' => 'name', 'message' => 'Item Name is required.']],
        ]);

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();

        $upload->refresh();

        $this->assertEquals(CatalogUploadStatus::Completed, $upload->status);
        $this->assertEquals(0, $upload->success_rows);
        $this->assertEquals(1, $upload->error_rows);
    }
}
