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
 *   field_key            – unique string identifier
 *   web_app_label        – human-readable label shown in the UI
 *   description          – help text / tooltip
 *   requirement_type     – 'required' | 'optional' | 'conditional' | 'system_derived'
 *   field_type           – 'text' | 'number' | 'decimal' | 'boolean'
 *   is_system_derived    – true if value comes from vendor account, not file
 *   system_source        – Eloquent dot-notation for system-derived fields
 *   is_multi_value       – true if field accepts multiple values (array)
 *   join_separator       – separator used when splitting/joining multi-value strings
 *   max_length           – max string length (null = no limit)
 *   conditional_on_field – field_key this field depends on
 *   conditional_on_value – value that triggers the requirement
 *   shown_on_frontend    – whether this field is exposed to the vendor in the
 *                          web app's mapping UI. False for the handful of
 *                          fields the VIT spec marks "Do not expose in the
 *                          Web App" (these are system/derived values that
 *                          still need a row here for csv generation).
 *   vit_csv_column       – the exact column header text required by the VIT
 *                          spec for the generated Excel/CSV file. Null when
 *                          the field does not get its own output column,
 *                          either because it packs into another column
 *                          (see packs_into_field) or appends onto another
 *                          field's value (see append_to_field), or because
 *                          it isn't part of the VIT spec at all (category,
 *                          lead_time, availability). THIS is the header
 *                          value the export writer should use — not
 *                          web_app_label, which is UI-facing only and does
 *                          not always match the spec's required header text
 *                          (e.g. web_app_label "Brand Name" vs. required
 *                          header "brandName").
 *   model_attribute      – the Eloquent attribute on CatalogItem this value
 *                          should be read from. Null means "use field_key
 *                          as the attribute name" (the common case). Only
 *                          set when the model's column name differs from
 *                          field_key (e.g. image_urls reads from `images`).
 *   packs_into_field     – if set, this field's value does not get its own
 *                          output CSV column. Instead, at csv-generation
 *                          time it is serialized into the named field
 *                          (see packs_into_key for the sub-key/prefix used).
 *                          Per VIT spec: UNSPSC, MSDS link, and Country of
 *                          Origin are collected as distinct fields in the
 *                          mapping UI (so they can be validated/required on
 *                          their own) but are written into the
 *                          "classifications" CSV column as
 *                          "KEY=value" pairs, not as their own columns.
 *   packs_into_key       – the "name=" prefix used when packing this value
 *                          into the target field (e.g. "UNSPSC", "MSDS URL",
 *                          "Country of Origin").
 *   append_to_field      – if set, this field's value is not written as its
 *                          own column either; it's appended to the end of
 *                          the named field's value (used for
 *                          quantity_per_unit, which VIT wants concatenated
 *                          onto the short description, e.g.
 *                          "..., 10 Reams/CS").
 *   sort_order           – display order in the mapping UI
 */
