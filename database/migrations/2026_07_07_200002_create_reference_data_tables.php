<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference / lookup data managed only by the admin. Vendors select
     * from these lists later during catalog entry (Phase 4+).
     */
    public function up(): void
    {
        // 3-level product hierarchy. A "leaf" row (level 3) has a
        // hierarchy_number — the code VIT expects on the CSV/XLSX row.
        // level 1 and 2 rows are purely structural (no hierarchy_number
        // required, though we allow it for completeness).
        Schema::create('product_hierarchies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('product_hierarchies')->nullOnDelete();
            $table->unsignedTinyInteger('level'); // 1, 2, or 3
            $table->string('name');
            // Full concatenated path using "!" separator, e.g. "Office!Paper!Reams"
            $table->string('path')->nullable()->unique();
            // The VIT-assigned hierarchy number for this exact path (level 3 rows)
            $table->string('hierarchy_number')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique(); // e.g. EA, CS, RM
            $table->string('description');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Classification "types" available to vendors (UPC, GTIN, Hazmat/MSDS,
        // Warranty, National Stock Number, NIGP Code, etc). UNSPSC and
        // Country of Origin are always-required and typically seeded here
        // with is_always_required = true.
        Schema::create('classification_types', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g. UNSPSC, UPC_RTL, GTIN, Country of Origin, MSDS URL...
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_always_required')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('country_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 2)->unique(); // 2-digit ISO-ish code per VIT spec
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Product Commodity Type dropdown (`category` column). The first
        // option is always literally "Use the name of Category Level 2".
        Schema::create('commodity_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commodity_types');
        Schema::dropIfExists('country_codes');
        Schema::dropIfExists('classification_types');
        Schema::dropIfExists('units_of_measure');
        Schema::dropIfExists('product_hierarchies');
    }
};
