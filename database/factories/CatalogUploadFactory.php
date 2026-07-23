<?php

namespace Database\Factories;

use App\Enums\CatalogUploadStatus;
use App\Models\Client;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CatalogUpload>
 */
class CatalogUploadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'vendor_id' => fn(array $attrs) => Client::find($attrs['client_id'])?->vendor_id ?? Vendor::factory(),
            'original_filename' => $this->faker->word() . '.csv',
            'catalog_name' => $this->faker->words(3, true),
            'file_path' => 'catalog-uploads/' . $this->faker->uuid() . '.csv',
            'disk' => 'local',
            'file_type' => 'csv',
            'status' => CatalogUploadStatus::Completed,
            'total_rows' => 0,
            'success_rows' => 0,
            'error_rows' => 0,
            'updated_rows' => 0,
            'skipped_rows' => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn(array $attrs) => [
            'status' => CatalogUploadStatus::Completed,
            'processing_completed_at' => now(),
        ]);
    }

    public function withErrors(int $count): static
    {
        return $this->state(fn(array $attrs) => [
            'error_rows' => $count,
        ]);
    }
}
