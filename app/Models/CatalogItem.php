<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One product/catalog item. Traceable to both the client (who did it) and
 * the vendor (which company it belongs to). Vendor-level data isolation is
 * enforced by vendor_id so multiple clients under the same vendor see the
 * same catalog data.
 */
class CatalogItem extends Model
{
    protected $fillable = [
        'vendor_id',
        'client_id',
        'catalog_name',
        'vendor_part_number',
        'field_values',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'field_values' => 'array',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(CatalogItemImage::class)->orderBy('sort_order');
    }
}
