<?php

namespace Tests\Feature;

use App\Enums\CatalogUploadStatus;
use App\Livewire\Vendor\CatalogProcessingBanner;
use App\Livewire\Vendor\VendorCatalogUpload;
use App\Models\Catalog;
use App\Models\CatalogUpload;
use App\Models\Client;
use App\Models\Vendor;
use App\Services\Catalog\CatalogUploadNotificationAcknowledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Narrow coverage: viewing a catalog upload's report must acknowledge that
 * exact catalog_upload_id via the existing 'catalog_upload_notifications_shown'
 * session mechanism, so the catalog completion banner does not re-appear.
 */
class CatalogUploadReportAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private Client $client;

    private Catalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::create(['name' => 'XYZ Company', 'status' => 'active']);
        $this->catalog = Catalog::create(['vendor_id' => $this->vendor->id, 'name' => 'Main']);
        $this->client = Client::create([
            'vendor_id' => $this->vendor->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'status' => 'active',
            'activated_at' => now(),
        ]);
        $this->actingAs($this->client, 'client');
    }

    private function createUpload(string $status, ?Catalog $catalog = null): CatalogUpload
    {
        return CatalogUpload::create([
            'client_id' => $this->client->id,
            'vendor_id' => $this->vendor->id,
            'catalog_id' => ($catalog ?? $this->catalog)->id,
            'original_filename' => 'test.csv',
            'file_path' => 'uploads/test.csv',
            'file_type' => 'csv',
            'status' => $status,
            'total_rows' => 10,
            'success_rows' => 10,
            'processing_completed_at' => $status === CatalogUploadStatus::Completed->value ? now() : null,
        ]);
    }

    private function acknowledged(): array
    {
        return session(CatalogUploadNotificationAcknowledger::SESSION_KEY, []);
    }

    public function test_direct_report_visit_acknowledges_the_completed_upload(): void
    {
        $upload = $this->createUpload(CatalogUploadStatus::Completed->value);

        $this->get(route('vendor.catalog-upload.summary', [
            'catalogId' => $this->catalog->id,
            'catalogUpload' => $upload->id,
        ]))->assertOk();

        Livewire::test(VendorCatalogUpload::class, [
            'catalogId' => $this->catalog->id,
            'uploadId' => $upload->id,
        ])->assertSet('step', 'summary');

        $this->assertContains($upload->id, $this->acknowledged());
    }

    public function test_short_upload_completion_acknowledges_the_upload(): void
    {
        $upload = $this->createUpload(CatalogUploadStatus::Processing->value);

        Livewire::test(VendorCatalogUpload::class, [
            'catalogId' => $this->catalog->id,
            'uploadId' => null,
        ])->set('catalogUploadId', $upload->id)
            ->assertSet('step', 'upload');

        $this->assertEmpty($this->acknowledged(), 'Processing state must not acknowledge.');

        $upload->update(['status' => CatalogUploadStatus::Completed]);

        Livewire::test(VendorCatalogUpload::class, [
            'catalogId' => $this->catalog->id,
            'uploadId' => null,
        ])->set('catalogUploadId', $upload->id)
            ->call('refreshStatus')
            ->assertSet('step', 'summary');

        $this->assertContains($upload->id, $this->acknowledged());
    }

    public function test_processing_upload_is_not_acknowledged_on_mount(): void
    {
        $upload = $this->createUpload(CatalogUploadStatus::Processing->value);

        Livewire::test(VendorCatalogUpload::class, [
            'catalogId' => $this->catalog->id,
            'uploadId' => $upload->id,
        ]);

        $this->assertEmpty($this->acknowledged());
    }

    public function test_banner_view_report_still_acknowledges_and_redirects(): void
    {
        $upload = $this->createUpload(CatalogUploadStatus::Completed->value);

        Livewire::test(CatalogProcessingBanner::class, ['catalogId' => $this->catalog->id])
            ->call('viewReport')
            ->assertRedirect(route('vendor.catalog-upload.summary', [
                'catalogId' => $this->catalog->id,
                'catalogUpload' => $upload->id,
            ]));

        $this->assertContains($upload->id, $this->acknowledged());

        $banner = Livewire::test(CatalogProcessingBanner::class, ['catalogId' => $this->catalog->id]);
        $this->assertFalse($banner->get('showCompletionNotification'));
    }

    public function test_banner_dismiss_still_acknowledges(): void
    {
        $upload = $this->createUpload(CatalogUploadStatus::Completed->value);

        Livewire::test(CatalogProcessingBanner::class, ['catalogId' => $this->catalog->id])
            ->assertSet('showCompletionNotification', true)
            ->call('dismissCompletionNotification');

        $this->assertContains($upload->id, $this->acknowledged());

        $banner = Livewire::test(CatalogProcessingBanner::class, ['catalogId' => $this->catalog->id]);
        $this->assertFalse($banner->get('showCompletionNotification'));
    }

    public function test_viewing_report_a_does_not_suppress_catalog_b_notification(): void
    {
        $catalogB = Catalog::create(['vendor_id' => $this->vendor->id, 'name' => 'Other']);
        $uploadA = $this->createUpload(CatalogUploadStatus::Completed->value, $this->catalog);
        $uploadB = $this->createUpload(CatalogUploadStatus::Completed->value, $catalogB);

        // View report A.
        Livewire::test(VendorCatalogUpload::class, [
            'catalogId' => $this->catalog->id,
            'uploadId' => $uploadA->id,
        ]);

        $this->assertNotContains($uploadB->id, $this->acknowledged());

        $bannerB = Livewire::test(CatalogProcessingBanner::class, ['catalogId' => $catalogB->id]);
        $this->assertTrue($bannerB->get('showCompletionNotification'));
        $this->assertSame($uploadB->id, $bannerB->get('completedUpload')->id);

        $bannerA = Livewire::test(CatalogProcessingBanner::class, ['catalogId' => $this->catalog->id]);
        $this->assertFalse($bannerA->get('showCompletionNotification'));
    }

    public function test_repeated_acknowledgement_does_not_duplicate_the_id(): void
    {
        $upload = $this->createUpload(CatalogUploadStatus::Completed->value);

        CatalogUploadNotificationAcknowledger::acknowledge($upload->id);
        CatalogUploadNotificationAcknowledger::acknowledge($upload->id);
        CatalogUploadNotificationAcknowledger::acknowledge($upload->id);

        $this->assertCount(1, $this->acknowledged());
        $this->assertEqualsCanonicalizing([$upload->id], $this->acknowledged());
    }
}
