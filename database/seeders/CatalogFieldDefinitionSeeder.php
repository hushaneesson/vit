<?php

namespace Database\Seeders;

use App\Models\CatalogFieldDefinition;
use Illuminate\Database\Seeder;

/**
 * Seeds `catalog_field_definitions` with VIT's actual field spec (Phase 3B).
 * This is the single source of truth read by the Phase 4 entry form, the
 * Phase 7 Excel generator, and the Phase 8 mapping tool. Do not hardcode
 * these fields anywhere else in the app.
 */
class CatalogFieldDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $sort = 0;

        foreach ($this->definitions() as $def) {
            $def['sort_order'] = $sort++;
            $def['active'] = true;

            CatalogFieldDefinition::updateOrCreate(
                ['field_key' => $def['field_key']],
                $def
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function definitions(): array
    {
        return [
            // Vendor's Name — auto-derived from the vendors row linked to the
            // logged-in client's vendor_id (admin-set, registered/activated
            // name). NOT an editable form field for the vendor.
            [
                'field_key' => 'vendor_name',
                'web_app_label' => "Vendor's Name",
                'vit_column_header' => 'vendor',
                'description' => "Automatically pulled from your company's registered vendor name on file with VIT. Not editable here.",
                'requirement_type' => 'required',
                'field_type' => 'text',
                'max_length' => 255,
                'visible_in_web_app' => false,
            ],

            [
                'field_key' => 'catalog_name',
                'web_app_label' => 'Vendor Catalog Name',
                'vit_column_header' => 'catalog',
                'description' => 'Must be prefixed with your vendor name, e.g. "XYZ Company-General Catalog".',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'max_length' => 255,
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'vendor_part_number',
                'web_app_label' => 'Vendor Part Number',
                'vit_column_header' => 'dealer sku',
                'description' => 'Must be unique per product. Also auto-populates the Customer SKU, Search SKU, and Vendor SKU columns.',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'max_length' => 255,
                'visible_in_web_app' => true,
            ],

            // Hidden columns that silently reuse the Vendor Part Number.
            [
                'field_key' => 'customer_sku',
                'web_app_label' => 'Customer SKU (hidden)',
                'vit_column_header' => 'customer sku',
                'description' => 'Always mirrors Vendor Part Number. Never shown to the vendor.',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'max_length' => 255,
                'visible_in_web_app' => false,
            ],
            [
                'field_key' => 'search_sku',
                'web_app_label' => 'Search SKU (hidden)',
                'vit_column_header' => 'search sku',
                'description' => 'Always mirrors Vendor Part Number. Never shown to the vendor.',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'max_length' => 255,
                'visible_in_web_app' => false,
            ],
            [
                'field_key' => 'vendor_sku',
                'web_app_label' => 'Vendor SKU (hidden)',
                'vit_column_header' => 'vendor sku',
                'description' => 'Always mirrors Vendor Part Number. Never shown to the vendor.',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'max_length' => 255,
                'visible_in_web_app' => false,
            ],

            [
                'field_key' => 'manufacturer_part_number',
                'web_app_label' => "Manufacturer's Part Number",
                'vit_column_header' => 'manufacturer sku',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'max_length' => 255,
                'visible_in_web_app' => true,
            ],

            // Always defaults to "ELINK" — never shown to the vendor.
            [
                'field_key' => 'type',
                'web_app_label' => 'Type (hidden)',
                'vit_column_header' => 'type',
                'description' => 'Always defaults to ELINK.',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'max_length' => 50,
                'visible_in_web_app' => false,
            ],

            // Category Level 1/2/3 — three vendor-facing dropdowns that
            // together build the Hierarchy Path (joined with "!"). The
            // hierarchy_number lookup below is the actual value written to
            // the "hierarchy" VIT column.
            [
                'field_key' => 'category_level_1',
                'web_app_label' => 'Category Level 1',
                'vit_column_header' => '(feeds hierarchy path)',
                'description' => 'First level of the product hierarchy.',
                'requirement_type' => 'required',
                'field_type' => 'dropdown',
                'options_source' => 'product_hierarchy_level_1',
                'visible_in_web_app' => true,
            ],
            [
                'field_key' => 'category_level_2',
                'web_app_label' => 'Category Level 2',
                'vit_column_header' => '(feeds hierarchy path)',
                'description' => 'Second level of the product hierarchy.',
                'requirement_type' => 'required',
                'field_type' => 'dropdown',
                'options_source' => 'product_hierarchy_level_2',
                'visible_in_web_app' => true,
            ],
            [
                'field_key' => 'category_level_3',
                'web_app_label' => 'Category Level 3',
                'vit_column_header' => '(feeds hierarchy path)',
                'description' => 'Third level of the product hierarchy.',
                'requirement_type' => 'required',
                'field_type' => 'dropdown',
                'options_source' => 'product_hierarchy_level_3',
                'visible_in_web_app' => true,
            ],
            [
                'field_key' => 'hierarchy_number',
                'web_app_label' => 'Hierarchy Number (computed)',
                'vit_column_header' => 'hierarchy',
                'description' => 'Looked up from Category Level 1!2!3 path. Emails admin if the path is not yet in the reference table.',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'join_separator' => '!',
                'visible_in_web_app' => false,
                'triggers_email_alert' => true,
            ],

            [
                'field_key' => 'commodity_type',
                'web_app_label' => 'Product Commodity Type',
                'vit_column_header' => 'category',
                'description' => 'Choosing "Use the name of Category Level 2" auto-fills this with your Category Level 2 selection.',
                'requirement_type' => 'required',
                'field_type' => 'dropdown',
                'options_source' => 'commodity_type',
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'short_description',
                'web_app_label' => 'Short Description',
                'vit_column_header' => 'name',
                'description' => 'On submit, the quantity-in-UOM and 2-digit UOM code are appended automatically.',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'max_length' => 255,
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'long_description',
                'web_app_label' => 'Long Description',
                'vit_column_header' => 'description',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'max_length' => 4000,
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'unit_of_measure',
                'web_app_label' => 'Unit of Measure',
                'vit_column_header' => 'unit of measure',
                'requirement_type' => 'required',
                'field_type' => 'dropdown',
                'options_source' => 'unit_of_measure',
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'product_images',
                'web_app_label' => 'Product Images',
                'vit_column_header' => 'product_images',
                'description' => 'Upload actual image files (Primary, #2, #3...). Embedded directly into the generated Excel file at 400x400.',
                'requirement_type' => 'required',
                'field_type' => 'image-upload',
                'is_multi_value' => true,
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'manufacturer_name',
                'web_app_label' => "Manufacturer's Name",
                'vit_column_header' => 'manufacturer',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'max_length' => 255,
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'brand_name',
                'web_app_label' => 'Brand Name',
                'vit_column_header' => 'brandName',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'max_length' => 255,
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'search_terms',
                'web_app_label' => 'Search Terms/Keywords',
                'vit_column_header' => 'search terms',
                'description' => 'Comma-separated.',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'max_length' => 2000,
                'is_multi_value' => true,
                'join_separator' => ',',
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'list_price',
                'web_app_label' => 'List Price/MSRP',
                'vit_column_header' => 'list price',
                'description' => 'If blank, Selling Price is used.',
                'requirement_type' => 'required',
                'field_type' => 'decimal',
                'decimal_places' => 2,
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'selling_price',
                'web_app_label' => 'Selling Price per Unit',
                'vit_column_header' => 'APDcost',
                'description' => 'Cannot exceed List Price.',
                'requirement_type' => 'required',
                'field_type' => 'decimal',
                'decimal_places' => 2,
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'item_weight',
                'web_app_label' => 'Item Weight (lbs)',
                'vit_column_header' => 'item weight',
                'requirement_type' => 'required',
                'field_type' => 'decimal',
                'decimal_places' => 2,
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'selling_points',
                'web_app_label' => 'Selling Points',
                'vit_column_header' => 'selling points',
                'requirement_type' => 'required',
                'field_type' => 'multi-value-list',
                'is_multi_value' => true,
                'join_separator' => '|',
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'specifications',
                'web_app_label' => 'Product Attributes / Specifications',
                'vit_column_header' => 'specifications',
                'description' => 'Key=value pairs, e.g. Color=Red, Material=Aluminum.',
                'requirement_type' => 'required',
                'field_type' => 'key-value-pairs',
                'is_multi_value' => true,
                'join_separator' => '|',
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'quantity_in_uom',
                'web_app_label' => 'Quantity in Unit of Measure',
                'vit_column_header' => '(appended to name)',
                'description' => 'e.g. "10" + "Reams" — appended to the end of the Short Description, preceded by a comma.',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'green_information',
                'web_app_label' => 'Green Information',
                'vit_column_header' => '(specifications: Green_Information)',
                'description' => 'Required only if the Green_Indicator classification is selected. Stored in specifications as Green_Information=<value>.',
                'requirement_type' => 'conditional',
                'conditional_on_field' => 'classifications',
                'conditional_on_value' => 'Green_Indicator',
                'field_type' => 'text',
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'classifications',
                'web_app_label' => 'Product Classifications',
                'vit_column_header' => 'classifications',
                'requirement_type' => 'optional',
                'field_type' => 'multi-value-list',
                'is_multi_value' => true,
                'join_separator' => '|',
                'triggers_email_alert' => true,
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'unspsc_code',
                'web_app_label' => 'UNSPSC Code',
                'vit_column_header' => '(classifications: UNSPSC)',
                'description' => 'Always required, even if not explicitly selected. Format: UNSPSC=<value>.',
                'requirement_type' => 'required',
                'field_type' => 'text',
                'max_length' => 255,
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'upc',
                'web_app_label' => 'UPC',
                'vit_column_header' => '(classifications: UPC_RTL)',
                'description' => 'Only if UPC classification selected. Format: UPC_RTL=<value>.',
                'requirement_type' => 'conditional',
                'conditional_on_field' => 'classifications',
                'conditional_on_value' => 'UPC_RTL',
                'field_type' => 'text',
                'max_length' => 255,
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'gtin',
                'web_app_label' => 'GTIN',
                'vit_column_header' => '(classifications: GTIN)',
                'description' => 'Format: GTIN=<value>.',
                'requirement_type' => 'conditional',
                'conditional_on_field' => 'classifications',
                'conditional_on_value' => 'GTIN',
                'field_type' => 'text',
                'max_length' => 255,
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'country_of_origin',
                'web_app_label' => 'Country of Origin',
                'vit_column_header' => '(classifications: Country of Origin)',
                'description' => 'Always required, even if not explicitly selected. Format: Country of Origin=<value>.',
                'requirement_type' => 'required',
                'field_type' => 'dropdown',
                'options_source' => 'country_code',
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'msds_link',
                'web_app_label' => 'MSDS Link',
                'vit_column_header' => '(classifications: MSDS URL)',
                'description' => 'Only if Hazmat is selected. Format: MSDS URL=<value>.',
                'requirement_type' => 'conditional',
                'conditional_on_field' => 'classifications',
                'conditional_on_value' => 'Hazmat',
                'field_type' => 'text',
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'national_stock_number',
                'web_app_label' => 'National Stock Number',
                'vit_column_header' => '(classifications: National Stock Number)',
                'description' => 'Format: National Stock Number=<value>.',
                'requirement_type' => 'conditional',
                'conditional_on_field' => 'classifications',
                'conditional_on_value' => 'National Stock Number',
                'field_type' => 'text',
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'nigp_code',
                'web_app_label' => 'NIGP Code',
                'vit_column_header' => '(classifications: NIGP Code)',
                'description' => 'Format: NIGP Code=<value>. 5 or 7 digits.',
                'requirement_type' => 'conditional',
                'conditional_on_field' => 'classifications',
                'conditional_on_value' => 'NIGP Code',
                'field_type' => 'text',
                'max_length' => 7,
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'warranty_indicator',
                'web_app_label' => 'Warranty Indicator',
                'vit_column_header' => '(classifications: Warranty Information)',
                'description' => 'Only if Warranty is selected. Format: Warranty Information=<value>.',
                'requirement_type' => 'conditional',
                'conditional_on_field' => 'classifications',
                'conditional_on_value' => 'Warranty',
                'field_type' => 'text',
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'minimum_order_qty',
                'web_app_label' => 'Minimum Order Qty',
                'vit_column_header' => 'minimum',
                'requirement_type' => 'optional',
                'field_type' => 'number',
                'max_length' => 11,
                'visible_in_web_app' => true,
            ],
            [
                'field_key' => 'multiple_order_qty',
                'web_app_label' => 'Multiple Order Qty',
                'vit_column_header' => 'multiples',
                'requirement_type' => 'optional',
                'field_type' => 'number',
                'max_length' => 11,
                'visible_in_web_app' => true,
            ],
            [
                'field_key' => 'maximum_order_qty',
                'web_app_label' => 'Maximum Order Qty',
                'vit_column_header' => 'maximum',
                'requirement_type' => 'optional',
                'field_type' => 'number',
                'max_length' => 11,
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'discontinued',
                'web_app_label' => 'Discontinue Item',
                'vit_column_header' => 'discontinued',
                'description' => 'TRUE/FALSE.',
                'requirement_type' => 'optional',
                'field_type' => 'boolean',
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'discontinued_date',
                'web_app_label' => 'Discontinued Date',
                'vit_column_header' => 'discontinuedDate',
                'description' => 'Format: YYYY-MM-DD.',
                'requirement_type' => 'conditional',
                'conditional_on_field' => 'discontinued',
                'conditional_on_value' => 'TRUE',
                'field_type' => 'date',
                'visible_in_web_app' => true,
            ],

            [
                'field_key' => 'replacement_part_number',
                'web_app_label' => 'Replacement Part Number',
                'vit_column_header' => 'replacement Sku',
                'description' => 'Comma-separated if multiple. New replacement products must be added as their own catalog entry first, then linked here.',
                'requirement_type' => 'conditional',
                'conditional_on_field' => 'discontinued',
                'conditional_on_value' => 'TRUE',
                'field_type' => 'text',
                'is_multi_value' => true,
                'join_separator' => ',',
                'visible_in_web_app' => true,
            ],
        ];
    }
}
