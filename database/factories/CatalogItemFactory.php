<?php

namespace Database\Factories;

use App\Models\CatalogItem;
use App\Models\Vendor;
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
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->paragraph(),

            'manufacturer_sku' => $this->faker->optional()->bothify('MFR-####??'),
            'manufacturer_name' => $this->faker->optional()->company(),
            'brand_name' => $this->faker->optional()->company(),

            'vendor_sku' => Str::upper($this->faker->unique()->bothify('SKU-#####??')),
            'unspsc_code' => $this->faker->numerify('########'),
            'product_type' => $this->faker->randomElement(['ELINK', 'STOCK', 'MADE_TO_ORDER']),

            'unit_of_measure' => $this->faker->randomElement(['EA', 'BX', 'CS', 'PK', 'RM']),
            'quantity_per_unit' => $this->faker->randomElement([1, 5, 10, 12, 24, 50]),
            'weight' => $this->faker->randomFloat(2, 0.1, 50),
            'min_order_quantity' => $this->faker->optional()->numberBetween(1, 10),
            'max_order_quantity' => $this->faker->optional()->numberBetween(50, 500),
            'multiples' => $this->faker->optional()->randomElement([1, 2, 5, 10]),

            'search_terms' => $this->faker->words($this->faker->numberBetween(2, 6)),
            'classifications' => $this->faker->optional()->randomElements(['Hazmat', 'Recycled Content', 'Energy Star', 'Made in USA', 'BPA Free']),
            'specifications' => collect(range(1, $this->faker->numberBetween(1, 4)))
                ->map(fn() => [
                    'key' => $this->faker->randomElement(['Color', 'Material', 'Size', 'Finish']),
                    'value' => $this->faker->word(),
                ])
                ->all(),
            'selling_points' => $this->faker->sentences($this->faker->numberBetween(1, 4)),
            'msds_link' => $this->faker->optional()->url(),
            'list_price' => $listPrice,
            'selling_price' => $this->faker->randomFloat(2, 1, $listPrice),
        ];
    }
}
