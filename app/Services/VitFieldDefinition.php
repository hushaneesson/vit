<?php

namespace App\Services;

/**
 * Static definition of all VIT eLink fields.
 *
 * These are application constants — part of the VIT specification — and
 * are NOT editable by administrators. The mapping screen, row validator,
 * and processing pipeline all use this class as their single source of
 * truth for field metadata.
 *
 * Each field definition contains all metadata needed by the UI and
 * processing pipeline:
 *
 *   field_key          – unique string identifier
 *   web_app_label      – human-readable label shown in the UI
 *   description        – help text / tooltip
 *   requirement_type   – 'required' | 'optional' | 'conditional'
 *   field_type         – 'text' | 'number' | 'decimal' | 'boolean'
 *   is_system_derived  – true if value comes from vendor account, not file
 *   system_source      – Eloquent dot-notation for system-derived fields
 *   is_multi_value     – true if field accepts multiple values (array)
 *   join_separator     – separator used when splitting multi-value strings
 *   max_length         – max string length (null = no limit)
 *   conditional_on_field – field_key this field depends on
 *   conditional_on_value – value that triggers the requirement
 *   sort_order         – display order in the mapping UI
 */
class VitFieldDefinition
{
    /**
     * All VIT field definitions, ordered by sort_order.
     *
     * @var array<int, array<string, mixed>>
     */
    private const DEFINITIONS = [
        ['field_key' => 'seller', 'web_app_label' => 'Seller', 'description' => 'Name of seller', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => true, 'system_source' => 'vendor.name', 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 10],
        ['field_key' => 'seller_sku', 'web_app_label' => 'Seller SKU', 'description' => "Seller's part #", 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 20],
        ['field_key' => 'image_file_name', 'web_app_label' => 'Image File Name', 'description' => 'File name of image attachment or the URL address where the image is already hosted at. Image size 400x400 (.jpg file).', 'requirement_type' => 'optional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 30],
        ['field_key' => 'manufacturer_sku', 'web_app_label' => 'Manufacturer SKU', 'description' => "Manufacturer's part #", 'requirement_type' => 'optional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 40],
        ['field_key' => 'manufacturer', 'web_app_label' => 'Manufacturer', 'description' => "Manufacturer's name", 'requirement_type' => 'optional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 50],
        ['field_key' => 'name', 'web_app_label' => 'Item Name', 'description' => "Item's name, title or short description", 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 60],
        ['field_key' => 'description', 'web_app_label' => 'Description', 'description' => 'Long description', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 70],
        ['field_key' => 'search_terms', 'web_app_label' => 'Search Terms', 'description' => 'Keywords, comma separated', 'requirement_type' => 'optional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => true, 'join_separator' => ',', 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 80],
        ['field_key' => 'categorization_or_hierarchy', 'web_app_label' => 'Categorization / Hierarchy', 'description' => 'Up to 3-levels is preferred. Example: Cleaning/Paper Products/Toilet paper.', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 90],
        ['field_key' => 'brand_name', 'web_app_label' => 'Brand Name', 'description' => 'Brand name. Example: 3M.', 'requirement_type' => 'optional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 100],
        ['field_key' => 'brand_logo', 'web_app_label' => 'Brand Logo', 'description' => 'Image file of the brand logo.', 'requirement_type' => 'optional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 110],
        ['field_key' => 'list_price', 'web_app_label' => 'List Price', 'description' => 'Manufacturer Suggested Retail Price (MSRP)', 'requirement_type' => 'required', 'field_type' => 'decimal', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 120],
        ['field_key' => 'selling_price_per_unit', 'web_app_label' => 'Selling Price per Unit', 'description' => 'The price/unit that the buyer will pay.', 'requirement_type' => 'required', 'field_type' => 'decimal', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 130],
        ['field_key' => 'unit_of_measure', 'web_app_label' => 'Unit of Measure', 'description' => 'Unit of Measure that the item is sold for the stated selling price per unit (ISO standard preferred). Example: EA for Each, BX for Box.', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 140],
        ['field_key' => 'specifications', 'web_app_label' => 'Specifications', 'description' => 'Product attributes such as dimensions, color, weight, materials, and other product-specific properties. The format should be "attribute name=attribute value" separated by a comma. For example: Color=Red, Materials=Aluminum, Length=25 feet.', 'requirement_type' => 'optional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => true, 'join_separator' => ',', 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 150],
        ['field_key' => 'unspsc_code', 'web_app_label' => 'UNSPSC Code', 'description' => 'Commodity code (8-digit code)', 'requirement_type' => 'required', 'field_type' => 'number', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 160],
        ['field_key' => 'classifications', 'web_app_label' => 'Classifications', 'description' => 'Product classifications such as Recycle indicator, MWBE, Green indicator, Non-returnable, Hazardous, etc. Example: EPP, Recyclable, SDB.', 'requirement_type' => 'optional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => true, 'join_separator' => ',', 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 170],
        ['field_key' => 'product_type_or_family', 'web_app_label' => 'Product Type / Family', 'description' => 'Type of product. Example: Gel Pens, Boards, etc.', 'requirement_type' => 'optional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 180],
        ['field_key' => 'item_weight', 'web_app_label' => 'Item Weight', 'description' => 'Weight of the product, in pounds.', 'requirement_type' => 'required', 'field_type' => 'decimal', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 190],
        ['field_key' => 'selling_points', 'web_app_label' => 'Selling Points', 'description' => 'Special characteristics of the item, separated by a comma.', 'requirement_type' => 'optional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => true, 'join_separator' => ',', 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 200],
        ['field_key' => 'quantity_per_unit', 'web_app_label' => 'Quantity per Unit', 'description' => 'Number of items within the stated unit of measure. Example: If the UOM is a PK (pack), the quantity per PK may be 4. So this is a 4/PK.', 'requirement_type' => 'optional', 'field_type' => 'number', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 210],
        ['field_key' => 'min_qty_per_order', 'web_app_label' => 'Minimum Qty per Order', 'description' => 'Minimum quantity limit per order, if any.', 'requirement_type' => 'optional', 'field_type' => 'number', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 220],
        ['field_key' => 'multiples', 'web_app_label' => 'Order Multiples', 'description' => 'If the item must be ordered in multiples of x. Example: multiple = 2 means the quantity being ordered must be divisible by 2 or in multiples of 2.', 'requirement_type' => 'optional', 'field_type' => 'number', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 230],
        ['field_key' => 'max_qty_per_order', 'web_app_label' => 'Maximum Qty per Order', 'description' => 'Maximum quantity limit per order, if any.', 'requirement_type' => 'optional', 'field_type' => 'number', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 240],
        ['field_key' => 'msds_link', 'web_app_label' => 'MSDS Link', 'description' => 'Link where the Material Safety Data Sheet (MSDS) can be found.', 'requirement_type' => 'optional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'sort_order' => 250],
    ];

    /**
     * Return all VIT field definitions as a collection.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function all(): \Illuminate\Support\Collection
    {
        return collect(self::DEFINITIONS)->map(fn(array $def) => (object) $def);
    }

    /**
     * Return fields that are active and visible in the web app (mapping UI).
     * Excludes system-derived fields (e.g. "Seller") since those values come
     * from the vendor's account, never from a file column.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function visibleInWebApp(): \Illuminate\Support\Collection
    {
        return self::all()
            ->where('is_system_derived', false);
    }

    /**
     * Return fields that are active (not system-derived) for processing.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function active(): \Illuminate\Support\Collection
    {
        return self::all()
            ->where('is_system_derived', false);
    }

    /**
     * Find a single field definition by its field_key.
     *
     * @return object|null
     */
    public static function find(string $fieldKey): ?object
    {
        return self::all()->firstWhere('field_key', $fieldKey);
    }

    /**
     * Return only required field keys.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public static function requiredFieldKeys(): \Illuminate\Support\Collection
    {
        return self::all()
            ->where('requirement_type', 'required')
            ->where('is_system_derived', false)
            ->pluck('field_key');
    }

    /**
     * Return all field keys.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public static function allFieldKeys(): \Illuminate\Support\Collection
    {
        return self::all()->pluck('field_key');
    }
}
