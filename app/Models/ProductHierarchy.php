<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 3-level product hierarchy (Category Level 1/2/3). Leaf (level 3) rows
 * carry the hierarchy_number VIT expects. `path` is the "!"-joined
 * concatenation of level 1!level 2!level 3 names.
 */
class ProductHierarchy extends Model
{
    protected $table = 'product_hierarchies';

    protected $fillable = [
        'parent_id',
        'level',
        'name',
        'hierarchy_number',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'hierarchy_number');
    }

    public function getPathAttribute()
    {
        $path = [];
        $category = $this;

        while ($category) {
            array_unshift($path, $category->name);
            $category = $category->parent;
        }

        return  implode('! ', $path);
    }
}
