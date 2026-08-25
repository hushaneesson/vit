<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Catalog extends Model
{
    protected $fillable = [
        'vendor_id',
        'name',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CatalogItem::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(CatalogSubmission::class);
    }
}
