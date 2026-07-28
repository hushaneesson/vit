<?php

namespace App\Models;

use Illuminate\Support\Collection;

/**
 * Static, non-system-derived VIT field definitions.
 *
 * This class provides the list of VIT fields that should appear in the
 * web app and Excel exports. It intentionally does NOT query the database
 * catalog_fields table — the field set is defined statically here, matching
 * the FIELD_TO_COLUMN map used by CatalogExportService and
 * ProcessValidatedRowsJob.
 *
 * Each definition has:
 *   - field_key:     the VIT field key (matches FIELD_TO_COLUMN keys)
 *   - web_app_label: the human-readable column header shown in exports
 */
class VitFieldDefinition
{
    /**
     * The static list of VIT field definitions visible in the web app.
     *
     * Order matters — this determines column order in the generated Excel.
     * System-derived fields (seller, categorization_or_hierarchy, etc.)
     * are included here so their columns appear in the export; their
     * values are resolved separately in CatalogExportService::resolveValue().
     */
    private const VISIBLE_FIELDS = [
        ['field_key' => 'seller',                       'web_app_label' => 'Seller'],
        ['field_key' => 'seller_sku',                   'web_app_label' => 'Seller SKU'],
        ['field_key' => 'manufacturer_sku',             'web_app_label' => 'Manufacturer SKU'],
        ['field_key' => 'manufacturer',                 'web_app_label' => 'Manufacturer'],
        ['field_key' => 'brand_name',                   'web_app_label' => 'Brand Name'],
        ['field_key' => 'name',                         'web_app_label' => 'Product Name'],
        ['field_key' => 'description',                  'web_app_label' => 'Product Description'],
        ['field_key' => 'product_type_or_family',       'web_app_label' => 'Product Type / Family'],
        ['field_key' => 'unit_of_measure',              'web_app_label' => 'Unit of Measure'],
        ['field_key' => 'quantity_per_unit',            'web_app_label' => 'Quantity per Unit'],
        ['field_key' => 'unspsc_code',                  'web_app_label' => 'UNSPSC Code'],
        ['field_key' => 'list_price',                   'web_app_label' => 'List Price'],
        ['field_key' => 'selling_price_per_unit',       'web_app_label' => 'Selling Price per Unit'],
        ['field_key' => 'item_weight',                  'web_app_label' => 'Item Weight'],
        ['field_key' => 'min_qty_per_order',            'web_app_label' => 'Minimum Qty per Order'],
        ['field_key' => 'max_qty_per_order',            'web_app_label' => 'Maximum Qty per Order'],
        ['field_key' => 'multiples',                    'web_app_label' => 'Multiples'],
        ['field_key' => 'search_terms',                 'web_app_label' => 'Search Terms'],
        ['field_key' => 'classifications',              'web_app_label' => 'Classifications'],
        ['field_key' => 'specifications',               'web_app_label' => 'Specifications'],
        ['field_key' => 'selling_points',               'web_app_label' => 'Selling Points'],
        ['field_key' => 'msds_link',                    'web_app_label' => 'MSDS Link'],
        ['field_key' => 'image_file_name',              'web_app_label' => 'Image File Name'],
        ['field_key' => 'brand_logo',                   'web_app_label' => 'Brand Logo'],
        ['field_key' => 'categorization_or_hierarchy',  'web_app_label' => 'Categorization / Hierarchy'],
    ];

    /**
     * Get all VIT field definitions visible in the web app.
     *
     * @return Collection<int, object{field_key: string, web_app_label: string}>
     */
    public static function visibleInWebApp(): Collection
    {
        return collect(self::VISIBLE_FIELDS)
            ->map(fn(array $field) => (object) $field);
    }
}
