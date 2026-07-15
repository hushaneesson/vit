<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorMappingTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'created_by_client_id',
        'name',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function createdByClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'created_by_client_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(VendorMappingTemplateField::class);
    }
}
