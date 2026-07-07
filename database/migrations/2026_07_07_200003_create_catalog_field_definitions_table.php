<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The single source of truth for every VIT CSV/XLSX column. Phase 4
     * (entry form), Phase 5 (complex fields), Phase 7 (Excel generation),
     * and Phase 8 (ETL mapping) all read from this table instead of having
     * fields hardcoded in Blade/Vue/generator code.
     */
    public function up(): void
    {
        Schema::create('catalog_field_definitions', function (Blueprint $table) {
            $table->id();

            $table->string('web_app_label'); // label shown to vendors on the entry form
            $table->string('vit_column_header'); // exact VIT column name, e.g. "dealer sku"
            $table->text('description')->nullable(); // tooltip text

            // required | conditional (see conditional_on_field / conditional_on_value)
            $table->enum('requirement_type', ['required', 'conditional', 'optional'])->default('optional');
            $table->string('conditional_on_field')->nullable(); // key of another field def / classification that triggers requirement
            $table->string('conditional_on_value')->nullable();

            $table->enum('field_type', [
                'text', 'number', 'decimal', 'date', 'boolean',
                'dropdown', 'multi-value-list', 'key-value-pairs', 'image-upload',
            ]);

            $table->unsignedInteger('max_length')->nullable();
            $table->unsignedTinyInteger('decimal_places')->nullable();

            $table->boolean('is_multi_value')->default(false);
            $table->string('join_separator', 5)->nullable(); // e.g. "|" or "!"

            // Some VIT columns exist in the spec but must never be shown to
            // the vendor (customer sku, search sku, vendor sku, type...)
            $table->boolean('visible_in_web_app')->default(true);

            // New/unrecognized value in this field must notify VIT admin
            // (hierarchy path, classifications)
            $table->boolean('triggers_email_alert')->default(false);

            // Machine-readable key used internally to reference this field
            // from code (e.g. in the classification engine) without relying
            // on fragile label matching.
            $table->string('field_key')->unique();

            // Optional: for dropdown fields, which reference table/source
            // powers the options (product_hierarchy, unit_of_measure,
            // commodity_type, country_code, classification_type, static:...)
            $table->string('options_source')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_field_definitions');
    }
};
