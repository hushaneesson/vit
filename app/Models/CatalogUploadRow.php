<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogUploadRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'catalog_upload_id',
        'row_number',
        'data',
        'raw_data',
        'status',
        'errors',
    ];

    protected $casts = [
        'data' => 'array',
        'raw_data' => 'array',
        'errors' => 'array',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(CatalogUpload::class, 'catalog_upload_id');
    }
}
