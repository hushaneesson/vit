<?php

namespace App\Filament\Resources\CountryCodes;

use App\Filament\Resources\CountryCodes\Pages\CreateCountryCode;
use App\Filament\Resources\CountryCodes\Pages\EditCountryCode;
use App\Filament\Resources\CountryCodes\Pages\ListCountryCodes;
use App\Filament\Resources\CountryCodes\Schemas\CountryCodeForm;
use App\Filament\Resources\CountryCodes\Tables\CountryCodesTable;
use App\Models\CountryCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CountryCodeResource extends Resource
{
    protected static ?string $model = CountryCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAmericas;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog Management';

    public static function form(Schema $schema): Schema
    {
        return CountryCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CountryCodesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCountryCodes::route('/'),
            'create' => CreateCountryCode::route('/create'),
            'edit' => EditCountryCode::route('/{record}/edit'),
        ];
    }
}
