<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per generated Excel file destined for (or delivered to) VIT.
 */
class Submission extends Model
{
    protected $fillable = [
        'vendor_id',
        'client_id',
        'catalog_name',
        'file_path',
        'file_size',
        'product_count',
        'submission_date',
        'status',
        'upload_attempts',
        'last_upload_error',
        'uploaded_at',
        'vit_api_response',
    ];

    protected function casts(): array
    {
        return [
            'submission_date' => 'datetime',
            'uploaded_at' => 'datetime',
            'vit_api_response' => 'array',
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
}
