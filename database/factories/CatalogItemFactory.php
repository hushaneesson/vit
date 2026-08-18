<?php

namespace Database\Factories;

use App\Models\CatalogItem;
use App\Models\CommodityType;
use App\Models\ProductHierarchy;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CatalogItem>
 */
class CatalogItemFactory extends Factory
{
    protected $model = CatalogItem::class;

    public function definition(): array
    {
        $listPrice = $this->faker->randomFloat(2, 5, 500);

        return [
            'dealer_sku' => Str::upper($this->faker->unique()->bothify('SKU-#####??')),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->paragraph(),

            'manufacturer_sku' => $this->faker->optional()->bothify('MFR-####??'),
            'manufacturer' => $this->faker->optional()->company(),
            'brand_name' => $this->faker->optional()->company(),

            'hierarchy' => ProductHierarchy::where('level', 3)->inRandomOrder()->first()?->hierarchy_number,
            'category' => CommodityType::inRandomOrder()->first()?->id,

            'item_weight' => $this->faker->randomFloat(2, 0.1, 50),
            'availability' => $this->faker->numberBetween(0, 1000),
            'lead_time' => $this->faker->randomElement(['0-3 days', '3-5 days', '5-10 days', '10 & over']),

            'unit_of_measure' => UnitOfMeasure::inRandomOrder()->first()?->id,
            'quantity_per_unit' => $this->faker->randomElement([1, 5, 10, 12, 24, 50]),
            'min_qty_per_order' => $this->faker->optional()->numberBetween(1, 10),
            'max_qty_per_order' => $this->faker->optional()->numberBetween(50, 500),
            'multiples' => $this->faker->optional()->randomElement([1, 2, 5, 10]),

            'search_terms' => $this->faker->words($this->faker->numberBetween(2, 6)),
            'selling_points' => $this->faker->sentences($this->faker->numberBetween(1, 4)),

            'classifications' => $this->faker->optional()->randomElements(['Hazmat', 'Recycled Content', 'Energy Star', 'Made in USA', 'BPA Free']),
            'unspsc_code' => $this->faker->numerify('########'),
            'msds_link' => $this->faker->optional()->url(),

            'specifications' => [
                [
                    'key' => 'Color',
                    'value' => $this->faker->safeColorName(),
                ],
                [
                    'key' => 'Size',
                    'value' => $this->faker->randomElement(['Small', 'Medium', 'Large']),
                ]
            ],

            'list_price' => $listPrice,
            'selling_price' => $this->faker->randomFloat(2, 1, $listPrice),
        ];
    }
}
