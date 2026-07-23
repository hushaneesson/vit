<?php

namespace Database\Seeders;

use App\Models\CatalogField;
use Illuminate\Database\Seeder;

/**
 * Seeds the static VIT eLink field list from the "field description" sheet
 * of the VIT vendor catalog data sheet. No category data included.
 */
class CatalogFieldSeeder extends Seeder
{
    public function run(): void
    {
        $fields = [
            ['field_key' => 'seller', 'web_app_label' => 'Seller', 'description' => 'Name of seller', 'requirement_type' => 'required', 'is_system_derived' => true, 'system_source' => 'vendor.name'],
            ['field_key' => 'seller_sku', 'web_app_label' => 'Seller SKU', 'description' => "Seller's part #", 'requirement_type' => 'required'],
            ['field_key' => 'image_file_name', 'web_app_label' => 'Image File Name', 'description' => 'File name of image attachment or the url address where the image is already hosted at', 'notes' => 'Image size 400x400 (.jpg file)', 'requirement_type' => 'optional'],
            ['field_key' => 'manufacturer_sku', 'web_app_label' => 'Manufacturer SKU', 'description' => "Manufacturer's part #", 'requirement_type' => 'optional'],
            ['field_key' => 'manufacturer', 'web_app_label' => 'Manufacturer', 'description' => "Manufacturer's name", 'requirement_type' => 'optional'],
            ['field_key' => 'name', 'web_app_label' => 'Item Name', 'description' => "Item's name, title or short description", 'requirement_type' => 'required'],
            ['field_key' => 'description', 'web_app_label' => 'Description', 'description' => 'Long description', 'requirement_type' => 'required'],
            ['field_key' => 'search_terms', 'web_app_label' => 'Search Terms', 'description' => 'Keywords, comma separated', 'requirement_type' => 'optional', 'is_multi_value' => true, 'join_separator' => ','],
            ['field_key' => 'categorization_or_hierarchy', 'web_app_label' => 'Categorization / Hierarchy', 'description' => 'Up to 3 levels is preferred', 'notes' => 'Example: Cleaning/Paper Products/Toilet paper', 'requirement_type' => 'required'],
            ['field_key' => 'brand_name', 'web_app_label' => 'Brand Name', 'description' => 'Brand name', 'notes' => 'Example: 3M', 'requirement_type' => 'optional'],
            ['field_key' => 'brand_logo', 'web_app_label' => 'Brand Logo', 'description' => 'Image file of the brand logo', 'requirement_type' => 'optional'],
            ['field_key' => 'list_price', 'web_app_label' => 'List Price', 'description' => 'Manufacturer Suggested Retail Price (MSRP)', 'requirement_type' => 'required', 'field_type' => 'decimal'],
            ['field_key' => 'selling_price_per_unit', 'web_app_label' => 'Selling Price per Unit', 'description' => 'The price/unit that the buyer will pay', 'requirement_type' => 'required', 'field_type' => 'decimal'],
            ['field_key' => 'unit_of_measure', 'web_app_label' => 'Unit of Measure', 'description' => 'Unit of Measure that the item is sold for at the stated selling price per unit (ISO standard preferred)', 'notes' => 'Example: EA for Each, BX for Box', 'requirement_type' => 'required'],
            ['field_key' => 'specifications', 'web_app_label' => 'Specifications', 'description' => 'Product attributes such as dimensions, color, weight, materials, and other product-specific properties, formatted as "attribute name=attribute value"', 'notes' => 'Example: Color=Red, Materials=Aluminum, Length=25 feet', 'requirement_type' => 'optional', 'is_multi_value' => true, 'join_separator' => ','],
            ['field_key' => 'unspsc_code', 'web_app_label' => 'UNSPSC Code', 'description' => 'Commodity code (8-digit code)', 'requirement_type' => 'required', 'field_type' => 'number'],
            ['field_key' => 'classifications', 'web_app_label' => 'Classifications', 'description' => 'Product classifications such as Recycle indicator, MWBE, Green indicator, Non-returnable, Hazardous, etc.', 'notes' => 'Example: EPP, Recyclable, SDB', 'requirement_type' => 'optional', 'is_multi_value' => true, 'join_separator' => ','],
            ['field_key' => 'product_type_or_family', 'web_app_label' => 'Product Type / Family', 'description' => 'Type of product', 'requirement_type' => 'optional'],
            ['field_key' => 'item_weight', 'web_app_label' => 'Item Weight', 'description' => 'Weight of the product, in pounds', 'requirement_type' => 'required', 'field_type' => 'decimal'],
            ['field_key' => 'selling_points', 'web_app_label' => 'Selling Points', 'description' => 'Special characteristics of the item, separated by a comma', 'requirement_type' => 'optional', 'is_multi_value' => true, 'join_separator' => ','],
            ['field_key' => 'quantity_per_unit', 'web_app_label' => 'Quantity per Unit', 'description' => 'Number of items within the stated unit of measure', 'notes' => 'Example: if the UOM is a PK (pack), the quantity per PK may be 4, i.e. a 4/PK', 'requirement_type' => 'optional', 'field_type' => 'number'],
            ['field_key' => 'min_qty_per_order', 'web_app_label' => 'Minimum Qty per Order', 'description' => 'Minimum quantity limit per order, if any', 'requirement_type' => 'optional', 'field_type' => 'number'],
            ['field_key' => 'multiples', 'web_app_label' => 'Order Multiples', 'description' => 'If the item must be ordered in multiples of x', 'requirement_type' => 'optional', 'field_type' => 'number'],
            ['field_key' => 'max_qty_per_order', 'web_app_label' => 'Maximum Qty per Order', 'description' => 'Maximum quantity limit per order, if any', 'requirement_type' => 'optional', 'field_type' => 'number'],
            ['field_key' => 'msds_link', 'web_app_label' => 'MSDS Link', 'description' => 'Link where the Material Safety Data Sheet (MSDS) is hosted', 'requirement_type' => 'optional'],
        ];

        foreach ($fields as $index => $field) {
            CatalogField::updateOrCreate(
                ['field_key' => $field['field_key']],
                array_merge([
                    'field_type' => 'text',
                    'is_system_derived' => false,
                    'system_source' => null,
                    'is_multi_value' => false,
                    'join_separator' => null,
                    'active' => true,
                    'visible_in_web_app' => true,
                    'sort_order' => ($index + 1) * 10,
                ], $field)
            );
        }
    }
}
