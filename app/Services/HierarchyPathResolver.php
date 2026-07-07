<?php

namespace App\Services;

use App\Models\ProductHierarchy;
use App\Notifications\NewHierarchyPathNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Resolves a Category Level 1/2/3 selection into the "!"-joined Hierarchy
 * Path and the VIT hierarchy_number for that path (Phase 6). If the exact
 * path doesn't exist yet in the reference table, emails the VIT gateway
 * address so an admin can add it, per the VIT field spec.
 */
class HierarchyPathResolver
{
    /**
     * @return array{path: string, hierarchy_number: ?string, is_new: bool}
     */
    public function resolve(string $level1, string $level2, string $level3, string $vendorName, string $catalogName): array
    {
        $path = implode('!', [$level1, $level2, $level3]);

        $hierarchy = ProductHierarchy::query()
            ->where('level', 3)
            ->where('path', $path)
            ->first();

        if ($hierarchy && $hierarchy->hierarchy_number) {
            return [
                'path' => $path,
                'hierarchy_number' => $hierarchy->hierarchy_number,
                'is_new' => false,
            ];
        }

        $this->notifyNewPath($vendorName, $catalogName, $path);

        return [
            'path' => $path,
            'hierarchy_number' => null,
            'is_new' => true,
        ];
    }

    protected function notifyNewPath(string $vendorName, string $catalogName, string $path): void
    {
        Notification::route('mail', config('vit.gateway_email'))
            ->notify(new NewHierarchyPathNotification($vendorName, $catalogName, $path));
    }
}
