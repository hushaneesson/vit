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
            $table->foreignId('catalog_id');

            $table->string('dealer_sku', 255);
            $table->json('replacement_sku')->nullable();
            $table->string('name', 255);
            $table->text('description')->nullable();

            $table->string('manufacturer_sku')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('brand_name')->nullable();

            $table->json('images')->nullable();

            $table->foreignId('hierarchy')->nullable();
            $table->foreignId('category')->nullable();

            $table->json('classifications')->nullable();

            $table->json('search_terms')->nullable();
            $table->json('specifications')->nullable();
            $table->json('selling_points')->nullable();

            $table->decimal('list_price', 10, 2)->nullable();
            $table->decimal('selling_price', 10, 2)->nullable();
            $table->integer('availability')->default(999);

            $table->decimal('item_weight', 10, 2)->nullable();
            $table->string('lead_time', 20)->nullable();

            $table->foreignId('unit_of_measure')->nullable();
            $table->integer('quantity_per_unit')->nullable();
            $table->integer('min_qty_per_order')->nullable();
            $table->integer('max_qty_per_order')->nullable();
            $table->integer('multiples')->nullable();

            $table->boolean('is_discontinued')->default(false);
            $table->date('discontinue_date')->nullable();

            $table->string('status', 20)->default('incomplete');
            $table->integer('completeness_score')->default(0);
            $table->timestamp('last_submitted_at')->nullable();

            $table->timestamps();

            $table->index(['vendor_id']);
            $table->index('dealer_sku');
            $table->unique(['vendor_id', 'dealer_sku'], 'catalog_items_dealer_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
