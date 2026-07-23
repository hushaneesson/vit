<?php

namespace App\Models;

use App\Services\VitFieldDefinition;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogUploadColumnMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'catalog_upload_id',
        'field_key',
        'column_index',
        'source_column_name',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(CatalogUpload::class, 'catalog_upload_id');
    }

    /**
     * Retrieve the VIT field definition for this mapping.
     * Returns null if the field_key is not found in the static definitions.
     */
    public function getFieldDefinition(): ?object
    {
        return $this->field_key ? VitFieldDefinition::find($this->field_key) : null;
    }
}
