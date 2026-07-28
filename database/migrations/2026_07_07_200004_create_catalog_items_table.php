<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            $table->string('name');
            $table->text('description');

            $table->string('manufacturer_sku')->nullable();
            $table->string('manufacturer_name')->nullable();
            $table->string('brand_name')->nullable();

            $table->string('vendor_sku');
            $table->foreignId('catalog_upload_id')->nullable()->constrained('catalog_uploads')->nullOnDelete();
            $table->string('data_fingerprint', 32)->nullable();

            $table->string('unspsc_code')->nullable();
            $table->string('product_type', 50)->nullable();

            $table->string('unit_of_measure', 50);
            $table->decimal('quantity_per_unit', 10, 2)->nullable();
            $table->decimal('weight', 10, 2)->default(0.01);
            $table->decimal('min_order_quantity', 10, 2)->nullable();
            $table->decimal('max_order_quantity', 10, 2)->nullable();
            $table->decimal('multiples', 10, 2)->nullable();

            $table->json('classifications')->nullable();
            $table->json('search_terms')->nullable();
            $table->json('specifications')->nullable();
            $table->json('selling_points')->nullable();
            $table->string('msds_link', 300)->nullable();

            $table->decimal('list_price', 10, 2)->nullable();
            $table->decimal('selling_price', 10, 2)->nullable();

            $table->string('status', 20)->default('incomplete');
            $table->integer('completeness_score')->default(0);

            $table->timestamps();

            $table->index(['vendor_id']);
            $table->index('data_fingerprint', 'catalog_items_fingerprint_index');
            $table->unique(['vendor_id', 'vendor_sku'], 'catalog_items_vendor_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
