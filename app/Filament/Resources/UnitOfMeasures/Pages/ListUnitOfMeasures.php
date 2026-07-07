<?php

namespace App\Filament\Resources\UnitOfMeasures\Pages;

use App\Filament\Resources\UnitOfMeasures\UnitOfMeasureResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUnitOfMeasures extends ListRecords
{
    protected static string $resource = UnitOfMeasureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
