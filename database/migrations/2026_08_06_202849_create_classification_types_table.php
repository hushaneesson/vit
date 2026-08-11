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
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $this->insertData();
    }

    protected function insertData(): void
    {
        // key => [label, always_required]
        $classifications = [
            ['id' => 1,  'required' => false, 'sort_order' => 2, 'key' => 'RESULT', 'label' => 'Result'],
            ['id' => 2,  'required' => false, 'sort_order' => 2, 'key' => 'ABILITYONE_INDICATOR', 'label' => 'AbilityOne Indicator'],
            ['id' => 3,  'required' => false, 'sort_order' => 2, 'key' => 'ASSEMBLY_CODE', 'label' => 'Assembly Code'],
            ['id' => 4,  'required' => false, 'sort_order' => 2, 'key' => 'CLEARANCE', 'label' => 'Clearance'],
            ['id' => 5,  'required' => false, 'sort_order' => 1, 'key' => 'COUNTRY_OF_ORIGIN', 'label' => 'Country of Origin'],
            ['id' => 6,  'required' => false, 'sort_order' => 2, 'key' => 'DATED_GOODS', 'label' => 'Dated Goods'],
            ['id' => 7,  'required' => false, 'sort_order' => 2, 'key' => 'DELIVERY', 'label' => 'Delivery'],
            ['id' => 8,  'required' => false, 'sort_order' => 2, 'key' => 'DISABLED_OWNED', 'label' => 'Disabled Owned'],
            ['id' => 9,  'required' => false, 'sort_order' => 2, 'key' => 'DROPSHIP', 'label' => 'Dropship'],
            ['id' => 10, 'required' => false, 'sort_order' => 2, 'key' => 'DROPSHIP_5_7', 'label' => 'Dropship 5-7'],
            ['id' => 11, 'required' => false, 'sort_order' => 2, 'key' => 'ECLASS_CODE', 'label' => 'EClass Code'],
            ['id' => 12, 'required' => false, 'sort_order' => 2, 'key' => 'EPACPGCOMPLIANT_CODE', 'label' => 'EPA CPG Compliant Code'],
            ['id' => 13, 'required' => false, 'sort_order' => 2, 'key' => 'ETS', 'label' => 'ETS'],
            ['id' => 14, 'required' => false, 'sort_order' => 2, 'key' => 'EXTENDEDDELIVERY', 'label' => 'Extended Delivery'],
            ['id' => 15, 'required' => false, 'sort_order' => 2, 'key' => 'GREEN', 'label' => 'Green'],
            ['id' => 16, 'required' => false, 'sort_order' => 1, 'key' => 'GREEN_INDICATOR', 'label' => 'Green Indicator'],
            ['id' => 17, 'required' => false, 'sort_order' => 1, 'key' => 'GREEN_INFORMATION', 'label' => 'Green Information'],
            ['id' => 18, 'required' => false, 'sort_order' => 2, 'key' => 'GSA', 'label' => 'GSA'],
            ['id' => 19, 'required' => false, 'sort_order' => 2, 'key' => 'GSA_SIN', 'label' => 'GSA SIN'],
            ['id' => 20, 'required' => false, 'sort_order' => 2, 'key' => 'HARMONIZATION_CODE', 'label' => 'Harmonization Code'],
            ['id' => 21, 'required' => false, 'sort_order' => 2, 'key' => 'HAZMAT', 'label' => 'Hazmat'],
            ['id' => 22, 'required' => false, 'sort_order' => 2, 'key' => 'HUBLGBTQ', 'label' => 'HUB LGBTQ'],
            ['id' => 23, 'required' => false, 'sort_order' => 2, 'key' => 'HUBMBE', 'label' => 'HUB MBE'],
            ['id' => 24, 'required' => false, 'sort_order' => 2, 'key' => 'HUBSUPPLIER', 'label' => 'HUB Supplier'],
            ['id' => 25, 'required' => false, 'sort_order' => 2, 'key' => 'LOCAL_CODE', 'label' => 'Local Code'],
            ['id' => 26, 'required' => false, 'sort_order' => 2, 'key' => 'MINORITY_BUSINESS_ENTERPRISE', 'label' => 'Minority Business Enterprise'],
            ['id' => 27, 'required' => false, 'sort_order' => 1, 'key' => 'MSDS_URL', 'label' => 'MSDS URL'],
            ['id' => 28, 'required' => false, 'sort_order' => 1, 'key' => 'MSDS_INDICATOR', 'label' => 'MSDS Indicator'],
            ['id' => 29, 'required' => true,  'sort_order' => 1, 'key' => 'NATIONAL_STOCK_NUMBER', 'label' => 'National Stock Number'],
            ['id' => 30, 'required' => false, 'sort_order' => 2, 'key' => 'NIGP_CODE', 'label' => 'NIGP Code'],
            ['id' => 31, 'required' => false, 'sort_order' => 2, 'key' => 'NON_RETURNABLE_CODE', 'label' => 'Non-Returnable Code'],
            ['id' => 32, 'required' => false, 'sort_order' => 2, 'key' => 'OVERWEIGHTOVERSIZE_INDICATOR', 'label' => 'Overweight/Oversize Indicator'],
            ['id' => 33, 'required' => false, 'sort_order' => 1, 'key' => 'RECYCLE_INDICATOR', 'label' => 'Recycle Indicator'],
            ['id' => 34, 'required' => false, 'sort_order' => 2, 'key' => 'SML_PKG_INDICATOR', 'label' => 'Small Package Indicator'],
            ['id' => 35, 'required' => false, 'sort_order' => 2, 'key' => 'SPECIAL_ORDER', 'label' => 'Special Order'],
            ['id' => 36, 'required' => false, 'sort_order' => 2, 'key' => 'STORE_PICKUP_ONLY', 'label' => 'Store Pick-Up Only'],
            ['id' => 37, 'required' => false, 'sort_order' => 2, 'key' => 'TAA', 'label' => 'Trade Agreements Act (TAA)'],
            ['id' => 38, 'required' => true,  'sort_order' => 1, 'key' => 'UNSPSC', 'label' => 'UNSPSC'],
            ['id' => 39, 'required' => false, 'sort_order' => 2, 'key' => 'UPC_RTL', 'label' => 'Retail UPC'],
            ['id' => 40, 'required' => false, 'sort_order' => 2, 'key' => 'VETERAN_OWNED', 'label' => 'Veteran Owned'],
            ['id' => 41, 'required' => false, 'sort_order' => 1, 'key' => 'WARRANTY', 'label' => 'Warranty'],
            ['id' => 42, 'required' => true,  'sort_order' => 1, 'key' => 'WARRANTY_INFORMATION', 'label' => 'Warranty Information'],
            ['id' => 43, 'required' => false, 'sort_order' => 2, 'key' => 'WBE_INDICATOR', 'label' => 'Women Business Enterprise Indicator'],
            ['id' => 44, 'required' => false, 'sort_order' => 2, 'key' => 'WOMEN_OWNED', 'label' => 'Women Owned'],
        ];

        $now = now();
        $classificationTypes = collect($classifications)
            ->map(function ($type) use ($now) {

                return [
                    'id' => $type['id'],
                    'key' => $type['key'],
                    'label' => $type['label'],
                    'is_always_required' => $type['required'],
                    'sort_order' => $type['sort_order'],
                    'active' => true,
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
