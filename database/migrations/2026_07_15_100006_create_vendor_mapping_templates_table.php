<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A vendor may opt to save a column mapping so future uploads can be
     * pre-filled automatically. A vendor can have more than one saved
     * template (e.g. different suppliers export different layouts).
     *
     * Each template is tied to a specific file structure via `file_signature`,
     * which is a hash of the sorted, normalized column headers from the file
     * the template was created from. When a new file is uploaded, we compute
     * the same signature and only match templates that have an identical
     * signature — this prevents templates from being applied to incompatible
     * file formats.
     */
    public function up(): void
    {
        Schema::create('vendor_mapping_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('created_by_client_id')->nullable()
                ->constrained('clients')->nullOnDelete();

            $table->string('name'); // e.g. "Standard export from our ERP"
            $table->boolean('active')->default(true);

            // Hash of the normalized, sorted column headers from the file this
            // template applies to. Used to match templates to compatible files.
            $table->string('file_signature')->nullable()->index();

            $table->timestamps();
        });

        // Field-level rows for a template, matched back to uploads by
        // source column NAME (not index) since headers may shift position
        // between exports even when the columns themselves are the same.
        Schema::create('vendor_mapping_template_fields', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_mapping_template_id');
            $table->foreign('vendor_mapping_template_id', 'vmt_fields_template_fk')
                ->references('id')
                ->on('vendor_mapping_templates')
                ->cascadeOnDelete();
            $table->string('field_key', 100);

            $table->string('source_column_name');
            $table->string('source_separator')->nullable();

            $table->timestamps();

            $table->unique(['vendor_mapping_template_id', 'source_column_name'], 'template_column_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_mapping_template_fields');
        Schema::dropIfExists('vendor_mapping_templates');
    }
};
