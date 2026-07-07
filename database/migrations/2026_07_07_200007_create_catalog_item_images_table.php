<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Actual uploaded image files for a catalog item (Primary, #2, #3...).
     * Stored via Laravel Storage; embedded as real images into the
     * generated Excel file (Phase 7), never as URLs/filenames-as-text.
     */
    public function up(): void
    {
        Schema::create('catalog_item_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->cascadeOnDelete();
            $table->string('disk')->default('local');
            $table->string('path'); // storage path to the stored image file
            $table->unsignedInteger('sort_order')->default(0); // 0 = Primary, 1 = #2, ...
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_item_images');
    }
};
