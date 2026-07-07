<?php

namespace App\Filament\Resources\CountryCodes\Pages;

use App\Filament\Resources\CountryCodes\CountryCodeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCountryCode extends CreateRecord
{
    protected static string $resource = CountryCodeResource::class;
}
