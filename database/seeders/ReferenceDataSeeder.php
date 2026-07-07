<?php

namespace Database\Seeders;

use App\Models\ClassificationType;
use App\Models\CommodityType;
use App\Models\CountryCode;
use App\Models\ProductHierarchy;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedUnitsOfMeasure();
        $this->seedCountryCodes();
        $this->seedClassificationTypes();
        $this->seedCommodityTypes();
        $this->seedSampleHierarchy();
    }

    protected function seedUnitsOfMeasure(): void
    {
        $uoms = [
            ['code' => 'EA', 'description' => 'Each'],
            ['code' => 'CS', 'description' => 'Case'],
            ['code' => 'BX', 'description' => 'Box'],
            ['code' => 'PK', 'description' => 'Pack'],
            ['code' => 'RM', 'description' => 'Ream'],
            ['code' => 'DZ', 'description' => 'Dozen'],
            ['code' => 'PR', 'description' => 'Pair'],
            ['code' => 'RL', 'description' => 'Roll'],
            ['code' => 'BT', 'description' => 'Bottle'],
            ['code' => 'GA', 'description' => 'Gallon'],
        ];

        foreach ($uoms as $i => $uom) {
            UnitOfMeasure::firstOrCreate(
                ['code' => $uom['code']],
                ['description' => $uom['description'], 'sort_order' => $i, 'active' => true]
            );
        }
    }

    protected function seedCountryCodes(): void
    {
        $countries = [
            ['code' => 'US', 'name' => 'United States'],
            ['code' => 'CA', 'name' => 'Canada'],
            ['code' => 'MX', 'name' => 'Mexico'],
            ['code' => 'CN', 'name' => 'China'],
            ['code' => 'GB', 'name' => 'United Kingdom'],
            ['code' => 'DE', 'name' => 'Germany'],
            ['code' => 'JP', 'name' => 'Japan'],
            ['code' => 'IN', 'name' => 'India'],
            ['code' => 'VN', 'name' => 'Vietnam'],
            ['code' => 'TW', 'name' => 'Taiwan'],
        ];

        foreach ($countries as $country) {
            CountryCode::firstOrCreate(['code' => $country['code']], ['name' => $country['name'], 'active' => true]);
        }
    }

    protected function seedClassificationTypes(): void
    {
        // key => [label, always_required]
        $types = [
            'UNSPSC' => ['UNSPSC Code', true],
            'Country of Origin' => ['Country of Origin', true],
            'UPC_RTL' => ['UPC', false],
            'GTIN' => ['GTIN', false],
            'MSDS URL' => ['MSDS Link (Hazmat)', false],
            'National Stock Number' => ['National Stock Number', false],
            'NIGP Code' => ['NIGP Code', false],
            'Warranty Information' => ['Warranty Indicator', false],
            'Green_Indicator' => ['Green Indicator', false],
        ];

        $i = 0;
        foreach ($types as $key => [$label, $alwaysRequired]) {
            ClassificationType::firstOrCreate(
                ['key' => $key],
                [
                    'label' => $label,
                    'is_always_required' => $alwaysRequired,
                    'active' => true,
                    'sort_order' => $i++,
                ]
            );
        }
    }

    protected function seedCommodityTypes(): void
    {
        // First option is literally "Use the name of Category Level 2" per
        // VIT spec — if selected, the app auto-fills with Category Level 2.
        $types = [
            'Use the name of Category Level 2',
            'Office Supplies',
            'Furniture',
            'Technology',
            'Janitorial & Sanitation',
            'Break Room & Food Service',
        ];

        foreach ($types as $i => $name) {
            CommodityType::firstOrCreate(['name' => $name], ['sort_order' => $i, 'active' => true]);
        }
    }

    protected function seedSampleHierarchy(): void
    {
        // A small starter hierarchy so Phase 4/6 have something to pick
        // from immediately. Admin can add more via the admin panel.
        $level1 = ProductHierarchy::firstOrCreate(
            ['path' => 'Office Supplies'],
            ['level' => 1, 'name' => 'Office Supplies', 'active' => true]
        );

        $level2 = ProductHierarchy::firstOrCreate(
            ['path' => 'Office Supplies!Paper Products'],
            ['level' => 2, 'name' => 'Paper Products', 'parent_id' => $level1->id, 'active' => true]
        );

        ProductHierarchy::firstOrCreate(
            ['path' => 'Office Supplies!Paper Products!Copy Paper'],
            [
                'level' => 3,
                'name' => 'Copy Paper',
                'parent_id' => $level2->id,
                'hierarchy_number' => '10001',
                'active' => true,
            ]
        );
    }
}
