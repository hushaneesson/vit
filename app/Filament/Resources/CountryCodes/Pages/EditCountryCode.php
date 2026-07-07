<?php

namespace App\Filament\Resources\CountryCodes\Pages;

use App\Filament\Resources\CountryCodes\CountryCodeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCountryCode extends EditRecord
{
    protected static string $resource = CountryCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
