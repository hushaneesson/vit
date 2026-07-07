<?php

namespace App\Filament\Resources\CatalogFieldDefinitions\Pages;

use App\Filament\Resources\CatalogFieldDefinitions\CatalogFieldDefinitionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCatalogFieldDefinition extends EditRecord
{
    protected static string $resource = CatalogFieldDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
