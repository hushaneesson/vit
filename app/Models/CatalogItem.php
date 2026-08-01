<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogItem extends Model
{
    use HasUuids, HasFactory;

    protected $fillable = [
        'vendor_id',
        'name',
        'description',
        'manufacturer_sku',
        'manufacturer',
        'brand_name',
        'brand_logo',
        'seller_sku',
        'image_file_name',
        'categorization_or_hierarchy',
        'unspsc_code',
        'product_type_or_family',
        'unit_of_measure',
        'quantity_per_unit',
        'item_weight',
        'min_qty_per_order',
        'max_qty_per_order',
        'multiples',
        'search_terms',
        'classifications',
        'specifications',
        'selling_points',
        'msds_link',
        'list_price',
        'selling_price_per_unit',

        // not VIT, but useful for completeness scoring
        'status',
        'completeness_score',
    ];

    protected $casts = [
        'search_terms' => 'array',
        'specifications' => 'array',
        'selling_points' => 'array',
        'classifications' => 'array',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(CatalogItemImage::class)->orderBy('sort_order');
    }

    /**
     * Minimum fields required for an item to be importable/displayable.
     *
     * IMPORTANT: This is NOT the full VIT-required field list.
     *
     * There are three distinct concepts:
     *   1. Database-required fields  – enforced by the schema (e.g.
     *      vendor_id, seller_sku, name). An item cannot exist without these.
     *   2. VIT-required fields       – fields VIT submission requires
     *      (see VitFieldDefinition). Some are NOT in this list because
     *      the system intentionally allows importing partial data.
     *   3. Completeness scoring      – requiredFields (below) gate whether
     *      an item is 'incomplete' vs 'acceptable'. excellentFields (below)
     *      push the score toward 100%.
     *
     * Every missing field (required OR excellent) lowers the completeness
     * score. Items missing any field in $requiredFields get 'incomplete'
     * status but are still importable and can be completed later from the
     * Catalog Item List.
     */
    protected static array $requiredFields = [
        'name',
        'description',
        'seller_sku',
        'unit_of_measure',
    ];

    /**
     * Additional fields that increase completeness toward VIT readiness.
     *
     * Includes the VIT-required fields NOT in $requiredFields
     * (categorization_or_hierarchy, list_price, selling_price_per_unit,
     * unspsc_code, item_weight) plus optional VIT fields. An item with all
     * of $requiredFields + $excellentFields filled scores 100 and is
     * marked 'excellent'.
     */
    protected static array $excellentFields = [
        'manufacturer_sku',
        'manufacturer',
        'brand_name',
        'brand_logo',
        'image_file_name',
        'categorization_or_hierarchy',
        'unspsc_code',
        'product_type_or_family',
        'search_terms',
        'specifications',
        'selling_points',
        'classifications',
        'msds_link',
        'quantity_per_unit',
        'item_weight',
        'min_qty_per_order',
        'max_qty_per_order',
        'multiples',
        'list_price',
        'selling_price_per_unit',
    ];

    protected static function booted()
    {
        static::saving(function ($item) {
            $stats = self::calculateCompleteness($item->toArray());
            $item->completeness_score = $stats['score'];
            $item->status = $stats['status'];
        });
    }

    /**
     * Calculate completeness score and status.
     *
     * - Score: percentage of all scoring fields (required + excellent)
     *   that have a non-blank value.
     * - Status: 'incomplete' if ANY $requiredField is missing;
     *   'excellent' if score is 100; otherwise 'acceptable'.
     *
     * An item can be 'acceptable' even when missing VIT-required fields
     * (e.g. list_price, unspsc_code) — those live in $excellentFields and
     * only reduce the score. 'incomplete' is reserved for missing minimum
     * import fields.
     */
    public static function calculateCompleteness(array $data): array
    {
        $allFields = array_merge(self::$requiredFields, self::$excellentFields);
        $filledCount = 0;

        foreach ($allFields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $filledCount++;
            }
        }
        $score = (int) round(($filledCount / count($allFields)) * 100);

        $status = 'acceptable';
        foreach (self::$requiredFields as $field) {
            if (empty($data[$field])) {
                $status = 'incomplete';
                break;
            }
        }

        if ($status !== 'incomplete' && $score === 100) {
            $status = 'excellent';
        }

        return [
            'score' => $score,
            'status' => $status,
        ];
    }
}
