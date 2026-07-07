<?php

namespace App\Filament\Resources\Appointments\Tables;

use App\Models\Appointment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AppointmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Appointment')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('schedulable.name')
                    ->label('Owner')
                    ->placeholder('N/A')
                    ->searchable(),
                TextColumn::make('start_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('periods_count')
                    ->label('Slots')
                    ->counts('periods')
                    ->sortable(),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn(bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn(bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('schedulable_type')
                    ->label('Owner Type')
                    ->options(fn() => Appointment::query()
                        ->select('schedulable_type')
                        ->distinct()
                        ->orderBy('schedulable_type')
                        ->pluck('schedulable_type', 'schedulable_type')
                        ->mapWithKeys(fn($value, $key) => [$key => class_basename($value)])),
                SelectFilter::make('is_active')
                    ->options([
                        1 => 'Active',
                        0 => 'Inactive',
                    ]),
            ]);
    }
}
