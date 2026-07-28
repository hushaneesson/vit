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
     * This is a business approval entity, not a technical event — it
     * exists independently of CatalogUpload.
     */
    public function up(): void
    {
        Schema::create('catalog_submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            // The upload that triggered this submission (nullable — submissions
            // may be created without a new upload in the future).
            $table->foreignId('catalog_upload_id')->nullable()
                ->constrained('catalog_uploads')->nullOnDelete();

            // Who asked for the review
            $table->foreignId('requested_by_client_id')->constrained('clients')->cascadeOnDelete();

            // Business status — see CatalogSubmissionStatus enum
            $table->string('status', 30)->default(CatalogSubmissionStatus::Draft->value);

            // Snapshot counts (computed at submission time for admin review context)
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('complete_items')->default(0);
            $table->unsignedInteger('incomplete_items')->default(0);

            // Review timestamps and actors (admin User model)
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // The export generated from this submission (set after approval)
            // FK is added in a later migration after catalog_exports table exists
            $table->foreignId('catalog_export_id')->nullable();

            $table->timestamps();

            $table->index(['vendor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_submissions');
    }
};
