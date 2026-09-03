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
            $table->boolean('approved')->default(false);
            $table->timestamps();
        });

        $this->insertData();
    }

    public function insertData(): void
    {
        $commodityTypes = [
            ['name' => 'AbilityOne', 'approved' => true],
            ['name' => 'Abrasives', 'approved' => true],
            ['name' => 'Adhesives, Sealants and Tape', 'approved' => true],
            ['name' => 'Binding, Filing, Labeling', 'approved' => true],
            ['name' => 'Breakroom', 'approved' => true],
            ['name' => 'Breakroom/Janitorial/Maintenance', 'approved' => true],
            ['name' => 'Business Machines', 'approved' => true],
            ['name' => 'Chemical, Lubricants and Paints', 'approved' => true],
            ['name' => 'Computer Equipment', 'approved' => true],
            ['name' => 'Computer Equipment-Service Plan', 'approved' => true],
            ['name' => 'Copy Paper', 'approved' => true],
            ['name' => 'Crafts/Class/Recreation Room', 'approved' => true],
            ['name' => 'Custom Imprint/Stamp', 'approved' => true],
            ['name' => 'Custom/Special', 'approved' => true],
            ['name' => 'Dated Goods', 'approved' => true],
            ['name' => 'Electrical and Lighting', 'approved' => true],
            ['name' => 'Envelopes/Mailers/Shipping Supplies', 'approved' => true],
            ['name' => 'Fastener Products', 'approved' => true],
            ['name' => 'Fee', 'approved' => true],
            ['name' => 'Ferguson', 'approved' => true],
            ['name' => 'Food Service', 'approved' => true],
            ['name' => 'Foodservice Supplies', 'approved' => true],
            ['name' => 'Forms/Recordkeeping/Reference Materials', 'approved' => true],
            ['name' => 'Furniture', 'approved' => true],
            ['name' => 'Furniture & Interiors', 'approved' => true],
            ['name' => 'General MRO Supplies', 'approved' => true],
            ['name' => 'General Supplies', 'approved' => true],
            ['name' => 'GSA', 'approved' => true],
            ['name' => 'HVAC', 'approved' => true],
            ['name' => 'Industrial', 'approved' => true],
            ['name' => 'Ink and Toner', 'approved' => true],
            ['name' => 'Janitorial & Facility Supplies', 'approved' => true],
            ['name' => 'Janitorial & Sanitation', 'approved' => true],
            ['name' => 'Janitorial Equipment', 'approved' => true],
            ['name' => 'Lagasse', 'approved' => true],
            ['name' => 'Marking Tools', 'approved' => true],
            ['name' => 'Material Handling', 'approved' => true],
            ['name' => 'Money Handling Products', 'approved' => true],
            ['name' => 'New Item', 'approved' => true],
            ['name' => 'NIB/NISH PRODUCTS', 'approved' => true],
            ['name' => 'Notebooks, Writing Pads', 'approved' => true],
            ['name' => 'Office', 'approved' => true],
            ['name' => 'Office Supplies', 'approved' => true],
            ['name' => 'Office/Desk Accessories', 'approved' => true],
            ['name' => 'ORS', 'approved' => true],
            ['name' => 'Paper', 'approved' => true],
            ['name' => 'Safety', 'approved' => true],
            ['name' => 'Safety & PPE', 'approved' => true],
            ['name' => 'School Supplies', 'approved' => true],
            ['name' => 'Tactical', 'approved' => true],
            ['name' => 'Technology', 'approved' => true],
            ['name' => 'Toner/OEM/Ink/Ribbon', 'approved' => true],
            ['name' => 'Toner/OEM/Laser/Other', 'approved' => true],
            ['name' => 'Toner/Reman/Ink/Ribbon', 'approved' => true],
            ['name' => 'Toner/Reman/Laser/Other', 'approved' => true],
            ['name' => 'Tools', 'approved' => true],
            ['name' => 'Welding Supplies', 'approved' => true],
            ['name' => 'Writing Instruments', 'approved' => true],
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
