<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogSubmissionItem extends Model
{
    protected $fillable = [
        'catalog_submission_id',
        'catalog_item_id',
        'vendor_id',
        'vendor_sku',
        'name',
        'description',
        'product_type',
        'unit_of_measure',
        'manufacturer_sku',
        'manufacturer_name',
        'brand_name',
        'list_price',
        'selling_price',
        'weight',
        'unspsc_code',
        'search_terms',
        'selling_points',
        'specifications',
        'classifications',
    ];

    protected $casts = [
        'list_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'weight' => 'decimal:3',
        'search_terms' => 'array',
        'selling_points' => 'array',
        'specifications' => 'array',
        'classifications' => 'array',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(CatalogSubmission::class);
    }
}
