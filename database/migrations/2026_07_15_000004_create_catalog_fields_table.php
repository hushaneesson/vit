<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The static list of VIT eLink fields that a vendor's uploaded columns
     * get mapped to. This is reference data only - no category/hierarchy
     * concept lives here or anywhere else in this component. Seeded from
     * the "field description" sheet in the VIT vendor catalog data sheet.
     */
    public function up(): void
    {
        Schema::create('catalog_fields', function (Blueprint $table) {
            $table->id();

            $table->string('field_key')->unique(); // e.g. "seller_sku"
            $table->boolean('is_system_derived')->default(false); // true if this field is derived from other fields (e.g. "full_product_name" is derived from "brand" + "product_name")
            $table->string('system_source')->nullable(); // dot-path, e.g. "vendor.name"
            $table->string('web_app_label'); // display label shown to the vendor
            $table->text('description')->nullable(); // from the "description" column in the sheet
            $table->text('notes')->nullable(); // from the "Comment" column in the sheet (e.g. format examples)

            $table->enum('requirement_type', ['required', 'optional', 'conditional'])->default('optional');
            $table->string('field_type')->default('text'); // text, number, decimal, boolean

            $table->boolean('is_multi_value')->default(false);
            $table->string('join_separator')->nullable(); // e.g. "," or "|"
            $table->unsignedInteger('max_length')->nullable();

            // Only used when requirement_type = 'conditional'.
            $table->string('conditional_on_field')->nullable();
            $table->string('conditional_on_value')->nullable();

            $table->boolean('active')->default(true);
            $table->boolean('visible_in_web_app')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_fields');
    }
};
