<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogItem extends Model
{
    use HasUuids, HasFactory;

    protected $fillable = [
        'vendor_id',
        'catalog_id',
        'name',
        'description',
        'manufacturer_sku',
        'manufacturer',
        'brand_name',
        'dealer_sku',
        'replacement_sku',
        'images',
        'hierarchy',
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
        'list_price',
        'selling_price',
        'is_discontinued',
        'discontinue_date',

        // not VIT, but useful for completeness scoring
        'status',
        'completeness_score',
        'last_submitted_at',
    ];

    protected $casts = [
        'images' => 'array',
        'search_terms' => 'array',
        'specifications' => 'array',
        'selling_points' => 'array',
        'classifications' => 'array',
        'last_submitted_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function catalog()
    {
        return $this->belongsTo(Catalog::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(CatalogItemImage::class)->orderBy('sort_order');
    }

    public function hierarchyInfo()
    {
        return $this->belongsTo(ProductHierarchy::class, 'hierarchy', 'id');
    }

    public function commodityType()
    {
        return $this->belongsTo(CommodityType::class, 'category', 'id');
    }

    public function unitOfMeasure()
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_of_measure', 'id');
    }

    /**
     * Whether this item has never been submitted or was submitted and has
     * not changed since. last_submitted_at is cleared whenever the item is
     * modified, so a non-null value always means it matches what was submitted.
     *
     * - 'not_submitted': last_submitted_at is null.
     * - 'submitted': last_submitted_at is set.
     */
    protected function submissionState(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->last_submitted_at === null ? 'modified' : 'submitted',
        );
    }

    protected function replacementSku(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null || $value === '') {
                    return [];
                }

                if (is_array($value)) {
                    return $this->normalizeReplacementSkuList($value);
                }

                if (is_string($value)) {
                    $decoded = json_decode($value, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        return $this->normalizeReplacementSkuList($decoded);
                    }

                    return [$value];
                }

                return [];
            },
            set: function ($value) {
                $replacementSkus = is_array($value) ? $value : [$value];
                $replacementSkus = $this->normalizeReplacementSkuList($replacementSkus);

                return $replacementSkus === [] ? null : json_encode($replacementSkus);
            },
        );
    }

    /**
     * @param  array<int, mixed>  $replacementSkus
     * @return array<int, string>
     */
    protected function normalizeReplacementSkuList(array $replacementSkus): array
    {
        return array_values(array_filter(array_map(
            fn($replacementSku) => trim((string) $replacementSku),
            $replacementSkus,
        ), fn($replacementSku) => $replacementSku !== ''));
    }

    protected static array $requiredFields = [
        'name',
        'description',
        'dealer_sku',
        'unit_of_measure',
        'manufacturer_sku',
        'manufacturer',
        'hierarchy',
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

            // Any change other than an explicit last_submitted_at update means
            // the item no longer matches what was last submitted.
            if ($item->exists && $item->isDirty() && ! $item->isDirty('last_submitted_at')) {
                $item->last_submitted_at = null;
            }
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
