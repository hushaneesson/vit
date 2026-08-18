<?php

namespace Tests\Unit;

use App\Services\CatalogRowValidator;
use PHPUnit\Framework\TestCase;

class CatalogRowValidatorTest extends TestCase
{
    private function fieldDefinitions(): \Illuminate\Support\Collection
    {
        return collect([
            (object) [
                'field_key' => 'dealer_sku',
                'web_app_label' => 'Seller SKU',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'is_multi_value' => false,
                'max_length' => 255,
                'conditional_on_field' => null,
                'conditional_on_value' => null,
            ],
            (object) [
                'field_key' => 'description',
                'web_app_label' => 'Description',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'is_multi_value' => false,
                'max_length' => 4000,
                'conditional_on_field' => null,
                'conditional_on_value' => null,
            ],
            (object) [
                'field_key' => 'manufacturer',
                'web_app_label' => 'Manufacturer',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'is_multi_value' => false,
                'max_length' => 255,
                'conditional_on_field' => null,
                'conditional_on_value' => null,
            ],
            (object) [
                'field_key' => 'name',
                'web_app_label' => 'Item Name',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'is_multi_value' => false,
                'max_length' => 255,
                'conditional_on_field' => null,
                'conditional_on_value' => null,
            ],
            (object) [
                'field_key' => 'list_price',
                'web_app_label' => 'List Price',
                'requirement_type' => 'required',
                'field_type' => 'decimal',
                'is_multi_value' => false,
                'max_length' => null,
                'conditional_on_field' => null,
                'conditional_on_value' => null,
            ],
            (object) [
                'field_key' => 'discontinued',
                'web_app_label' => 'Item Discontinued',
                'requirement_type' => 'optional',
                'field_type' => 'boolean',
                'is_multi_value' => false,
                'max_length' => 255,
                'conditional_on_field' => null,
                'conditional_on_value' => null,
            ],
            (object) [
                'field_key' => 'discontinued_date',
                'web_app_label' => 'Discontinued Date',
                'requirement_type' => 'conditional',
                'field_type' => 'text',
                'is_multi_value' => false,
                'max_length' => 255,
                'conditional_on_field' => 'discontinued',
                'conditional_on_value' => 'TRUE',
            ],
            (object) [
                'field_key' => 'classifications',
                'web_app_label' => 'Classifications',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'is_multi_value' => true,
                'join_separator' => ',',
                'max_length' => 255,
                'conditional_on_field' => null,
                'conditional_on_value' => null,
            ],
            (object) [
                'field_key' => 'msds_link',
                'web_app_label' => 'MSDS Link',
                'requirement_type' => 'conditional',
                'field_type' => 'text',
                'is_multi_value' => false,
                'max_length' => 255,
                'conditional_on_field' => 'classifications',
                'conditional_on_value' => 'Hazmat',
            ],
        ]);
    }

    public function test_fully_valid_row_has_no_warnings_and_no_errors(): void
    {
        $validator = new CatalogRowValidator();

        $result = $validator->validate($this->fieldDefinitions(), [
            'dealer_sku' => 'SKU-1',
            'description' => 'Full product description',
            'manufacturer' => 'Acme',
            'name' => 'Widget',
            'list_price' => '10.00',
            'discontinued' => 'FALSE',
            'classifications' => ['EPP'],
        ]);

        $this->assertSame([], $result['warnings']);
        $this->assertSame([], $result['errors']);
    }

    public function test_missing_description_is_warning_and_row_is_still_importable(): void
    {
        $validator = new CatalogRowValidator();

        $result = $validator->validate($this->fieldDefinitions(), [
            'dealer_sku' => 'SKU-2',
            'description' => '',
            'manufacturer' => 'Acme',
            'name' => 'Widget',
            'list_price' => '10.00',
            'discontinued' => 'FALSE',
            'classifications' => ['EPP'],
        ]);

        $this->assertCount(1, $result['warnings']);
        $this->assertSame('description', $result['warnings'][0]['field_key']);
        $this->assertSame([], $result['errors']);
    }

