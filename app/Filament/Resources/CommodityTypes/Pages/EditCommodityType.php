<?php

namespace App\Filament\Resources\CommodityTypes\Pages;

use App\Filament\Resources\CommodityTypes\CommodityTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCommodityType extends EditRecord
{
    protected static string $resource = CommodityTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