class VitFieldDefinition
{
    /**
     * All VIT field definitions, ordered by sort_order.
     *
     * @var array<int, array<string, mixed>>
     */
    private const DEFINITIONS = [
        ['field_key' => 'vendor_name', 'web_app_label' => 'Vendor', 'description' => 'Vendor name', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => true, 'system_source' => 'vendor.name', 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => false, 'vit_csv_column' => 'vendor', 'model_attribute' => null, 'sort_order' => 10],

        ['field_key' => 'catalog_name', 'web_app_label' => 'Catalog', 'description' => 'Catalog name', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => false, 'vit_csv_column' => 'catalog', 'model_attribute' => null, 'sort_order' => 15],

        ['field_key' => 'dealer_sku', 'web_app_label' => 'Seller SKU', 'description' => 'Your unique SKU for this item', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'dealer sku', 'model_attribute' => null, 'sort_order' => 20],

        // not to be exposed in the mapping but used to generate the excel file
        // customer sku - system derived from dealer sku
        ['field_key' => 'customer_sku', 'web_app_label' => 'Customer SKU', 'description' => 'Customer SKU derived from the Seller SKU', 'requirement_type' => 'system_derived', 'field_type' => 'text', 'is_system_derived' => true, 'system_source' => 'dealer_sku', 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => false, 'vit_csv_column' => 'customer sku', 'model_attribute' => null, 'sort_order' => 21],

        // vendor sku - system derived from dealer sku
        ['field_key' => 'vendor_sku', 'web_app_label' => 'Vendor SKU', 'description' => 'Vendor SKU derived from the Seller SKU', 'requirement_type' => 'system_derived', 'field_type' => 'text', 'is_system_derived' => true, 'system_source' => 'dealer_sku', 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => false, 'vit_csv_column' => 'vendor sku', 'model_attribute' => null, 'sort_order' => 22],

        // search sku - system derived from dealer sku
        ['field_key' => 'search_sku', 'web_app_label' => 'Search SKU', 'description' => 'Search SKU derived from the Seller SKU', 'requirement_type' => 'system_derived', 'field_type' => 'text', 'is_system_derived' => true, 'system_source' => 'dealer_sku', 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => false, 'vit_csv_column' => 'search sku', 'model_attribute' => null, 'sort_order' => 23],

        // FIXED: spec marks Manufacturer's Part Number as required, was 'optional'
        ['field_key' => 'manufacturer_sku', 'web_app_label' => 'Manufacturer SKU', 'description' => "Manufacturer's part number", 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'manufacturer sku', 'model_attribute' => null, 'sort_order' => 40],

        // not to be exposed in the mapping but used to generate the excel file
        ['field_key' => 'type', 'web_app_label' => 'Type', 'description' => 'eLink', 'requirement_type' => 'system_derived', 'field_type' => 'text', 'is_system_derived' => true, 'system_source' => 'elink', 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => false, 'vit_csv_column' => 'type', 'model_attribute' => null, 'sort_order' => 24],

        // NOTE: csv column for this per spec is "category" — product_commodity_type is the
        // field_key used internally; make sure the csv writer maps this key to the "category" header.
        ['field_key' => 'product_commodity_type', 'web_app_label' => 'Product Commodity Type', 'description' => 'Product commodity type', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'category', 'model_attribute' => null, 'sort_order' => 25],

        // FIXED: spec marks Manufacturer's Name as required, was 'optional'
        ['field_key' => 'manufacturer', 'web_app_label' => 'Manufacturer', 'description' => "Manufacturer's name", 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'manufacturer', 'model_attribute' => null, 'sort_order' => 50],

        ['field_key' => 'short_description', 'web_app_label' => 'Item Name', 'description' => 'Item name or short description', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'name', 'model_attribute' => 'name', 'sort_order' => 60],

        ['field_key' => 'long_description', 'web_app_label' => 'Description', 'description' => 'Full product description', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 4000, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'description', 'model_attribute' => 'description', 'sort_order' => 70],

        // FIXED: spec marks Search Terms as required, was 'optional'
        ['field_key' => 'search_terms', 'web_app_label' => 'Search Terms', 'description' => 'Keywords for finding the item', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => true, 'join_separator' => ',', 'max_length' => 2000, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'search terms', 'model_attribute' => null, 'sort_order' => 80, 'is_key_value' => false],

        ['field_key' => 'hierarchy', 'web_app_label' => 'Hierarchy', 'description' => 'Product category path, up to 3 levels. Example: Cleaning/Paper Products/Toilet Paper', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => false, 'vit_csv_column' => 'hierarchy', 'model_attribute' => null, 'sort_order' => 90],

        // NOTE: csv column for this per spec is "brandName" — check csv writer mapping.
        ['field_key' => 'brand_name', 'web_app_label' => 'Brand Name', 'description' => 'Brand name. Example: 3M', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'brandName', 'model_attribute' => null, 'sort_order' => 100],

        ['field_key' => 'list_price', 'web_app_label' => 'List Price', 'description' => "Manufacturer's suggested retail price (MSRP)", 'requirement_type' => 'required', 'field_type' => 'decimal', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'list price', 'model_attribute' => null, 'sort_order' => 120],

        // NOTE: csv column for this per spec is "APDcost" — check csv writer mapping.
        ['field_key' => 'selling_price', 'web_app_label' => 'Selling Price per Unit', 'description' => 'Price the buyer pays per unit', 'requirement_type' => 'required', 'field_type' => 'decimal', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'APDcost', 'model_attribute' => null, 'sort_order' => 130],

        ['field_key' => 'unit_of_measure', 'web_app_label' => 'Unit of Measure', 'description' => 'Unit the item is sold in. Example: EA (Each), BX (Box)', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'unit of measure', 'model_attribute' => null, 'sort_order' => 140],

        // FIXED: spec marks Product Attribute (specifications) as required, was 'optional'
        ['field_key' => 'specifications', 'web_app_label' => 'Specifications', 'description' => 'Product details in name=value format. Example: Color=Red, Material=Aluminum', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => true, 'join_separator' => '|', 'max_length' => 4000, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'specifications', 'model_attribute' => null, 'sort_order' => 150, 'is_key_value' => true],

        // STRUCTURAL: per spec, UNSPSC does not get its own csv column — it's always-required
        // but delivered as "UNSPSC=value" packed into the "classifications" column.
        // Kept as its own field here so the mapping UI can validate/require it independently.
        ['field_key' => 'unspsc_code', 'web_app_label' => 'UNSPSC Code', 'description' => '8-digit UNSPSC commodity code', 'requirement_type' => 'required', 'field_type' => 'number', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => 'classifications', 'packs_into_key' => 'UNSPSC', 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => null, 'model_attribute' => null, 'sort_order' => 160],

        ['field_key' => 'classifications', 'web_app_label' => 'Classifications', 'description' => 'Product classifications such as Recyclable, Green, or Hazardous', 'requirement_type' => 'optional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => true, 'join_separator' => '|', 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'classifications', 'model_attribute' => null, 'sort_order' => 170, 'is_key_value' => false],

        // STRUCTURAL: per spec, Country of Origin is always-required and packed into
        // "classifications" as "Country of Origin=XX". This was missing entirely before.
        ['field_key' => 'country_of_origin', 'web_app_label' => 'Country of Origin', 'description' => '2-letter country code for the country of origin', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => 'classifications', 'packs_into_key' => 'Country of Origin', 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => null, 'model_attribute' => null, 'sort_order' => 175],

        ['field_key' => 'category', 'web_app_label' => 'Product Type / Family', 'description' => 'Product type or family. Example: Gel Pens, Boards', 'requirement_type' => 'optional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => null, 'model_attribute' => null, 'sort_order' => 180],
        // ^ NOT in the VIT spec sheet. Kept as-is pending confirmation — doesn't map to any
        // spec row (the spec's "category" csv column belongs to product_commodity_type above).
        // Flagging for review; may be legacy from before the spec was finalized.

        ['field_key' => 'item_weight_in_pounds', 'web_app_label' => 'Item Weight in Pounds', 'description' => 'Product weight in pounds', 'requirement_type' => 'required', 'field_type' => 'decimal', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'item weight', 'model_attribute' => null, 'sort_order' => 190],

        // FIXED: spec marks Selling Point as required, was 'optional'
        ['field_key' => 'selling_points', 'web_app_label' => 'Selling Points', 'description' => 'Key product features or benefits', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => true, 'join_separator' => '|', 'max_length' => 4000, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'selling points', 'model_attribute' => null, 'sort_order' => 200, 'is_key_value' => false],

        // FIXED: spec marks Quantity contained in Unit of Measure as required, was 'optional'.
        // STRUCTURAL: per spec this is NOT its own csv column — it's appended to the end of
        // "name" (shortdescription), e.g. "..., 10 Reams/CS". Kept as its own field for
        // vendor entry/validation; append_to_field tells the pipeline where it lands.
        ['field_key' => 'quantity_per_unit', 'web_app_label' => 'Quantity per Unit', 'description' => 'Number of items in each unit. Example: 4 per pack', 'requirement_type' => 'required', 'field_type' => 'number', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => 'short_description', 'shown_on_frontend' => true, 'vit_csv_column' => null, 'model_attribute' => null, 'sort_order' => 210],

        // NEW: was missing entirely. Spec requires the vendor enter this alongside
        // quantity_per_unit — distinct from unit_of_measure's abbreviation (e.g. "CS").
        // Combines with quantity_per_unit + unit_of_measure to build the string appended
        // onto shortdescription, e.g. "10" + "Reams" + "/" + "CS" -> "10 Reams/CS".
        // Consumed by the quantity_per_unit append logic — doesn't get its own csv column.
        ['field_key' => 'unit_word', 'web_app_label' => 'Quantity Unit Type', 'description' => 'Name of the unit being counted. Example: Reams, Sheets, Each', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => null, 'model_attribute' => null, 'sort_order' => 211],

        ['field_key' => 'min_qty_per_order', 'web_app_label' => 'Minimum Qty per Order', 'description' => 'Minimum quantity allowed per order', 'requirement_type' => 'optional', 'field_type' => 'number', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 11, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'minimum', 'model_attribute' => null, 'sort_order' => 220],

        ['field_key' => 'multiples', 'web_app_label' => 'Order Multiples', 'description' => 'Order quantity must be a multiple of this number. Example: 2', 'requirement_type' => 'optional', 'field_type' => 'number', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 11, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'multiples', 'model_attribute' => null, 'sort_order' => 230],

        ['field_key' => 'max_qty_per_order', 'web_app_label' => 'Maximum Qty per Order', 'description' => 'Maximum quantity allowed per order', 'requirement_type' => 'optional', 'field_type' => 'number', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 11, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'maximum', 'model_attribute' => null, 'sort_order' => 240],

        // STRUCTURAL: per spec, MSDS link is only required if the vendor selects Hazmat, and
        // is delivered as "MSDS URL=value" packed into "classifications", not its own column.
        ['field_key' => 'msds_link', 'web_app_label' => 'MSDS Link', 'description' => 'Link to the product Material Safety Data Sheet (MSDS)', 'requirement_type' => 'conditional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => 'classifications', 'conditional_on_value' => 'Hazmat', 'packs_into_field' => 'classifications', 'packs_into_key' => 'MSDS URL', 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => null, 'model_attribute' => null, 'sort_order' => 250],

        // NEW: was missing entirely. Required. Multi-value, pipe-separated. Vendor can either
        // supply hosted image/video URLs or filenames to be hosted in the marketplace — the
        // web app is responsible for turning filenames into the required URL format before
        // they land here.
        ['field_key' => 'image_urls', 'web_app_label' => 'Image / Video URLs', 'description' => 'Image or video URLs, starting with the primary image', 'requirement_type' => 'required', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => true, 'join_separator' => '|', 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'image URLs', 'model_attribute' => 'images', 'sort_order' => 255, 'is_key_value' => false],

        // NEW: was missing entirely. "As applicable" per spec — treated as optional.
        ['field_key' => 'discontinued', 'web_app_label' => 'Item Discontinued', 'description' => 'Enter TRUE if the item is discontinued, otherwise FALSE', 'requirement_type' => 'optional', 'field_type' => 'boolean', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'discontinued', 'model_attribute' => 'is_discontinued', 'sort_order' => 260],

        // NEW: was missing entirely. Conditional on discontinued = TRUE.
        ['field_key' => 'discontinued_date', 'web_app_label' => 'Discontinued Date', 'description' => 'Date the item was discontinued. Format: YYYY-MM-DD', 'requirement_type' => 'conditional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => 'discontinued', 'conditional_on_value' => 'TRUE', 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'discontinuedDate', 'model_attribute' => 'discontinue_date', 'sort_order' => 270],

        // NEW: was missing entirely. Single SKU value, "as applicable".
        ['field_key' => 'replacement_sku', 'web_app_label' => 'Replacement Part Number', 'description' => 'SKU of the replacement product, if available', 'requirement_type' => 'optional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => 'discontinued', 'conditional_on_value' => 'TRUE', 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => false, 'vit_csv_column' => 'replacement Sku', 'model_attribute' => null, 'sort_order' => 280],

        ['field_key' => 'lead_time', 'web_app_label' => 'Shipping Lead Time', 'description' => 'Number of days needed to fulfill and ship the order', 'requirement_type' => 'optional', 'field_type' => 'number', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => null, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'lead_time', 'model_attribute' => 'lead_time', 'sort_order' => 290],

        ['field_key' => 'availability', 'web_app_label' => 'Availability', 'description' => 'Current stock status, such as In Stock or Out of Stock', 'requirement_type' => 'optional', 'field_type' => 'text', 'is_system_derived' => false, 'system_source' => null, 'is_multi_value' => false, 'join_separator' => null, 'max_length' => 255, 'conditional_on_field' => null, 'conditional_on_value' => null, 'packs_into_field' => null, 'packs_into_key' => null, 'append_to_field' => null, 'shown_on_frontend' => true, 'vit_csv_column' => 'availability', 'model_attribute' => 'availability', 'sort_order' => 300],
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
     * Return fields flagged as shown_on_frontend, i.e. actually rendered in
     * the vendor-facing mapping UI. This is distinct from is_system_derived:
     * a field can be non-system-derived and still hidden, though today the
     * two happen to line up (customer_sku, vendor_sku, search_sku, type).
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function frontendVisible(): \Illuminate\Support\Collection
    {
        return self::all()
            ->where('shown_on_frontend', true)
            ->sortBy('sort_order')
            ->values();
    }

    /**
     * Return fields that get their own column in the generated VIT
     * Excel/CSV file, ordered by sort_order. This is the field set and
     * order the export writer should iterate over to build the header row —
     * it already excludes fields that pack into another column
     * (packs_into_field) or append onto another field's value
     * (append_to_field), and fields that aren't part of the VIT spec at all
     * (vit_csv_column === null). Use vit_csv_column for the header text, not
     * web_app_label.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function exportableFields(): \Illuminate\Support\Collection
    {
        return self::all()
            ->whereNotNull('vit_csv_column')
            ->sortBy('sort_order')
            ->values();
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
     * Return field keys that are conditionally required, keyed by the field
     * they depend on.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function conditionalFieldKeys(): \Illuminate\Support\Collection
    {
        return self::all()
            ->where('requirement_type', 'conditional')
            ->where('is_system_derived', false);
    }

    /**
     * Return fields whose value gets packed into another field's CSV column
     * at generation time (e.g. UNSPSC, MSDS Link, Country of Origin all pack
     * into "classifications" as "KEY=value" pairs) rather than being written
     * to their own column.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function packedFields(): \Illuminate\Support\Collection
    {
        return self::all()
            ->whereNotNull('packs_into_field');
    }

    /**
     * Return fields whose value gets appended onto another field's value
     * (e.g. quantity_per_unit appends onto shortdescription) rather than
     * being written to their own column.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function appendedFields(): \Illuminate\Support\Collection
    {
        return self::all()
            ->whereNotNull('append_to_field');
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
