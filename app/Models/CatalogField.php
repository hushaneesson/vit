<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The static, admin-managed list of VIT eLink fields a vendor's uploaded
 * columns get mapped to on the mapping screen. Deliberately has no
 * category/hierarchy concept - this table is a flat field list only.
 */
class CatalogField extends Model
{
    use HasFactory;

    protected $fillable = [
        'field_key',
        'web_app_label',
        'description',
        'notes',
        'requirement_type',
        'field_type',
        'is_system_derived',
        'system_source',
        'is_multi_value',
        'join_separator',
        'max_length',
        'conditional_on_field',
        'conditional_on_value',
        'active',
        'visible_in_web_app',
        'sort_order',
    ];

    protected $casts = [
        'is_multi_value' => 'boolean',
        'is_system_derived' => 'boolean',
        'active' => 'boolean',
        'visible_in_web_app' => 'boolean',
        'max_length' => 'integer',
        'sort_order' => 'integer',
    ];

    public function columnMappings(): HasMany
    {
        return $this->hasMany(CatalogUploadColumnMapping::class, 'catalog_field_id');
    }

    public function templateFields(): HasMany
    {
        return $this->hasMany(VendorMappingTemplateField::class, 'catalog_field_id');
    }
}
