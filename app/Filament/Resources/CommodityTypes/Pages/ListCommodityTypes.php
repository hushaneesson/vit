<?php

namespace App\Filament\Resources\CommodityTypes\Pages;

use App\Filament\Resources\CommodityTypes\CommodityTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCommodityTypes extends ListRecords
{
    protected static string $resource = CommodityTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
