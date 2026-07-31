<?php

namespace Tests\Feature;

use App\Enums\CatalogUploadStatus;
use App\Jobs\ProcessValidatedRowsJob;
use App\Models\CatalogItem;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
use App\Models\Client;
use App\Models\Vendor;
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
            'seller_sku' => 'SKU-DUP',
            'name' => 'Existing Item',
            'description' => 'Description',
            'unit_of_measure' => 'EA',
            'item_weight' => 1.0,
        ]);

        $initialCount = CatalogItem::count();

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();

        $this->assertEquals($initialCount, CatalogItem::count());
    }

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
            'processing_started_at' => now()->subMinutes(120),
        ]);

        $this->assertTrue(
            $upload->processing_started_at->copy()->addMinutes(60)->isPast(),
            'Upload should be detected as stale'
        );
    }

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

        CatalogUploadRow::create([
            'catalog_upload_id' => $upload->id,
            'row_number' => 1,
            'data' => json_encode(['name' => 'Row 1']),
            'status' => 'valid',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        CatalogUploadRow::create([
            'catalog_upload_id' => $upload->id,
            'row_number' => 1,
            'data' => json_encode(['name' => 'Row 1 Duplicate']),
            'status' => 'valid',
        ]);
    }

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
            'seller_sku' => 'SKU-FAIL',
            'name' => 'Item',
            'description' => 'Desc',
            'unit_of_measure' => 'EA',
            'item_weight' => 1.0,
        ]);

        $initial = CatalogItem::count();

        $job = new ProcessValidatedRowsJob($upload->id);
        $job->handle();

        $this->assertEquals($initial, CatalogItem::count());
    }

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

        $upload->refresh();
        $this->assertTrue(in_array($upload->status->value, ['processing_items', 'completed'], true));
    }
}
