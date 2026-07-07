<?php

namespace App\Filament\Resources\ProductHierarchies\Pages;

use App\Filament\Resources\ProductHierarchies\ProductHierarchyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductHierarchies extends ListRecords
{
    protected static string $resource = ProductHierarchyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
