<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot table between catalog_submissions and catalog_items.
     *
     * This stores a SNAPSHOT of the catalog item data at the time of
     * submission, not just a foreign key reference. This ensures auditability:
     * even if catalog items are later updated or deleted, the submission
     * record always reflects exactly what the admin reviewed.
     *
     * The snapshot columns mirror the VIT-relevant fields from catalog_items
     * that are needed for Excel export generation.
     */
    public function up(): void
    {
        Schema::create('catalog_submission_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('catalog_submission_id')
                ->constrained('catalog_submissions')
                ->cascadeOnDelete();

            // Store the original catalog_item_id for reference, but DO NOT
            // foreign key it — we want the snapshot to survive even if the
            // original catalog_item is deleted.
            $table->uuid('catalog_item_id')->nullable();
            $table->unsignedBigInteger('vendor_id');

            // Snapshot of the item data at submission time
            $table->string('vendor_sku');

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('product_type')->nullable();
            $table->string('unit_of_measure')->nullable();
            $table->string('manufacturer_sku')->nullable();
            $table->string('manufacturer_name')->nullable();
            $table->string('brand_name')->nullable();
            $table->decimal('list_price', 10, 2)->nullable();
            $table->decimal('selling_price', 10, 2)->nullable();
            $table->decimal('weight', 8, 3)->nullable();
            $table->string('unspsc_code')->nullable();
            $table->json('search_terms')->nullable();
            $table->json('selling_points')->nullable();
            $table->json('specifications')->nullable();
            $table->json('classifications')->nullable();

            $table->timestamps();

            $table->unique(['catalog_submission_id', 'catalog_item_id'], 'submission_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_submission_items');
    }
};
