<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorMappingTemplateField extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_mapping_template_id',
        'catalog_field_id',
        'source_column_name',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(VendorMappingTemplate::class, 'vendor_mapping_template_id');
    }

    public function catalogField(): BelongsTo
    {
        return $this->belongsTo(CatalogField::class, 'catalog_field_id');
    }
}
