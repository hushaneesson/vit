<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single source of truth for every VIT CSV/XLSX column. See Phase 3B.
 * Admin can add/edit/deactivate/reorder fields here without code changes.
 */
class CatalogFieldDefinition extends Model
{
    protected $fillable = [
        'web_app_label',
        'vit_column_header',
        'description',
        'requirement_type',
        'conditional_on_field',
        'conditional_on_value',
        'field_type',
        'max_length',
        'decimal_places',
        'is_multi_value',
        'join_separator',
        'visible_in_web_app',
        'triggers_email_alert',
        'field_key',
        'options_source',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'is_multi_value' => 'boolean',
            'visible_in_web_app' => 'boolean',
            'triggers_email_alert' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeVisibleInWebApp($query)
    {
        return $query->where('visible_in_web_app', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
