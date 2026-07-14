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
        'manufacturer_name',
        'brand_name',
        'vendor_sku',
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
        'selling_price'
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
}
