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
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique(); // e.g. EA, CS, RM
            $table->string('description');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $this->insertData();
    }

    public function insertData()
    {
        $UOM = [
            ['id' => 1,  'code' => 'AS',  'description' => 'Assorted'],
            ['id' => 2,  'code' => 'BA',  'description' => 'Barrel'],
            ['id' => 3,  'code' => 'BD',  'description' => 'Bundle'],
            ['id' => 4,  'code' => 'BG',  'description' => 'Bag'],
            ['id' => 5,  'code' => 'BL',  'description' => 'Bale'],
            ['id' => 6,  'code' => 'BM',  'description' => 'Beam'],
            ['id' => 7,  'code' => 'BO',  'description' => 'Bottle'],
            ['id' => 8,  'code' => 'BT',  'description' => 'Bolt'],
            ['id' => 9,  'code' => 'BX',  'description' => 'Box'],
            ['id' => 10, 'code' => 'CA',  'description' => 'Can'],
            ['id' => 11, 'code' => 'CD',  'description' => 'Card'],
            ['id' => 12, 'code' => 'CG',  'description' => 'Cage'],
            ['id' => 13, 'code' => 'CL',  'description' => 'Coil'],
            ['id' => 14, 'code' => 'CN',  'description' => 'Container'],
            ['id' => 15, 'code' => 'CQ',  'description' => 'Cartridge'],
            ['id' => 16, 'code' => 'CS',  'description' => 'Case'],
            ['id' => 17, 'code' => 'CT',  'description' => 'Carton'],
            ['id' => 18, 'code' => 'CX',  'description' => 'Crate'],
            ['id' => 19, 'code' => 'DC',  'description' => 'Disk'],
            ['id' => 20, 'code' => 'DI',  'description' => 'Drum'],
            ['id' => 21, 'code' => 'DR',  'description' => 'Drawer'],
            ['id' => 22, 'code' => 'DS',  'description' => 'Display'],
            ['id' => 23, 'code' => 'DZ',  'description' => 'Dozen'],
            ['id' => 24, 'code' => 'DZN', 'description' => 'Dozen'],
            ['id' => 25, 'code' => 'EA',  'description' => 'Each'],
            ['id' => 26, 'code' => 'FT',  'description' => 'Foot'],
            ['id' => 27, 'code' => 'GA',  'description' => 'Gallon'],
            ['id' => 28, 'code' => 'GG',  'description' => 'Gigagram'],
            ['id' => 29, 'code' => 'GL',  'description' => 'Glass'],
            ['id' => 30, 'code' => 'GR',  'description' => 'Gram'],
            ['id' => 31, 'code' => 'GRO', 'description' => 'Gross'],
            ['id' => 32, 'code' => 'GS',  'description' => 'Gross'],
            ['id' => 33, 'code' => 'HD',  'description' => 'Hundred'],
            ['id' => 34, 'code' => 'HU',  'description' => 'Hundred'],
            ['id' => 35, 'code' => 'JG',  'description' => 'Jug'],
            ['id' => 36, 'code' => 'JR',  'description' => 'Jar'],
            ['id' => 37, 'code' => 'KE',  'description' => 'Keg'],
            ['id' => 38, 'code' => 'KT',  'description' => 'Kit'],
            ['id' => 39, 'code' => 'LB',  'description' => 'Pound'],
            ['id' => 40, 'code' => 'LF',  'description' => 'Linear Foot'],
            ['id' => 41, 'code' => 'M',   'description' => 'Metre'],
            ['id' => 42, 'code' => 'MC',  'description' => 'Master Carton'],
            ['id' => 43, 'code' => 'MK',  'description' => 'Markup'],
            ['id' => 44, 'code' => 'OZ',  'description' => 'Ounce'],
            ['id' => 45, 'code' => 'PA',  'description' => 'Packet'],
            ['id' => 46, 'code' => 'PC',  'description' => 'Piece'],
            ['id' => 47, 'code' => 'PD',  'description' => 'Pad'],
            ['id' => 48, 'code' => 'PH',  'description' => 'Pack'],
            ['id' => 49, 'code' => 'PK',  'description' => 'Package'],
            ['id' => 50, 'code' => 'PL',  'description' => 'Pail'],
            ['id' => 51, 'code' => 'PR',  'description' => 'Pair'],
            ['id' => 52, 'code' => 'QT',  'description' => 'Quart'],
            ['id' => 53, 'code' => 'RD',  'description' => 'Rod'],
            ['id' => 54, 'code' => 'RE',  'description' => 'Ream'],
            ['id' => 55, 'code' => 'RL',  'description' => 'Roll'],
            ['id' => 56, 'code' => 'RM',  'description' => 'Rim'],
            ['id' => 57, 'code' => 'SET', 'description' => 'Set'],
            ['id' => 58, 'code' => 'SF',  'description' => 'Square Foot'],
            ['id' => 59, 'code' => 'SG',  'description' => 'Sheet'],
            ['id' => 60, 'code' => 'SH',  'description' => 'Sheet'],
            ['id' => 61, 'code' => 'SL',  'description' => 'Sleeve'],
            ['id' => 62, 'code' => 'SO',  'description' => 'Spool'],
            ['id' => 63, 'code' => 'SP',  'description' => 'Strip'],
            ['id' => 64, 'code' => 'SQ',  'description' => 'Square'],
            ['id' => 65, 'code' => 'ST',  'description' => 'Stick'],
            ['id' => 66, 'code' => 'TB',  'description' => 'Tube'],
            ['id' => 67, 'code' => 'TE',  'description' => 'Tote'],
            ['id' => 68, 'code' => 'TL',  'description' => 'Tile'],
            ['id' => 69, 'code' => 'TO',  'description' => 'Ton'],
            ['id' => 70, 'code' => 'VL',  'description' => 'Vial'],
            ['id' => 71, 'code' => 'YD',  'description' => 'Yard'],
        ];


        DB::table('units_of_measure')->insert($UOM);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units_of_measure');
    }
};
