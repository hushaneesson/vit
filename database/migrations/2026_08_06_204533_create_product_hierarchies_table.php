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
        Schema::create('product_hierarchies', function (Blueprint $table) {
            $table->id();
            $table->string('hierarchy_number')->unique()->nullable();
            $table->foreignId('parent_id')->nullable();
            $table->string('name', 100);
            $table->integer('level');
            $table->timestamps();
        });

        $this->insertData();
    }

    public function insertData()
    {
        $hierarchies = [];
        $file = fopen(base_path('resources/files/hierarchies.csv'), 'r');

        // skip header row
        fgetcsv($file);

        while (($line = fgetcsv($file)) !== false) {
            $hierarchies[] = $line;
        }

        fclose($file);

        $hierarchies = collect($hierarchies)->map(function ($hierarchy) {
            return [
                'id' => $hierarchy[0],
                'hierarchy_number' => $hierarchy[1],
                'name' => $hierarchy[2],
                'level' => $hierarchy[3],
                'parent_id' => $hierarchy[4] ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        });

        DB::table('product_hierarchies')->insert($hierarchies->where('level', 1)->toArray());
        DB::table('product_hierarchies')->insert($hierarchies->where('level', 2)->toArray());
        DB::table('product_hierarchies')->insert($hierarchies->where('level', 3)->toArray());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_hierarchies');
    }
};
