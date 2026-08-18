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
            $table->boolean('is_always_required')->default(false);
            $table->boolean('is_boolean')->default(false);
            $table->timestamps();
        });

        $this->insertData();
    }

    protected function insertData(): void
    {
        $classifications = [
            ['id' => 1,  'required' => false, 'key' => 'ABILITYONE_INDICATOR', 'label' => 'AbilityOne Indicator'],
            ['id' => 2,  'required' => false, 'key' => 'ASSEMBLY_CODE', 'label' => 'Assembly Code'],
            ['id' => 3,  'required' => false, 'key' => 'CLEARANCE', 'label' => 'Clearance'],
            ['id' => 4,  'required' => true,  'key' => 'COUNTRY_OF_ORIGIN', 'label' => 'Country of Origin'],
            ['id' => 5,  'required' => false, 'key' => 'DATED_GOODS', 'label' => 'Dated Goods'],
            ['id' => 6,  'required' => false, 'key' => 'DELIVERY', 'label' => 'Delivery'],
            ['id' => 7,  'required' => false, 'key' => 'DISABLED_OWNED', 'label' => 'Disabled Owned'],
            ['id' => 8,  'required' => false, 'key' => 'DROPSHIP', 'label' => 'Dropship'],
            ['id' => 9,  'required' => false, 'key' => 'DROPSHIP_5_7', 'label' => 'Dropship 5-7'],
            ['id' => 10, 'required' => false, 'key' => 'ECLASS_CODE', 'label' => 'EClass Code'],
            ['id' => 11, 'required' => false, 'key' => 'EPACPGCOMPLIANT_CODE', 'label' => 'EPA CPG Compliant Code'],
            ['id' => 12, 'required' => false, 'key' => 'ETS', 'label' => 'ETS'],
            ['id' => 13, 'required' => false, 'key' => 'EXTENDEDDELIVERY', 'label' => 'Extended Delivery'],
            ['id' => 14, 'required' => false, 'key' => 'GREEN', 'label' => 'Green'],
            ['id' => 15, 'required' => false, 'key' => 'GREEN_INDICATOR', 'label' => 'Green Indicator'],
            ['id' => 16, 'required' => false, 'key' => 'GREEN_INFORMATION', 'label' => 'Green Information'],
            ['id' => 17, 'required' => false, 'key' => 'GSA', 'label' => 'GSA'],
            ['id' => 18, 'required' => false, 'key' => 'GSA_SIN', 'label' => 'GSA SIN'],
            ['id' => 19, 'required' => false, 'key' => 'HARMONIZATION_CODE', 'label' => 'Harmonization Code'],
            ['id' => 20, 'required' => false, 'key' => 'HAZMAT', 'label' => 'Hazmat'],
            ['id' => 21, 'required' => false, 'key' => 'HUBLGBTQ', 'label' => 'HUB LGBTQ'],
            ['id' => 22, 'required' => false, 'key' => 'HUBMBE', 'label' => 'HUB MBE'],
            ['id' => 23, 'required' => false, 'key' => 'HUBSUPPLIER', 'label' => 'HUB Supplier'],
            ['id' => 24, 'required' => false, 'key' => 'LOCAL_CODE', 'label' => 'Local Code'],
            ['id' => 25, 'required' => false, 'key' => 'MINORITY_BUSINESS_ENTERPRISE', 'label' => 'Minority Business Enterprise'],
            ['id' => 26, 'required' => false, 'key' => 'MSDS_URL', 'label' => 'MSDS URL'],
            ['id' => 27, 'required' => false, 'key' => 'MSDS_INDICATOR', 'label' => 'MSDS Indicator'],
            ['id' => 28, 'required' => false, 'key' => 'NATIONAL_STOCK_NUMBER', 'label' => 'National Stock Number'],
            ['id' => 29, 'required' => false, 'key' => 'NIGP_CODE', 'label' => 'NIGP Code'],
            ['id' => 30, 'required' => false, 'key' => 'NON_RETURNABLE_CODE', 'label' => 'Non-Returnable Code'],
            ['id' => 31, 'required' => false, 'key' => 'OVERWEIGHTOVERSIZE_INDICATOR', 'label' => 'Overweight/Oversize Indicator'],
            ['id' => 32, 'required' => false, 'key' => 'RECYCLE_INDICATOR', 'label' => 'Recycle Indicator'],
            ['id' => 33, 'required' => false, 'key' => 'SML_PKG_INDICATOR', 'label' => 'Small Package Indicator'],
            ['id' => 34, 'required' => false, 'key' => 'SPECIAL_ORDER', 'label' => 'Special Order'],
            ['id' => 35, 'required' => false, 'key' => 'STORE_PICKUP_ONLY', 'label' => 'Store Pick-Up Only'],
            ['id' => 36, 'required' => false, 'key' => 'TAA', 'label' => 'Trade Agreements Act (TAA)'],
            ['id' => 37, 'required' => true,  'key' => 'UNSPSC', 'label' => 'UNSPSC'],
            ['id' => 38, 'required' => false, 'key' => 'UPC_RTL', 'label' => 'Retail UPC'],
            ['id' => 39, 'required' => false, 'key' => 'VETERAN_OWNED', 'label' => 'Veteran Owned'],
            ['id' => 40, 'required' => false, 'key' => 'WARRANTY', 'label' => 'Warranty'],
            ['id' => 41, 'required' => false, 'key' => 'WARRANTY_INFORMATION', 'label' => 'Warranty Information'],
            ['id' => 42, 'required' => false, 'key' => 'WBE_INDICATOR', 'label' => 'Women Business Enterprise Indicator'],
            ['id' => 43, 'required' => false, 'key' => 'WOMEN_OWNED', 'label' => 'Women Owned'],
        ];
        $now = now();
        $classificationTypes = collect($classifications)
            ->map(function ($type) use ($now) {

                return [
                    'id' => $type['id'],
                    'key' => $type['key'],
                    'label' => $type['label'],
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
