<?php

namespace Database\Factories;

use App\Models\CatalogUpload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CatalogUploadRow>
 */
class CatalogUploadRowFactory extends Factory
{
    protected $model = \App\Models\CatalogUploadRow::class;

    public function definition(): array
    {
        return [
            'catalog_upload_id' => CatalogUpload::factory(),
            'row_number' => $this->faker->numberBetween(1, 100),
            'data' => [],
            'raw_data' => [],
            'status' => 'valid',
            'errors' => null,
        ];
    }

    public function invalid(): static
    {
        return $this->state(fn(array $attrs) => [
            'status' => 'invalid',
            'errors' => [
                [
                    'field_key' => 'name',
                    'message' => 'Item Name is required.',
                ],
                [
                    'field_key' => 'list_price',
                    'message' => 'List Price must be a number.',
                ],
            ],
        ]);
    }

    public function withSingleError(): static
    {
        return $this->state(fn(array $attrs) => [
            'status' => 'invalid',
            'errors' => [
                ['field_key' => 'name', 'message' => 'Item Name is required.'],
            ],
        ]);
    }
}
