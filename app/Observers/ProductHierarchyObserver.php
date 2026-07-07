<?php

namespace App\Observers;

use App\Models\ProductHierarchy;

/**
 * Auto-computes the "!"-joined path for a hierarchy row from its ancestor
 * chain, so admins don't have to type it manually and it can't drift out of
 * sync with the parent/name fields.
 */
class ProductHierarchyObserver
{
    public function saving(ProductHierarchy $hierarchy): void
    {
        $hierarchy->path = $this->buildPath($hierarchy);
    }

    protected function buildPath(ProductHierarchy $hierarchy): string
    {
        $segments = [$hierarchy->name];

        $parent = $hierarchy->parent_id ? ProductHierarchy::find($hierarchy->parent_id) : null;

        while ($parent) {
            array_unshift($segments, $parent->name);
            $parent = $parent->parent_id ? ProductHierarchy::find($parent->parent_id) : null;
        }

        return implode('!', $segments);
    }
}
