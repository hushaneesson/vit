<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogUploadColumnMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'catalog_upload_id',
        'catalog_field_id',
        'column_index',
        'source_column_name',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(CatalogUpload::class, 'catalog_upload_id');
    }

    public function catalogField(): BelongsTo
    {
        return $this->belongsTo(CatalogField::class, 'catalog_field_id');
    }
}
