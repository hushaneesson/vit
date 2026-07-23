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

            $table->timestamps();
        });

        // Field-level rows for a template, matched back to uploads by
        // source column NAME (not index) since headers may shift position
        // between exports even when the columns themselves are the same.
        Schema::create('vendor_mapping_template_fields', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_mapping_template_id')->constrained('vendor_mapping_templates')->cascadeOnDelete();
            $table->foreignId('catalog_field_id')->constrained('catalog_fields')->cascadeOnDelete();

            $table->string('source_column_name');

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
