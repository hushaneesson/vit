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
        'seller_sku',
        'catalog_upload_id',
        'data_fingerprint',
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

    // Fields that are considered "required" for a complete item.
    protected static array $requiredFields = [
        'name',
        'description',
        'seller_sku',
        'unit_of_measure',
    ];

    // Fields that push an item from "acceptable" to "excellent"
    protected static array $excellentFields = [
        'manufacturer_sku',
        'manufacturer',
        'brand_name',
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
