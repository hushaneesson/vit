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
        'dealer_sku',
        'replacement_sku',
        'images',
        'hierarchy',
        'unspsc_code',
        'category',
        'unit_of_measure',
        'quantity_per_unit',
        'item_weight',
        'lead_time',
        'availability',
        'min_qty_per_order',
        'max_qty_per_order',
        'multiples',
        'search_terms',
        'classifications',
        'specifications',
        'selling_points',
        'msds_link',
        'list_price',
        'selling_price',
        'is_discontinued',
        'discontinue_date',

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

    protected static array $requiredFields = [
        'name',
        'description',
        'dealer_sku',
        'unit_of_measure',
        'manufacturer_sku',
        'manufacturer',
        'hierarchy',
        'unspsc_code',
        'category',
        'specifications',
        'selling_points',
        'quantity_per_unit',
        'item_weight',
        'selling_price',
    ];

    protected static array $excellentFields = [
        'brand_name',
        'images',
        'search_terms',
        'classifications',
        'msds_link',
        'min_qty_per_order',
        'max_qty_per_order',
        'multiples',
        'list_price',
        'availability',
        'lead_time',
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
