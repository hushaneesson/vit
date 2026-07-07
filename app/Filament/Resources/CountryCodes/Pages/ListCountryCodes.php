<?php

namespace App\Filament\Resources\CountryCodes\Pages;

use App\Filament\Resources\CountryCodes\CountryCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCountryCodes extends ListRecords
{
    protected static string $resource = CountryCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
