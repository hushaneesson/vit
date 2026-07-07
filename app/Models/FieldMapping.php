<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vendor's file-column -> catalog field mapping (Phase 8). Saved against
 * vendor_id so any client at that vendor benefits from a mapping a
 * colleague already set up.
 */
class FieldMapping extends Model
{
    protected $fillable = [
        'vendor_id',
        'created_by',
        'name',
        'source_columns',
        'mapped_fields',
    ];

    protected function casts(): array
    {
        return [
            'source_columns' => 'array',
            'mapped_fields' => 'array',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'created_by');
    }
}