    public function test_missing_manufacturer_is_warning_and_row_is_still_importable(): void
    {
        $validator = new CatalogRowValidator();

        $result = $validator->validate($this->fieldDefinitions(), [
            'dealer_sku' => 'SKU-3',
            'description' => 'Description',
            'manufacturer' => '',
            'name' => 'Widget',
            'list_price' => '10.00',
            'discontinued' => 'FALSE',
            'classifications' => ['EPP'],
        ]);

        $this->assertCount(1, $result['warnings']);
        $this->assertSame('manufacturer', $result['warnings'][0]['field_key']);
        $this->assertSame([], $result['errors']);
    }

    public function test_missing_several_required_business_fields_are_multiple_warnings(): void
    {
        $validator = new CatalogRowValidator();

        $result = $validator->validate($this->fieldDefinitions(), [
            'dealer_sku' => 'SKU-4',
            'description' => '',
            'manufacturer' => '',
            'name' => 'Widget',
            'list_price' => '10.00',
            'discontinued' => 'FALSE',
            'classifications' => ['EPP'],
        ]);

        $this->assertCount(2, $result['warnings']);
        $this->assertSame([], $result['errors']);
    }

    public function test_missing_dealer_sku_is_blocking_error_and_not_importable(): void
    {
        $validator = new CatalogRowValidator();

        $result = $validator->validate($this->fieldDefinitions(), [
            'dealer_sku' => '',
            'description' => 'Description',
            'manufacturer' => 'Acme',
            'name' => 'Widget',
            'list_price' => '10.00',
            'discontinued' => 'FALSE',
            'classifications' => ['EPP'],
        ]);

        $this->assertSame([], $result['warnings']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('dealer_sku', $result['errors'][0]['field_key']);
    }

    public function test_invalid_numeric_value_is_blocking_error(): void
    {
        $validator = new CatalogRowValidator();

        $result = $validator->validate($this->fieldDefinitions(), [
            'dealer_sku' => 'SKU-6',
            'description' => 'Description',
            'manufacturer' => 'Acme',
            'name' => 'Widget',
            'list_price' => 'not-a-number',
            'discontinued' => 'FALSE',
            'classifications' => ['EPP'],
        ]);

        $this->assertSame([], $result['warnings']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('List Price', $result['errors'][0]['message']);
    }

    public function test_invalid_boolean_value_is_blocking_error(): void
    {
        $validator = new CatalogRowValidator();

        $result = $validator->validate($this->fieldDefinitions(), [
            'dealer_sku' => 'SKU-7',
            'description' => 'Description',
            'manufacturer' => 'Acme',
            'name' => 'Widget',
            'list_price' => '10.00',
            'discontinued' => 'maybe',
            'classifications' => ['EPP'],
        ]);

        $this->assertSame([], $result['warnings']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('discontinued', $result['errors'][0]['field_key']);
    }

    public function test_discontinued_true_without_discontinued_date_is_warning(): void
    {
        $validator = new CatalogRowValidator();

        $result = $validator->validate($this->fieldDefinitions(), [
            'dealer_sku' => 'SKU-8',
            'description' => 'Description',
            'manufacturer' => 'Acme',
            'name' => 'Widget',
            'list_price' => '10.00',
            'discontinued' => 'TRUE',
            'classifications' => ['EPP'],
        ]);

        $this->assertCount(1, $result['warnings']);
        $this->assertSame('discontinued_date', $result['warnings'][0]['field_key']);
        $this->assertSame([], $result['errors']);
    }

    public function test_hazmat_classification_without_msds_link_is_warning(): void
    {
        $validator = new CatalogRowValidator();

        $result = $validator->validate($this->fieldDefinitions(), [
            'dealer_sku' => 'SKU-9',
            'description' => 'Description',
            'manufacturer' => 'Acme',
            'name' => 'Widget',
            'list_price' => '10.00',
            'discontinued' => 'FALSE',
            'classifications' => ['Hazmat'],
        ]);

        $this->assertCount(1, $result['warnings']);
        $this->assertSame('msds_link', $result['warnings'][0]['field_key']);
        $this->assertSame([], $result['errors']);
    }
}
