<?php

namespace App\Filament\Resources\Appointments\Tables;

use App\Models\Appointment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AppointmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                return $query
                    ->where('schedulable_type', \App\Models\User::class)
                    ->where('schedulable_id', auth()->id())
                    ->where('schedule_type', \Zap\Enums\ScheduleTypes::APPOINTMENT->value);
            })
            ->defaultSort('start_date', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label('Appointment')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('start_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('periods')
                    ->label('Time')
                    ->getStateUsing(function (Appointment $record) {
                        return $record->periods
                            ->map(fn($period) => \Carbon\Carbon::parse($period->start_time)->format('h:i A')
                                . ' - '
                                . \Carbon\Carbon::parse($period->end_time)->format('h:i A'))
                            ->implode(', ');
                    })
                    ->wrap()
                    ->placeholder('No times set'),
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
