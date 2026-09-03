<?php

namespace App\Filament\Resources\CommodityTypes;

use App\Filament\Resources\CommodityTypes\Pages\CreateCommodityType;
use App\Filament\Resources\CommodityTypes\Pages\EditCommodityType;
use App\Filament\Resources\CommodityTypes\Pages\ListCommodityTypes;
use App\Filament\Resources\CommodityTypes\Schemas\CommodityTypeForm;
use App\Filament\Resources\CommodityTypes\Tables\CommodityTypesTable;
use App\Models\CommodityType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CommodityTypeResource extends Resource
{
    protected static ?string $model = CommodityType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog Management';

    public static function getNavigationBadge(): ?string
    {
        return (string) CommodityType::where('approved', false)
            ->count();
    }


    public static function form(Schema $schema): Schema
    {
        return CommodityTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CommodityTypesTable::configure($table);
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
            'index' => ListCommodityTypes::route('/'),
            'create' => CreateCommodityType::route('/create'),
            'edit' => EditCommodityType::route('/{record}/edit'),
        ];
    }
}
