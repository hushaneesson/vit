<?php

namespace App\Observers;

use App\Models\ProductHierarchy;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-computes the "!"-joined path for a hierarchy row from its ancestor
 * chain, so admins don't have to type it manually and it can't drift out of
 * sync with the parent/name fields.
 */
class ProductHierarchyObserver
{
    public function saving(ProductHierarchy $hierarchy): void
    {
        // Some environments don't have a physical `path` column.
        // Avoid mutating an unknown column on insert/update.
        if (! Schema::hasColumn('product_hierarchies', 'path')) {
            return;
        }

        $hierarchy->path = $this->buildPath($hierarchy);
    }

    protected function buildPath(ProductHierarchy $hierarchy): string
    {
        $segments = [$hierarchy->name];

        $parent = $hierarchy->parent_id
            ? ProductHierarchy::query()->where('hierarchy_number', $hierarchy->parent_id)->first()
            : null;

        while ($parent) {
            array_unshift($segments, $parent->name);
            $parent = $parent->parent_id
                ? ProductHierarchy::query()->where('hierarchy_number', $parent->parent_id)->first()
                : null;
        }

        return implode('!', $segments);
    }
}
