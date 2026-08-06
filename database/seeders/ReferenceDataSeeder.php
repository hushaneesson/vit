<?php

namespace Database\Seeders;

use App\Models\CommodityType;
use App\Models\CountryCode;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCountryCodes();
        $this->seedCommodityTypes();
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
}
