<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks each catalog export generation run. One row per generated .xlsx
     * file, recording the vendor, file metadata, and the lifecycle status
     * (pending → generating → completed | failed).
     *
     * This table exists independently of the submissions pipeline — it's the
     * source-of-truth for the "generate Excel → store on disk → (future: FTP)"
     * workflow, which is separate from the "submit to VIT" workflow tracked
     * by the submissions table.
     */
    public function up(): void
    {
        Schema::create('catalog_exports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            $table->string('file_path')->nullable();        // relative storage path to the .xlsx
            $table->string('disk')->default('local');        // which filesystem disk it lives on
            $table->unsignedBigInteger('file_size')->nullable(); // bytes

            $table->unsignedInteger('total_items')->default(0); // number of CatalogItem rows exported

            $table->string('status', 20)->default('pending');  // pending | generating | completed | failed
            $table->text('failure_reason')->nullable();

            $table->timestamp('generating_started_at')->nullable();
            $table->timestamp('generated_at')->nullable();      // when the file was successfully written

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_exports');
    }
};
