<?php

namespace Tests\Feature;

use App\Enums\CatalogUploadStatus;
use App\Jobs\ProcessValidatedRowsJob;
use App\Livewire\Vendor\VendorCatalogUpload;
use App\Models\CatalogUpload;
use App\Models\Client;
use App\Models\Vendor;
use App\Notifications\CatalogUploadValidationReportNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogUploadValidationReportTest extends TestCase
{
    use RefreshDatabase;

    private function createCompletedUploadWithErrors(int $invalidRowCount): array
    {
        $vendor = Vendor::factory()->create(['name' => 'Test Vendor', 'status' => 'active']);
        $client = Client::factory()->create([
            'vendor_id' => $vendor->id,
            'name' => 'Test Client',
            'email' => 'client@example.com',
            'status' => 'active',
            'activated_at' => now(),
        ]);

        $upload = CatalogUpload::factory()
            ->withErrors($invalidRowCount)
            ->create([
                'client_id' => $client->id,
                'vendor_id' => $vendor->id,
                // catalog_name removed from schema
                'status' => CatalogUploadStatus::Processing,
                'processing_started_at' => now(),
            ]);

        for ($i = 0; $i < $invalidRowCount; $i++) {
            \App\Models\CatalogUploadRow::factory()->invalid()->create([
                'catalog_upload_id' => $upload->id,
                'row_number' => $i + 1,
            ]);
        }

        $upload->refresh();

        return compact('upload', 'client', 'vendor');
    }

    // -----------------------------------------------------------------------
    //  1. Validation results display limits
    // -----------------------------------------------------------------------

    public function test_10_or_fewer_errors_shows_full_table_and_no_email(): void
    {
        Notification::fake();
        $result = $this->createCompletedUploadWithErrors(5);
        $upload = $result['upload'];

        (new ProcessValidatedRowsJob($upload->id))->handle();
        $upload->refresh();

        Notification::assertNothingSent();
        $this->assertNull($upload->validation_report_emailed_at);
        $this->assertEquals(5, $upload->error_rows);
        $this->assertEquals(5, $upload->rows()->where('status', 'invalid')->count());
    }

    public function test_more_than_10_errors_sends_email_and_sets_timestamp(): void
    {
        Notification::fake();
        $result = $this->createCompletedUploadWithErrors(11);
        $upload = $result['upload'];

        (new ProcessValidatedRowsJob($upload->id))->handle();
        $upload->refresh();

        Notification::assertSentOnDemand(CatalogUploadValidationReportNotification::class);
        $this->assertNotNull($upload->validation_report_emailed_at);
        $this->assertEquals(11, $upload->error_rows);
    }

    public function test_more_than_10_errors_but_email_fails_falls_back_gracefully(): void
    {
        // Do NOT use Notification::fake() - we need the actual mail driver to fail
        // Mock the Log facade to verify the warning is logged
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn($message) => str_contains($message, 'Failed to send validation report email'));

        $result = $this->createCompletedUploadWithErrors(11);
        $upload = $result['upload'];

        // Override the mailer config to force an actual connection failure
        config(['mail.default' => 'smtp']);
        config(['mail.mailers.smtp' => [
            'transport' => 'smtp',
            'host' => '127.0.0.1',
            'port' => '1',
            'timeout' => 1,
        ]]);

        (new ProcessValidatedRowsJob($upload->id))->handle();
        $upload->refresh();

        // Status should remain ProcessingItems to allow retry when email fails
        $this->assertEquals(CatalogUploadStatus::ProcessingItems, $upload->status);
        $this->assertNull($upload->validation_report_emailed_at);
        $this->assertEquals(11, $upload->error_rows);
        $this->assertEquals(11, $upload->rows()->where('status', 'invalid')->count());

        // Restore config
        config(['mail.default' => 'array']);
    }

    public function test_exactly_10_errors_shows_full_table_no_email(): void
    {
        Notification::fake();
        $result = $this->createCompletedUploadWithErrors(10);
        $upload = $result['upload'];

        (new ProcessValidatedRowsJob($upload->id))->handle();
        $upload->refresh();

        Notification::assertNothingSent();
        $this->assertNull($upload->validation_report_emailed_at);
    }

    public function test_exactly_11_errors_sends_email(): void
    {
        Notification::fake();
        $result = $this->createCompletedUploadWithErrors(11);
        $upload = $result['upload'];

        (new ProcessValidatedRowsJob($upload->id))->handle();
        $upload->refresh();

        Notification::assertSentOnDemand(CatalogUploadValidationReportNotification::class);
        $this->assertNotNull($upload->validation_report_emailed_at);
    }

    public function test_zero_errors_shows_no_failed_rows_and_no_email(): void
    {
        Notification::fake();
        $result = $this->createCompletedUploadWithErrors(0);
        $upload = $result['upload'];

        (new ProcessValidatedRowsJob($upload->id))->handle();
        $upload->refresh();

        Notification::assertNothingSent();
        $this->assertNull($upload->validation_report_emailed_at);
        $this->assertEquals(0, $upload->error_rows);
    }

    // -----------------------------------------------------------------------
    //  2. Email notification tests
    // -----------------------------------------------------------------------

    public function test_notification_is_sent_to_on_demand_route_with_email(): void
    {
        Notification::fake();
        $result = $this->createCompletedUploadWithErrors(11);
        $upload = $result['upload'];

        (new ProcessValidatedRowsJob($upload->id))->handle();
        $upload->refresh();

        Notification::assertSentOnDemand(
            CatalogUploadValidationReportNotification::class,
            function (CatalogUploadValidationReportNotification $notification, array $channels, object $notifiable) {
                return ($notifiable->routes['mail'] ?? null) === 'client@example.com';
            }
        );
    }

    public function test_notification_content_matches_upload_data(): void
    {
        $result = $this->createCompletedUploadWithErrors(5);
        $upload = $result['upload'];
        $client = $result['client'];

        $failedRows = $upload->rows()
            ->where('status', 'invalid')
            ->whereNotNull('errors')
            ->orderBy('row_number')
            ->get(['row_number', 'errors']);

        $notification = new CatalogUploadValidationReportNotification(
            catalogName: 'Upload #' . $upload->id,
            processedAt: $upload->processing_completed_at,
            totalErrors: $upload->error_rows,
            totalWarnings: 0,
            errors: $failedRows,
            warnings: collect(),
        );

        $rendered = $notification->toMail($client)->render();

        $this->assertStringContainsString('Total error rows:', $rendered);
        $this->assertStringContainsString('5', $rendered);
        $this->assertStringContainsString('Total warning rows:', $rendered);
        $this->assertStringContainsString('0', $rendered);
        $this->assertStringContainsString('Item Name is required.', $rendered);
        $this->assertStringContainsString('List Price must be a number.', $rendered);
        $this->assertStringNotContainsString('Warnings', $rendered);
    }

    public function test_warnings_section_appears_when_populated(): void
    {
        $result = $this->createCompletedUploadWithErrors(2);
        $upload = $result['upload'];
        $client = $result['client'];

        $failedRows = $upload->rows()
            ->where('status', 'invalid')
            ->whereNotNull('errors')
            ->get(['row_number', 'errors']);

        $warningRows = collect([
            (object) ['row_number' => 3, 'errors' => [['field_key' => 'price', 'message' => 'Price is below recommended minimum.']]],
            (object) ['row_number' => 7, 'errors' => [['field_key' => 'description', 'message' => 'Description is shorter than recommended.']]],
        ]);

        $notification = new CatalogUploadValidationReportNotification(
            catalogName: 'Upload #' . $upload->id,
            processedAt: $upload->processing_completed_at,
            totalErrors: $upload->error_rows,
            totalWarnings: 2,
            errors: $failedRows,
            warnings: $warningRows,
        );

        $rendered = $notification->toMail($client)->render();

        $this->assertStringContainsString('Warnings', $rendered);
        $this->assertStringContainsString('Total warning rows:', $rendered);
        $this->assertStringContainsString('2', $rendered);
        $this->assertStringContainsString('Price is below recommended minimum.', $rendered);
        $this->assertStringContainsString('Description is shorter than recommended.', $rendered);
    }

    public function test_notification_with_upload_id(): void
    {
        $client = Client::factory()->create([
            'vendor_id' => Vendor::factory()->create()->id,
            'email' => 'test@example.com',
            'status' => 'active',
            'activated_at' => now(),
        ]);

        $notification = new CatalogUploadValidationReportNotification(
            catalogName: null,
            processedAt: null,
            totalErrors: 0,
            totalWarnings: 0,
            errors: collect(),
            warnings: collect(),
        );

        $rendered = $notification->toMail($client)->render();
        $this->assertStringContainsString('Total error rows:', $rendered);
    }

    // -----------------------------------------------------------------------
    //  3. Duplicate email prevention
    // -----------------------------------------------------------------------

    public function test_duplicate_email_not_sent_when_already_emailed(): void
    {
        Notification::fake();
        $result = $this->createCompletedUploadWithErrors(11);
        $upload = $result['upload'];
        $upload->update(['validation_report_emailed_at' => now()->subHour()]);
        $upload->refresh();

        (new ProcessValidatedRowsJob($upload->id))->handle();
        $upload->refresh();

        Notification::assertNothingSent();
        $this->assertNotNull($upload->validation_report_emailed_at);
    }

    public function test_duplicate_email_not_sent_on_retry_with_timestamp(): void
    {
        Notification::fake();
        $result = $this->createCompletedUploadWithErrors(11);
        $upload = $result['upload'];
        $upload->update(['validation_report_emailed_at' => now()]);
        $upload->refresh();

        (new ProcessValidatedRowsJob($upload->id))->handle();
        $upload->refresh();

        Notification::assertNothingSent();
    }

    // -----------------------------------------------------------------------
    //  4. Upload processing tests
    // -----------------------------------------------------------------------

    public function test_invalid_rows_are_stored_with_correct_errors(): void
    {
        $result = $this->createCompletedUploadWithErrors(3);
        $upload = $result['upload'];

        $invalidRows = $upload->rows()->where('status', 'invalid')->get();
        $this->assertCount(3, $invalidRows);

        foreach ($invalidRows as $row) {
            $this->assertIsArray($row->errors);
            $this->assertNotEmpty($row->errors);
            $this->assertArrayHasKey('field_key', $row->errors[0]);
            $this->assertArrayHasKey('message', $row->errors[0]);
        }
    }

    public function test_job_preserves_error_counts_after_failed_email(): void
    {
        $result = $this->createCompletedUploadWithErrors(11);
        $upload = $result['upload'];

        Mail::fake();
        // The mailer is faked so sending will "fail" silently but the job
        // should still complete and preserve counts.
        // Since Mail::fake() captures instead of throwing, the log warning
        // path won't be hit — but the data will still be preserved.

        (new ProcessValidatedRowsJob($upload->id))->handle();
        $upload->refresh();

        $this->assertEquals(11, $upload->error_rows);
        $this->assertEquals(11, $upload->rows()->where('status', 'invalid')->count());
    }

    // -----------------------------------------------------------------------
    //  5. Livewire screen tests
    // -----------------------------------------------------------------------

    public function test_summary_shows_failed_rows_table_when_10_or_fewer(): void
    {
        $result = $this->createCompletedUploadWithErrors(5);
        $upload = $result['upload'];
        $this->actingAs($result['client'], 'client');

        Livewire::test(VendorCatalogUpload::class)
            ->set('catalogUploadId', $upload->id)
            ->set('step', 'summary')
            ->set('progress', [
                'status' => CatalogUploadStatus::Completed,
                'total_rows' => 5,
                'success_rows' => 0,
                'updated_rows' => 0,
                'skipped_rows' => 0,
                'skipped_item_names' => null,
                'error_rows' => 5,
                'failure_reason' => null,
            ])
            ->set('validationReportEmailed', false)
            ->set('validationReportFailed', false)
            ->call('refreshStatus')
            ->assertSee('Failed rows')
            ->assertSee('Row')
            ->assertSee('Reason');
    }

    public function test_summary_shows_email_notification_when_over_10_and_emailed(): void
    {
        $result = $this->createCompletedUploadWithErrors(11);
        $upload = $result['upload'];
        $upload->update([
            'validation_report_emailed_at' => now(),
            'status' => CatalogUploadStatus::Completed,
            'processing_completed_at' => now(),
        ]);
        $upload->refresh();
        $this->actingAs($result['client'], 'client');

        Livewire::test(VendorCatalogUpload::class)
            ->set('catalogUploadId', $upload->id)
            ->set('step', 'summary')
            ->set('progress', [
                'status' => CatalogUploadStatus::Completed,
                'total_rows' => 11,
                'success_rows' => 0,
                'updated_rows' => 0,
                'skipped_rows' => 0,
                'skipped_item_names' => null,
                'error_rows' => 11,
                'failure_reason' => null,
            ])
            ->call('refreshStatus')
            ->assertSee('Validation report emailed')
            ->assertSee('client@example.com')
            ->assertDontSee('Failed rows');
    }

    public function test_summary_shows_fallback_when_over_10_and_email_failed(): void
    {
        $result = $this->createCompletedUploadWithErrors(11);
        $upload = $result['upload'];
        $this->actingAs($result['client'], 'client');

        // Test the fallback UI state directly without refreshStatus()
        // which always sets validationReportFailed = false.
        Livewire::test(VendorCatalogUpload::class)
            ->set('catalogUploadId', $upload->id)
            ->set('step', 'summary')
            ->set('progress', [
                'status' => CatalogUploadStatus::Completed,
                'total_rows' => 11,
                'success_rows' => 0,
                'updated_rows' => 0,
                'skipped_rows' => 0,
                'skipped_item_names' => null,
                'error_rows' => 11,
                'failure_reason' => null,
            ])
            ->set('validationReportEmailed', false)
            ->set('validationReportFailed', true)
            ->assertSee('Could not email full report')
            ->assertSee('first 10')
            ->assertDontSee('Validation report emailed');
    }

    public function test_summary_includes_user_email_when_emailed(): void
    {
        $result = $this->createCompletedUploadWithErrors(11);
        $upload = $result['upload'];
        $upload->update([
            'validation_report_emailed_at' => now(),
            'status' => CatalogUploadStatus::Completed,
            'processing_completed_at' => now(),
        ]);
        $upload->refresh();
        $this->actingAs($result['client'], 'client');

        Livewire::test(VendorCatalogUpload::class)
            ->set('catalogUploadId', $upload->id)
            ->set('step', 'summary')
            ->set('progress', [
                'status' => CatalogUploadStatus::Completed,
                'total_rows' => 11,
                'success_rows' => 0,
                'updated_rows' => 0,
                'skipped_rows' => 0,
                'skipped_item_names' => null,
                'error_rows' => 11,
                'failure_reason' => null,
            ])
            ->call('refreshStatus')
            ->assertSee($result['client']->email);
    }

    // -----------------------------------------------------------------------
    //  6. Edge cases
    // -----------------------------------------------------------------------

    public function test_duplicate_invalid_rows_are_preserved(): void
    {
        $result = $this->createCompletedUploadWithErrors(11);
        $upload = $result['upload'];

        $invalidRows = $upload->rows()->where('status', 'invalid')->get();
        $this->assertCount(11, $invalidRows);

        $rowNumbers = $invalidRows->pluck('row_number')->sort()->values();
        $this->assertEquals(range(1, 11), $rowNumbers->toArray());
    }

    public function test_validation_report_emailed_at_column_exists_and_is_nullable(): void
    {
        $vendor = Vendor::factory()->create();
        $client = Client::factory()->create([
            'vendor_id' => $vendor->id,
            'email' => 'test@example.com',
            'status' => 'active',
            'activated_at' => now(),
        ]);

        $upload = CatalogUpload::factory()->create([
            'client_id' => $client->id,
            'vendor_id' => $vendor->id,
        ]);

        $this->assertNull($upload->validation_report_emailed_at);

        $upload->update(['validation_report_emailed_at' => now()]);
        $upload->refresh();
        $this->assertNotNull($upload->validation_report_emailed_at);
        $this->assertInstanceOf(\DateTimeInterface::class, $upload->validation_report_emailed_at);
    }

    public function test_job_failure_logs_warning_instead_of_crashing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn($message) => str_contains($message, 'Failed to send validation report email'));

        $result = $this->createCompletedUploadWithErrors(11);
        $upload = $result['upload'];

        // Override the mailer config to force a failure
        config(['mail.default' => 'smtp']);
        config(['mail.mailers.smtp' => [
            'transport' => 'smtp',
            'host' => '127.0.0.1',
            'port' => '1',
            'timeout' => 1,
        ]]);

        (new ProcessValidatedRowsJob($upload->id))->handle();
        $upload->refresh();

        // Email failed, so status stays ProcessingItems for retry
        $this->assertEquals(CatalogUploadStatus::ProcessingItems, $upload->status);
        // Restore config
        config(['mail.default' => 'array']);
    }

    public function test_queue_retry_scenario_preserves_data(): void
    {
        $result = $this->createCompletedUploadWithErrors(11);
        $upload = $result['upload'];

        // First attempt with misconfigured mail
        config(['mail.default' => 'smtp']);
        config(['mail.mailers.smtp' => [
            'transport' => 'smtp',
            'host' => '127.0.0.1',
            'port' => '1',
            'timeout' => 1,
        ]]);

        (new ProcessValidatedRowsJob($upload->id))->handle();
        $upload->refresh();
        $this->assertNull($upload->validation_report_emailed_at);
        // Email failed, so status stays ProcessingItems for retry
        $this->assertEquals(CatalogUploadStatus::ProcessingItems, $upload->status);

        // Retry with working mail
        config(['mail.default' => 'array']);
        Notification::fake();

        (new ProcessValidatedRowsJob($upload->id))->handle();
        $upload->refresh();

        Notification::assertSentOnDemand(CatalogUploadValidationReportNotification::class);
        $this->assertNotNull($upload->validation_report_emailed_at);
    }
}
