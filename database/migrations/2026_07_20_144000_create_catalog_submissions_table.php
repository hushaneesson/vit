<?php

use App\Enums\CatalogSubmissionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per vendor catalog review submission. Each submission
     * represents a snapshot of a vendor's catalog items at the time the
     * vendor requested admin review. The items included are recorded in
     * the catalog_submission_items pivot table for auditability.
     *
     * This is the single source of truth for:
     * - Business approval status (CatalogSubmissionStatus enum)
     * - Generated Excel file path, size, disk
     * - Technical processing state (processing_status column)
     * - Generation and upload timestamps
     */
    public function up(): void
    {
        Schema::create('catalog_submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            $table->foreignId('catalog_id')
                ->nullable()
                ->constrained('catalogs')
                ->nullOnDelete();

            // Client who requested the submission
            $table->foreignId('requested_by_client_id')->constrained('clients')->cascadeOnDelete();

            // Business status — see CatalogSubmissionStatus enum
            $table->string('status', 30)->default(CatalogSubmissionStatus::Draft->value);

            // Snapshot counts (computed at submission time for admin review context)
            $table->unsignedInteger('total_items')->default(0);

            // Review timestamps and actors (admin User model)
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->string('file_path', 500)->nullable();
            $table->string('disk')->default('local');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('product_count')->default(0);
            $table->timestamp('submission_date')->nullable();

            // VIT upload tracking
            $table->string('processing_status', 20)->default('pending');
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('upload_attempts')->default(0);
            $table->text('last_upload_error')->nullable();
            $table->json('vit_api_response')->nullable();

            $table->timestamp('generating_started_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();

            $table->timestamps();

            $table->index(['vendor_id', 'status']);
            $table->index(['catalog_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_submissions');
    }
};
