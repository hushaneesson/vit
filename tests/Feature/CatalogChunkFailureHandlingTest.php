<?php

namespace Tests\Feature;

use App\Enums\CatalogUploadStatus;
use App\Jobs\ProcessValidatedRowsJob;
use App\Models\Catalog;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadRow;
use App\Models\Client;
use App\Models\Vendor;
use App\Services\Catalog\CatalogValidationReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Chunk-failure handling: when a ProcessValidatedRowsJob exhausts its retries,
 * its unprocessed rows must become user-safe system errors, the failed_rows
 * counter must advance by the real row count, later chunks must still process,
 * and the upload must reach a terminal state instead of staying in Processing.
 */
class CatalogChunkFailureHandlingTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private Client $client;

    private Catalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();
        $this->client = Client::factory()->create(['vendor_id' => $this->vendor->id]);
        $this->catalog = Catalog::create(['vendor_id' => $this->vendor->id, 'name' => 'Main']);
    }

    private function makeUpload(int $totalRows): CatalogUpload
    {
        return CatalogUpload::create([
            'vendor_id' => $this->vendor->id,
            'client_id' => $this->client->id,
            'catalog_id' => $this->catalog->id,
            'original_filename' => 'upload.csv',
            'file_path' => 'catalog-uploads/upload.csv',
            'disk' => 'local',
            'file_type' => 'csv',
            'status' => CatalogUploadStatus::ProcessingItems,
            'total_rows' => $totalRows,
        ]);
    }

    private function addRow(CatalogUpload $upload): CatalogUploadRow
    {
        return CatalogUploadRow::create([
            'catalog_upload_id' => $upload->id,
            'row_number' => $upload->rows()->count() + 1,
            'data' => [],
            'raw_data' => ['SKU-'.$upload->rows()->count()],
            'status' => 'valid',
        ]);
    }

    public function test_exhausted_chunk_marks_rows_as_system_errors_and_completes_upload(): void
    {
        $upload = $this->makeUpload(3);
        $rows = collect([$this->addRow($upload), $this->addRow($upload), $this->addRow($upload)]);

        $job = new ProcessValidatedRowsJob($upload->id, $rows->first()->id, $rows->last()->id);
        $job->failed(new \Exception('boom'));

        $upload->refresh();

        $this->assertSame(3, $upload->failed_rows, 'failed_rows must advance by the number of system-error rows.');

        foreach ($rows as $row) {
            $row->refresh();

            $this->assertSame('failed', $row->status);
            $this->assertSame(
                'System error while processing this row. The system was unable to complete processing after multiple attempts.',
                $row->errors['_system'][0]['message'] ?? null,
            );
        }

        // All rows belong to this chunk, so processed == total_rows and the
        // upload must have reached a terminal state.
        $this->assertSame(CatalogUploadStatus::Completed, $upload->status);
    }

    public function test_later_chunks_still_process_after_a_failed_chunk(): void
    {
        $upload = $this->makeUpload(3);

        $dead = collect([$this->addRow($upload), $this->addRow($upload)]);
        $alive = collect([$this->addRow($upload)]);

        // Chunk 1 (dead rows) exhausts retries.
        (new ProcessValidatedRowsJob($upload->id, $dead->first()->id, $dead->last()->id))
            ->failed(new \Exception('boom'));

        // Chunk 2 (alive rows) processes normally afterwards.
        (new ProcessValidatedRowsJob($upload->id, $alive->first()->id, $alive->first()->id))
            ->handle(app(\App\Services\Catalog\CatalogItemProcessor::class), app(\App\Services\Catalog\CatalogRowValidator::class));

        $upload->refresh();

        $this->assertSame(CatalogUploadStatus::Completed, $upload->status);
        $this->assertSame(2, $upload->failed_rows);
        // The alive chunk was fully accounted for (processed or invalid),
        // keeping processed == total_rows.
        $this->assertSame(1, $upload->success_rows + $upload->invalid_rows);

        // The dead chunk's rows keep their system-error state and were not
        // re-marked or duplicated by the second chunk run.
        $this->assertSame(2, CatalogUploadRow::where('catalog_upload_id', $upload->id)->where('status', 'failed')->count());
    }

    public function test_retry_does_not_double_count_system_error_rows(): void
    {
        $upload = $this->makeUpload(2);
        $rows = collect([$this->addRow($upload), $this->addRow($upload)]);

        $job = new ProcessValidatedRowsJob($upload->id, $rows->first()->id, $rows->last()->id);
        $job->failed(new \Exception('boom'));
        $job->failed(new \Exception('boom again'));

        $upload->refresh();

        // failed() is idempotent: already-failed rows are skipped, so the
        // counter stays at 2 even if bookkeeping runs twice.
        $this->assertSame(2, $upload->failed_rows);
        $this->assertSame(CatalogUploadStatus::Completed, $upload->status);
    }

    public function test_system_error_rows_appear_in_the_validation_report_email(): void
    {
        Notification::fake();

        $upload = $this->makeUpload(3);
        $rows = collect([$this->addRow($upload), $this->addRow($upload), $this->addRow($upload)]);

        // Force the existing send condition (>10 invalid rows).
        $upload->forceFill(['invalid_rows' => 11])->save();

        (new ProcessValidatedRowsJob($upload->id, $rows->first()->id, $rows->last()->id))
            ->failed(new \Exception('boom'));

        $upload->refresh();
        app(CatalogValidationReportService::class)->sendValidationReportIfNeeded($upload->fresh());

        Notification::assertSentOnDemand(
            \App\Notifications\CatalogUploadValidationReportNotification::class,
            function ($notification, $channels, $mail) {
                $body = $notification->toMail((object) [])->render();

                return str_contains($body, 'System error while processing this row');
            }
        );

        $upload->refresh();
        $this->assertNotNull($upload->validation_report_emailed_at);
    }
}
