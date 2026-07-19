<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One record per vendor file upload session (the "run" that a client
     * kicks off). This is the parent record that the mapping, staged rows,
     * and later CSV-generation/SFTP phases will all hang off of.
     */
    public function up(): void
    {
        Schema::create('catalog_uploads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            $table->string('original_filename');
            $table->string('catalog_name')->nullable();
            $table->string('file_path'); // path on the configured disk (DigitalOcean Spaces)
            $table->string('disk')->default('spaces');
            $table->enum('file_type', ['csv', 'xlsx', 'xls']);

            // uploaded -> mapping -> queued -> processing/processing_items -> completed -> failed
            $table->enum('status', [
                'uploaded',
                'mapping',
                'queued',
                'processing',
                'processing_items',
                'completed',
                'failed',
            ])->default('uploaded');

            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->json('skipped_item_names')->nullable();

            $table->text('failure_reason')->nullable(); // set if the whole job fails (bad file, etc.)

            $table->timestamp('mapping_confirmed_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_completed_at')->nullable();

            $table->timestamps();

            $table->index(['vendor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_uploads');
    }
};
