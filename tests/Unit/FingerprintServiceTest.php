<?php

namespace Tests\Unit;

use App\Services\FingerprintService;
use Tests\TestCase;

class FingerprintServiceTest extends TestCase
{
    /**
     * Test that identical data produces identical fingerprints.
     */
    public function test_identical_data_produces_identical_fingerprints(): void
    {
        $data = [
            'name' => 'Test Product',
            'description' => 'Test Description',
            'vendor_sku' => 'SKU-123',
            'vendor_id' => 1,
            'catalog_name' => 'Test Catalog',
        ];

        $fingerprint1 = FingerprintService::compute($data);
        $fingerprint2 = FingerprintService::compute($data);

        $this->assertEquals($fingerprint1, $fingerprint2);
    }

    /**
     * Test that excluded columns are not included in fingerprint.
     */
    public function test_excluded_columns_not_included(): void
    {
        $data1 = [
            'name' => 'Product A',
            'vendor_id' => 1,
            'catalog_name' => 'Catalog 1',
            'data_fingerprint' => null,
        ];

        $data2 = [
            'name' => 'Product A',
            'vendor_id' => 999,
            'catalog_name' => 'Catalog 2',
            'data_fingerprint' => null,
        ];

        // These should produce the same fingerprint because excluded columns are ignored
        $this->assertEquals(
            FingerprintService::compute($data1),
            FingerprintService::compute($data2)
        );
    }

    /**
     * Test that different content produces different fingerprints.
     */
    public function test_different_content_produces_different_fingerprints(): void
    {
        $data1 = [
            'name' => 'Product A',
            'description' => 'Description A',
        ];

        $data2 = [
            'name' => 'Product B',
            'description' => 'Description B',
        ];

        $this->assertNotEquals(
            FingerprintService::compute($data1),
            FingerprintService::compute($data2)
        );
    }

    /**
     * Test that empty arrays are handled correctly.
     */
    public function test_empty_arrays_handled(): void
    {
        $data = [
            'name' => 'Product',
            'search_terms' => '[]', // Empty JSON array string
            'classifications' => [],
        ];

        $fingerprint = FingerprintService::compute($data);

        // Should not throw and should produce a valid fingerprint
        $this->assertEquals(32, strlen($fingerprint));
    }

    /**
     * Test that null values are handled correctly.
     */
    public function test_null_values_handled(): void
    {
        $data = [
            'name' => null,
            'description' => '',
        ];

        $fingerprint = FingerprintService::compute($data);

        $this->assertEquals(32, strlen($fingerprint));
    }

    /**
     * Test that numeric values are normalized.
     */
    public function test_numeric_values_normalized(): void
    {
        $data1 = [
            'weight' => 10.0,
            'list_price' => 100,
        ];

        $data2 = [
            'weight' => 10,
            'list_price' => 100.0,
        ];

        // These should produce the same fingerprint because numeric values are normalized
        $this->assertEquals(
            FingerprintService::compute($data1),
            FingerprintService::compute($data2)
        );
    }

    /**
     * Test that array values are sorted.
     */
    public function test_array_values_sorted(): void
    {
        $data1 = [
            'search_terms' => ['c', 'a', 'b'],
        ];

        $data2 = [
            'search_terms' => ['a', 'b', 'c'],
        ];

        // These should produce the same fingerprint because arrays are sorted
        $this->assertEquals(
            FingerprintService::compute($data1),
            FingerprintService::compute($data2)
        );
    }

    /**
     * Test custom excluded columns.
     */
    public function test_custom_excluded_columns(): void
    {
        $data = [
            'name' => 'Product',
            'custom_field' => 'value',
        ];

        $customExcluded = ['custom_field'];
        $fingerprint = FingerprintService::compute($data, $customExcluded);

        $this->assertEquals(32, strlen($fingerprint));
    }
}
