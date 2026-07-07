<?php

namespace App\Filament\Resources\CatalogFieldDefinitions\Pages;

use App\Filament\Resources\CatalogFieldDefinitions\CatalogFieldDefinitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCatalogFieldDefinitions extends ListRecords
{
    protected static string $resource = CatalogFieldDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
