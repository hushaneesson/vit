<?php

namespace App\Filament\Resources\Availabilities;

use App\Filament\Resources\Availabilities\Pages\CreateAvailability;
use App\Filament\Resources\Availabilities\Pages\EditAvailability;
use App\Filament\Resources\Availabilities\Pages\ListAvailabilities;
use App\Filament\Resources\Availabilities\Schemas\AvailabilityForm;
use App\Filament\Resources\Availabilities\Tables\AvailabilitiesTable;
use App\Models\Availability;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AvailabilityResource extends Resource
{
    protected static ?string $model = Availability::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsVertical;

    protected static ?string $navigationLabel = 'Availability Settings';

    protected static ?string $modelLabel = 'Availability';

    protected static ?string $pluralModelLabel = 'Availabilities';

    protected static string|UnitEnum|null $navigationGroup = 'Appointments';

    public static function form(Schema $schema): Schema
    {
        return AvailabilityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AvailabilitiesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['periods', 'schedulable']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAvailabilities::route('/'),
            'create' => CreateAvailability::route('/create'),
            'edit' => EditAvailability::route('/{record}/edit'),
        ];
    }
}
