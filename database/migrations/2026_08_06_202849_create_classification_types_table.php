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
        Schema::create('classification_types', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g. UNSPSC,
            $table->string('label', 50);
            $table->string('default_value')->nullable();
            $table->boolean('is_always_required')->default(false);
            $table->timestamps();
        });

        $this->insertData();
    }

    protected function insertData(): void
    {
        $classifications = [
            ['id' => 1,  'required' => false, 'key' => 'ABILITYONE_INDICATOR', 'default_value' => 'Y', 'label' => 'AbilityOne Indicator'],
            ['id' => 2,  'required' => false, 'key' => 'Application', 'default_value' => null, 'label' => 'Application'],
            ['id' => 3,  'required' => false, 'key' => 'ASSEMBLY_CODE', 'default_value' => 'Y', 'label' => 'Assembly Code'],
            ['id' => 4,  'required' => false, 'key' => 'BEP-Certified', 'default_value' => 'Y', 'label' => 'BEP-Certified'],
            ['id' => 5,  'required' => false, 'key' => 'California Prop 65 Warning', 'default_value' => 'Y', 'label' => 'California Prop 65 Warning'],
            ['id' => 6,  'required' => false, 'key' => 'Certified_Black_Owned', 'default_value' => 'Y', 'label' => 'Certified Black Owned'],
            ['id' => 7,  'required' => false, 'key' => 'Certified_Disadvantaged_Business_Enterprise', 'default_value' => 'Y', 'label' => 'Certified Disadvantaged Business Enterprise'],
            ['id' => 8,  'required' => false, 'key' => 'Certified_NMSDC_MBE', 'default_value' => 'Y', 'label' => 'Certified NMSDC MBE'],
            ['id' => 9,  'required' => false, 'key' => 'Clearance', 'default_value' => 'Y', 'label' => 'Clearance'],
            ['id' => 10, 'required' => true,  'key' => 'Country of Origin', 'default_value' => null, 'label' => 'Country of Origin'],
            ['id' => 11, 'required' => false, 'key' => 'Dated Goods', 'default_value' => 'Y', 'label' => 'Dated Goods'],
            ['id' => 12, 'required' => false, 'key' => 'Delivery', 'default_value' => 'Y', 'label' => 'Delivery'],
            ['id' => 13, 'required' => false, 'key' => 'Disabled Owned', 'default_value' => 'Y', 'label' => 'Disabled Owned'],
            ['id' => 14, 'required' => false, 'key' => 'Dropship', 'default_value' => 'Y', 'label' => 'Dropship'],
            ['id' => 15, 'required' => false, 'key' => 'Dropship 5-7', 'default_value' => 'Y', 'label' => 'Dropship 5-7'],
            ['id' => 16, 'required' => false, 'key' => 'EAN', 'default_value' => null, 'label' => 'EAN'],
            ['id' => 17, 'required' => false, 'key' => 'Eclass Code', 'default_value' => null, 'label' => 'EClass Code'],
            ['id' => 18, 'required' => false, 'key' => 'EPACPGCompliant_Code', 'default_value' => 'Y', 'label' => 'EPA CPG Compliant Code'],
            ['id' => 19, 'required' => false, 'key' => 'ETS', 'default_value' => null, 'label' => 'ETS'],
            ['id' => 20, 'required' => false, 'key' => 'Extended Delivery', 'default_value' => 'Y', 'label' => 'Extended Delivery'],
            ['id' => 21, 'required' => false, 'key' => 'FSA Eligible', 'default_value' => 'Y', 'label' => 'FSA Eligible'],
            ['id' => 22, 'required' => false, 'key' => 'Green_Indicator', 'default_value' => 'Y', 'label' => 'Green Indicator'],
            ['id' => 23, 'required' => false, 'key' => 'Green_Information', 'default_value' => null, 'label' => 'Green Information'],
            ['id' => 24, 'required' => false, 'key' => 'GSA', 'default_value' => 'Y', 'label' => 'GSA'],
            ['id' => 25, 'required' => false, 'key' => 'GSA_SIN', 'default_value' => null, 'label' => 'GSA SIN'],
            ['id' => 26, 'required' => false, 'key' => 'GTIN', 'default_value' => null, 'label' => 'GTIN'],
            ['id' => 27, 'required' => false, 'key' => 'Harmonization Code', 'default_value' => null, 'label' => 'Harmonization Code'],
            ['id' => 28, 'required' => false, 'key' => 'Hazmat', 'default_value' => 'Y', 'label' => 'Hazmat'],
            ['id' => 29, 'required' => false, 'key' => 'HUBLGBTQ', 'default_value' => 'Y', 'label' => 'HUB LGBTQ'],
            ['id' => 30, 'required' => false, 'key' => 'HUBMBE', 'default_value' => 'Y', 'label' => 'HUB MBE'],
            ['id' => 31, 'required' => false, 'key' => 'HUBSupplier', 'default_value' => 'Y', 'label' => 'HUB Supplier'],
            ['id' => 32, 'required' => false, 'key' => 'Local_Code', 'default_value' => 'Y', 'label' => 'Local Code'],
            ['id' => 33, 'required' => false, 'key' => 'Minority Business Enterprise', 'default_value' => null, 'label' => 'Minority Business Enterprise'],
            ['id' => 34, 'required' => false, 'key' => 'MSDS_INDICATOR', 'default_value' => 'Y', 'label' => 'MSDS Indicator'],
            ['id' => 35, 'required' => false, 'key' => 'MSDS_URL', 'default_value' => null, 'label' => 'MSDS URL'],
            ['id' => 36, 'required' => false, 'key' => 'National Stock Number', 'default_value' => null, 'label' => 'National Stock Number'],
            ['id' => 37, 'required' => false, 'key' => 'NIGP Code', 'default_value' => null, 'label' => 'NIGP Code'],
            ['id' => 38, 'required' => false, 'key' => 'Non_Returnable_Code', 'default_value' => 'Y', 'label' => 'Non-Returnable Code'],
            ['id' => 39, 'required' => false, 'key' => 'OverweightOversize_Indicator', 'default_value' => 'Y', 'label' => 'Overweight/Oversize Indicator'],
            ['id' => 40, 'required' => false, 'key' => 'PhD_Indicator:', 'default_value' => 'Y', 'label' => 'Recycle Indicator'],
            ['id' => 41, 'required' => false, 'key' => 'Recycle_Indicator', 'default_value' => 'Y', 'label' => 'Recycle Indicator'],
            ['id' => 42, 'required' => false, 'key' => 'Returnable Item', 'default_value' => 'Y', 'label' => 'Returnable Item'],
            ['id' => 43, 'required' => false, 'key' => 'SML_PKG_Indicator', 'default_value' => 'Y', 'label' => 'Small Package Indicator'],
            ['id' => 44, 'required' => false, 'key' => 'Special Order', 'default_value' => 'Y', 'label' => 'Special Order'],
            ['id' => 45, 'required' => false, 'key' => 'Stock Status', 'default_value' => null, 'label' => 'Stock Status'],
            ['id' => 46, 'required' => false, 'key' => 'Storage Requirements', 'default_value' => 'Y', 'label' => 'Storage Requirements'],
            ['id' => 47, 'required' => false, 'key' => 'Store Pick-Up Only', 'default_value' => null, 'label' => 'Store Pick-Up Only'],
            ['id' => 48, 'required' => false, 'key' => 'TAA', 'default_value' => 'Y', 'label' => 'Trade Agreements Act (TAA)'],
            ['id' => 49, 'required' => true,  'key' => 'UNSPSC', 'default_value' => null, 'label' => 'UNSPSC'],
            ['id' => 50, 'required' => false, 'key' => 'UPC_RTL', 'default_value' => null, 'label' => 'Retail UPC'],
            ['id' => 51, 'required' => false, 'key' => 'Veteran Owned', 'default_value' => null, 'label' => 'Veteran Owned'],
            ['id' => 52, 'required' => false, 'key' => 'Warranty', 'default_value' => 'Y', 'label' => 'Warranty'],
            ['id' => 53, 'required' => false, 'key' => 'Warranty Information', 'default_value' => null, 'label' => 'Warranty Information'],
            ['id' => 54, 'required' => false, 'key' => 'WBE_Indicator', 'default_value' => 'Y', 'label' => 'Women Business Enterprise Indicator'],
            ['id' => 55, 'required' => false, 'key' => 'Women Owned', 'default_value' => 'Y', 'label' => 'Women Owned'],
        ];

        $now = now();
        $classificationTypes = collect($classifications)
            ->map(function ($type) use ($now) {

                return [
                    'id' => $type['id'],
                    'key' => $type['key'],
                    'label' => $type['label'],
                    'default_value' => $type['default_value'],
                    'is_always_required' => $type['required'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->toArray();

        DB::table('classification_types')->insert($classificationTypes);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classification_types');
    }
};
