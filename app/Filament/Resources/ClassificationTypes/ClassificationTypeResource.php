<?php

namespace App\Filament\Resources\ClassificationTypes;

use App\Filament\Resources\ClassificationTypes\Pages\CreateClassificationType;
use App\Filament\Resources\ClassificationTypes\Pages\EditClassificationType;
use App\Filament\Resources\ClassificationTypes\Pages\ListClassificationTypes;
use App\Filament\Resources\ClassificationTypes\Schemas\ClassificationTypeForm;
use App\Filament\Resources\ClassificationTypes\Tables\ClassificationTypesTable;
use App\Models\ClassificationType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ClassificationTypeResource extends Resource
{
    protected static ?string $model = ClassificationType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog Management';


    public static function form(Schema $schema): Schema
    {
        return ClassificationTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClassificationTypesTable::configure($table);
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
            'index' => ListClassificationTypes::route('/'),
            'create' => CreateClassificationType::route('/create'),
            'edit' => EditClassificationType::route('/{record}/edit'),
        ];
    }
}
