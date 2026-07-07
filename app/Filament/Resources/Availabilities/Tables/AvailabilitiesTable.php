<?php

namespace App\Filament\Resources\Availabilities\Tables;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AvailabilitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('schedulable.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('frequency_config.days')
                    ->label('Days')
                    ->formatStateUsing(function ($state): string {
                        $days = is_array($state) ? $state : [];

                        if ($days === []) {
                            return 'None';
                        }

                        return collect($days)
                            ->map(fn(string $day): string => ucfirst(substr($day, 0, 3)))
                            ->join(', ');
                    })
                    ->wrap(),
                TextColumn::make('time_range')
                    ->label('Time Range')
                    ->state(function ($record): string {
                        $period = $record->periods->sortBy('id')->first();

                        if (! $period) {
                            return 'Not set';
                        }

                        return $period->start_time . ' - ' . $period->end_time;
                    }),
                TextColumn::make('metadata.slot_duration_minutes')
                    ->label('Slot Duration')
                    ->formatStateUsing(fn($state): string => ((int) ($state ?? 30)) . ' min')
                    ->sortable(),
                TextColumn::make('metadata.buffer_minutes')
                    ->label('Buffer')
                    ->formatStateUsing(fn($state): string => ((int) ($state ?? 0)) . ' min')
                    ->sortable(),
                TextColumn::make('metadata.max_appointments_per_day')
                    ->label('Max / Day')
                    ->formatStateUsing(fn($state): string => (string) ((int) ($state ?? 10)))
                    ->sortable(),
                TextColumn::make('start_date')
                    ->label('Effective From')
                    ->date()
                    ->sortable(),
                TextColumn::make('end_date')
                    ->label('Until')
                    ->date()
                    ->sortable()
                    ->placeholder('No end date'),
                TextColumn::make('frequency')
                    ->badge()
                    ->formatStateUsing(fn($state): string => $state ? ucfirst((string) $state) : 'N/A')
                    ->color('gray'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('schedulable_id')
                    ->label('User')
                    ->options(fn() => User::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
