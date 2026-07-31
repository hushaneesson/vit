<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per generated Excel file (Phase 9). Tracks the file location,
     * VIT upload lifecycle (Phase 11), and audit data.
     */
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            $table->string('file_path'); // storage/app/submissions/vendor-{id}/catalog-{name}-{timestamp}.xlsx
            $table->unsignedBigInteger('file_size')->nullable(); // bytes
            $table->unsignedInteger('product_count')->default(0);
            $table->timestamp('submission_date')->nullable();

            $table->enum('status', ['pending_upload', 'uploaded', 'failed', 'processing'])->default('pending_upload');
            $table->unsignedInteger('upload_attempts')->default(0);
            $table->text('last_upload_error')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->json('vit_api_response')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
