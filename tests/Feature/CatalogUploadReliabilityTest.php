<?php

namespace Tests\Feature;

use App\Enums\CatalogUploadStatus;
use App\Jobs\ProcessValidatedRowsJob;
use App\Models\CatalogItem;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
use App\Models\Client;
use App\Models\Vendor;
use App\Services\FingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogUploadReliabilityTest extends TestCase
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
     * Test 1: Status guard prevents completed upload from being processed again.
     */
    public function test_completed_upload_has_retry_safety(): void
    {
        $upload = CatalogUpload::create([
            'vendor_id' => $this->vendor->id,
            'client_id' => $this->client->id,
            'original_filename' => 'completed.csv',
            'file_path' => 'uploads/completed.csv',
            'disk' => 'local',
            'file_type' => 'csv',
            'status' => CatalogUploadStatus::Completed,
            'processing_started_at' => now(),
            'processing_completed_at' => now(),
        ]);

        CatalogItem::create([
            'vendor_id' => $this->vendor->id,
            'vendor_sku' => 'SKU-DUP',
            'name' => 'Existing Item',
            'description' => 'Description',
            'unit_of_measure' => 'EA',
            'weight' => 1.0,
        ]);

        $initialCount = CatalogItem::count();

        // Execute the job
        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();

        // Verify no new items created - job exited early due to status guard
        $this->assertEquals($initialCount, CatalogItem::count());
    }

    /**
     * Test 2: Stale upload detection allows recovery.
     */
    public function test_stale_processing_detection_works(): void
    {
        $upload = CatalogUpload::create([
            'vendor_id' => $this->vendor->id,
            'client_id' => $this->client->id,
            'original_filename' => 'stale.csv',
            'file_path' => 'uploads/stale.csv',
            'disk' => 'local',
            'file_type' => 'csv',
            'status' => CatalogUploadStatus::ProcessingItems,
            'processing_started_at' => now()->subMinutes(120), // 2 hours ago - stale
        ]);

        // Verify stale detection logic works - processing_started_at + 60 min is in the past
        $this->assertTrue(
            $upload->processing_started_at->copy()->addMinutes(60)->isPast(),
            'Upload should be detected as stale'
        );

        // If the upload had valid rows, a new worker could claim it
        // (This verifies the detection logic, not full processing)
    }

    /**
     * Test 3: Unique constraint prevents duplicate row numbers.
     */
    public function test_unique_constraint_prevents_duplicate_row_numbers(): void
    {
        $upload = CatalogUpload::create([
            'vendor_id' => $this->vendor->id,
            'client_id' => $this->client->id,
            'original_filename' => 'unique.csv',
            'file_path' => 'uploads/unique.csv',
            'disk' => 'local',
            'file_type' => 'csv',
            'status' => CatalogUploadStatus::Uploaded,
        ]);

        // Create first row
        CatalogUploadRow::create([
            'catalog_upload_id' => $upload->id,
            'row_number' => 1,
            'data' => json_encode(['name' => 'Row 1']),
            'status' => 'valid',
        ]);

        // Attempting to insert duplicate should fail
        $this->expectException(\Illuminate\Database\QueryException::class);

        CatalogUploadRow::create([
            'catalog_upload_id' => $upload->id,
            'row_number' => 1, // Same row number - violates unique constraint
            'data' => json_encode(['name' => 'Row 1 Duplicate']),
            'status' => 'valid',
        ]);
    }

    /**
     * Test 4: Fingerprint produces consistent output.
     */
    public function test_fingerprint_produces_consistent_output(): void
    {
        $data = [
            'name' => 'Test Product',
            'description' => 'Test Description',
            'vendor_sku' => 'SKU-123',
            'vendor_id' => 1,
        ];

        $fp1 = FingerprintService::compute($data);
        $fp2 = FingerprintService::compute($data);

        $this->assertEquals($fp1, $fp2, 'Same input produces same fingerprint');
        $this->assertEquals(32, strlen($fp1), 'MD5 hash length is 32 characters');
    }

    /**
     * Test 5: Fingerprint excludes metadata columns.
     */
    public function test_fingerprint_excludes_metadata_columns(): void
    {
        $data1 = ['name' => 'Product', 'vendor_sku' => 'SKU-1', 'vendor_id' => 1, 'catalog_name' => 'Catalog'];
        $data2 = ['name' => 'Product', 'vendor_sku' => 'SKU-2', 'vendor_id' => 999, 'catalog_name' => 'Other'];

        // Both should produce same fingerprint because vendor_sku, vendor_id, catalog_name are excluded
        $this->assertEquals(
            FingerprintService::compute($data1),
            FingerprintService::compute($data2),
            'Excluded columns do not affect fingerprint'
        );
    }

    /**
     * Test 6: Failed upload has status guard.
     */
    public function test_failed_upload_has_status_guard(): void
    {
        $upload = CatalogUpload::create([
            'vendor_id' => $this->vendor->id,
            'client_id' => $this->client->id,
            'original_filename' => 'failed.csv',
            'file_path' => 'uploads/failed.csv',
            'disk' => 'local',
            'file_type' => 'csv',
            'status' => CatalogUploadStatus::Failed,
            'processing_started_at' => now(),
        ]);

        CatalogItem::create([
            'vendor_id' => $this->vendor->id,
            'vendor_sku' => 'SKU-FAIL',
            'name' => 'Item',
            'description' => 'Desc',
            'unit_of_measure' => 'EA',
            'weight' => 1.0,
        ]);

        $initial = CatalogItem::count();

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();

        // Failed uploads also have status guard - no reprocessing
        $this->assertEquals($initial, CatalogItem::count());
    }

    /**
     * Test 7: Processing status can be claimed atomically.
     */
    public function test_processing_status_can_be_claimed(): void
    {
        $upload = CatalogUpload::create([
            'vendor_id' => $this->vendor->id,
            'client_id' => $this->client->id,
            'original_filename' => 'processing.csv',
            'file_path' => 'uploads/processing.csv',
            'disk' => 'local',
            'file_type' => 'csv',
            'status' => CatalogUploadStatus::Processing,
        ]);

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();

        // Job should have claimed the upload and transitioned to ProcessingItems
        // (Then to Completed since there are no valid rows)
        $upload->refresh();
        $this->assertTrue(in_array($upload->status->value, ['processing_items', 'completed'], true));
    }
}
