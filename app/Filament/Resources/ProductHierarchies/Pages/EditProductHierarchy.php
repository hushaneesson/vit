<?php

namespace App\Filament\Resources\ProductHierarchies\Pages;

use App\Filament\Resources\ProductHierarchies\ProductHierarchyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductHierarchy extends EditRecord
{
    protected static string $resource = ProductHierarchyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
