<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The vendor's column -> VIT field mapping for one specific upload.
     * catalog_field_id nullable = "ignore this column" (vendor chose not
     * to map it to anything).
     */
    public function up(): void
    {
        Schema::create('catalog_upload_column_mappings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('catalog_upload_id')->constrained('catalog_uploads')->cascadeOnDelete();
            $table->foreignId('catalog_field_id')->nullable()
                ->constrained('catalog_fields')->nullOnDelete();

            $table->unsignedInteger('column_index'); // 0-based position in the source file
            $table->string('source_column_name'); // header text as it appeared in the vendor's file

            $table->timestamps();

            $table->unique(['catalog_upload_id', 'column_index'], 'upload_column_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_upload_column_mappings');
    }
};
