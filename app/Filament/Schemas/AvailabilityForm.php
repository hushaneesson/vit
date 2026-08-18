<?php

namespace App\Filament\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AvailabilityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('start_date')
                    ->required(),
                DatePicker::make('end_date')
                    ->afterOrEqual('start_date'),
                Repeater::make('weekly_periods')
                    ->label('Weekly periods')
                    ->schema([
                        \Filament\Forms\Components\Select::make('day')
                            ->options([
                                'monday' => 'Monday',
                                'tuesday' => 'Tuesday',
                                'wednesday' => 'Wednesday',
                                'thursday' => 'Thursday',
                                'friday' => 'Friday',
                                'saturday' => 'Saturday',
                                'sunday' => 'Sunday',
                            ])
                            ->disabled()
                            ->dehydrated(),
                        TimePicker::make('start_time')
                            ->label('Start time')
                            ->seconds(false)
                            ->required(fn($get) => (bool) $get('enabled')),
                        TimePicker::make('end_time')
                            ->label('End time')
                            ->seconds(false)
                            ->required(fn($get) => (bool) $get('enabled'))
                            ->after('start_time'),
                        Toggle::make('enabled')
                            ->default(false),
                    ])
                    ->default(self::defaultWeeklyPeriods())
                    ->columns(4)
                    ->columnSpanFull()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false),
                TextInput::make('slot_duration_minutes')
                    ->label('Slot duration (minutes)')
                    ->numeric()
                    ->minValue(5)
                    ->default(30)
                    ->required(),
                TextInput::make('buffer_minutes')
                    ->label('Buffer between slots (minutes)')
                    ->numeric()
                    ->minValue(0)
                    ->default(30)
                    ->required(),
                TextInput::make('max_appointments_per_day')
                    ->label('Max appointments per day')
                    ->numeric()
                    ->minValue(1)
                    ->default(5)
                    ->required(),
            ])
            ->columns(2);
    }

    public static function defaultWeeklyPeriods(): array
    {
        return collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->map(fn(string $day) => [
                'day' => $day,
                'start_time' => '09:00',
                'end_time' => '17:00',
                'enabled' => false,
            ])
            ->all();
    }
}
