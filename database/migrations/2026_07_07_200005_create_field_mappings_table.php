<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores a vendor's file-column -> catalog_field_definitions mapping
     * (Phase 8). Saved against vendor_id (not client_id) so any client at
     * that vendor benefits from a mapping a colleague already set up.
     */
    public function up(): void
    {
        Schema::create('field_mappings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('clients')->nullOnDelete();

            $table->string('name')->nullable(); // optional label, e.g. "Default catalog import"
            $table->json('source_columns'); // original headers detected from the vendor's file
            $table->json('mapped_fields'); // source column => catalog_field_definitions.field_key rules

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_mappings');
    }
};
