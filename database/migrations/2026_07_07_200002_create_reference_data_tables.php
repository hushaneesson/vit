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
    }
};
