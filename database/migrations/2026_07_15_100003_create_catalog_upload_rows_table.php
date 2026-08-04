<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per product line from the vendor's file, after mapping is
     * applied. `data` is keyed by VitFieldDefinition field_key (not
     * the vendor's original column names), so downstream phases (CSV
     * generation, hierarchy engine, SFTP delivery) can read it the same
     * way regardless of how the vendor's source file was laid out.
     *
     * This table is the staging point this component is responsible for.
     * It does NOT talk to VIT directly - a later phase reads from here.
     */
    public function up(): void
    {
        Schema::create('catalog_upload_rows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('catalog_upload_id')->constrained('catalog_uploads')->cascadeOnDelete();

            $table->unsignedInteger('row_number'); // 1-based, matches source file line (excluding header)

            $table->json('data'); // { "seller_sku": "ABC-123", "name": "...", ... } keyed by field_key
            $table->json('raw_data')->nullable(); // original unmapped row values, kept for debugging/support

            $table->enum('status', ['valid', 'invalid'])->default('valid');
            $table->json('errors')->nullable(); // [{ "field_key": "list_price", "message": "..." }]

            $table->timestamps();

            $table->index(['catalog_upload_id', 'status']);
            $table->unique(['catalog_upload_id', 'row_number'], 'catalog_upload_rows_row_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_upload_rows');
    }
};
