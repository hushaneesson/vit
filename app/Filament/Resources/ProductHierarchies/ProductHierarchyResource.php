<?php

namespace App\Filament\Resources\ProductHierarchies;

use App\Filament\Resources\ProductHierarchies\Pages\CreateProductHierarchy;
use App\Filament\Resources\ProductHierarchies\Pages\EditProductHierarchy;
use App\Filament\Resources\ProductHierarchies\Pages\ListProductHierarchies;
use App\Filament\Resources\ProductHierarchies\Schemas\ProductHierarchyForm;
use App\Filament\Resources\ProductHierarchies\Tables\ProductHierarchiesTable;
use App\Models\ProductHierarchy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductHierarchyResource extends Resource
{
    protected static ?string $model = ProductHierarchy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';



    public static function form(Schema $schema): Schema
    {
        return ProductHierarchyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductHierarchiesTable::configure($table);
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
            'index' => ListProductHierarchies::route('/'),
            'create' => CreateProductHierarchy::route('/create'),
            'edit' => EditProductHierarchy::route('/{record}/edit'),
        ];
    }
}
