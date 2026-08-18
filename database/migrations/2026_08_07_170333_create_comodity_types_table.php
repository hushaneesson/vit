<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('commodity_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $this->insertData();
    }

    public function insertData(): void
    {
        $commodityTypes = [
            ['name' => 'Agricultural Products', 'active' => true],
            ['name' => 'Chemicals', 'active' => true],
            ['name' => 'Construction Materials', 'active' => true],
            ['name' => 'Consumer Goods', 'active' => true],
            ['name' => 'Electronics', 'active' => true],
            ['name' => 'Energy Products', 'active' => true],
            ['name' => 'Food and Beverages', 'active' => true],
            ['name' => 'Industrial Equipment', 'active' => true],
            ['name' => 'Metals and Minerals', 'active' => true],
            ['name' => 'Pharmaceuticals', 'active' => true],
            ['name' => 'Textiles and Apparel', 'active' => true],
            ['name' => 'Transportation Equipment', 'active' => true],
        ];

        DB::table('commodity_types')->insert($commodityTypes);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commodity_types');
    }
};
