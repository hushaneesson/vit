<?php

namespace App\Filament\Resources\CatalogFieldDefinitions;

use App\Filament\Resources\CatalogFieldDefinitions\Pages\CreateCatalogFieldDefinition;
use App\Filament\Resources\CatalogFieldDefinitions\Pages\EditCatalogFieldDefinition;
use App\Filament\Resources\CatalogFieldDefinitions\Pages\ListCatalogFieldDefinitions;
use App\Filament\Resources\CatalogFieldDefinitions\Schemas\CatalogFieldDefinitionForm;
use App\Filament\Resources\CatalogFieldDefinitions\Tables\CatalogFieldDefinitionsTable;
use App\Models\CatalogFieldDefinition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CatalogFieldDefinitionResource extends Resource
{
    protected static ?string $model = CatalogFieldDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';


    public static function form(Schema $schema): Schema
    {
        return CatalogFieldDefinitionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CatalogFieldDefinitionsTable::configure($table);
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
            'index' => ListCatalogFieldDefinitions::route('/'),
            'create' => CreateCatalogFieldDefinition::route('/create'),
            'edit' => EditCatalogFieldDefinition::route('/{record}/edit'),
        ];
    }
}
