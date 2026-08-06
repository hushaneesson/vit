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
            $table->string('hierarchy_number')->unique();
            $table->string('parent_id')->nullable();
            $table->string('name', 100);
            $table->integer('level');
            $table->timestamps();
        });

        // Add the self-referencing FK in a separate step, now that
        // the unique index on hierarchy_number definitely exists.
        Schema::table('product_hierarchies', function (Blueprint $table) {
            $table->foreign('parent_id')
                ->references('hierarchy_number')
                ->on('product_hierarchies')
                ->onDelete('cascade');
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

        $hierarchies = collect($hierarchies)->map(function ($hierarchy, $index) {
            return [
                'id' => $index + 1,
                'hierarchy_number' => $hierarchy[0],
                'name' => $hierarchy[1],
                'level' => $hierarchy[2],
                'parent_id' => $hierarchy[3] ?: null,
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
