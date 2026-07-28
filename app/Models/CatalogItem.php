<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogItem extends Model
{
    use HasUuids, HasFactory;

    protected $fillable = [
        'vendor_id',
        'name',
        'description',
        'manufacturer_sku',
        'manufacturer_name',
        'brand_name',
        'vendor_sku',
        'catalog_upload_id',
        'data_fingerprint',
        'unspsc_code',
        'product_type',
        'unit_of_measure',
        'quantity_per_unit',
        'weight',
        'min_order_quantity',
        'max_order_quantity',
        'multiples',
        'search_terms',
        'classifications',
        'specifications',
        'selling_points',
        'msds_link',
        'list_price',
        'selling_price',

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
     * The catalog submissions that included this item.
     * Recorded via the catalog_submission_items pivot for auditability.
     */
    public function catalogSubmissions(): BelongsToMany
    {
        return $this->belongsToMany(CatalogSubmission::class, 'catalog_submission_items')
            ->withTimestamps();
    }

    // Fields that are considered "required" for a complete item.
    // These are the data points a vendor MUST provide for the item
    // to be usable. Missing required fields mark the item as 'incomplete'.
    protected static array $requiredFields = [
        'name',
        'description',
        'vendor_sku',
        'unit_of_measure',
    ];

    // Fields that push an item from "acceptable" to "excellent"
    protected static array $excellentFields = [
        'manufacturer_sku',
        'manufacturer_name',
        'brand_name',
        'unspsc_code',
        'product_type',
        'search_terms',
        'specifications',
        'selling_points',
        'classifications',
        'msds_link',
        'quantity_per_unit',
        'weight',
        'min_order_quantity',
        'max_order_quantity',
        'multiples',
        'list_price',
        'selling_price',
    ];


    protected static function booted()
    {
        static::saving(function ($item) {
            // Calculate using the dirty/updated attributes of this model instance
            $stats = self::calculateCompleteness($item->toArray());

            $item->completeness_score = $stats['score'];
            $item->status = $stats['status'];
        });
    }

    /**
     * Static Engine: Calculates completeness using raw array data.
     */
    public static function calculateCompleteness(array $data): array
    {
        $allFields = array_merge(self::$requiredFields, self::$excellentFields);
        $filledCount = 0;

        // 1. Calculate the score (0 to 100)
        foreach ($allFields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $filledCount++;
            }
        }
        $score = (int) round(($filledCount / count($allFields)) * 100);

        // 2. Determine the status state
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
