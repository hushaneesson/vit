<?php

namespace App\Filament\Resources\UnitOfMeasures\Tables;

use App\Models\UnitOfMeasure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UnitOfMeasuresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query): Builder => $query
                ->orderBy('active', 'asc')
                ->orderBy('code', 'asc'))
            ->columns([
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('description')
                    ->searchable(),
                IconColumn::make('active')
                    ->label('approved')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('active')
                    ->options([
                        1 => 'Approved',
                        0 => 'Not Approved',
                    ])
                    ->label('Approval Status'),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(UnitOfMeasure $record): bool => ! $record->active)
                    ->requiresConfirmation()
                    ->modalHeading('Approve unit of measure')
                    ->modalDescription('This will allow vendors to submit catalogs including this unit of measure.')
                    ->action(function (UnitOfMeasure $record): void {
                        if ($record->active) {
                            $this->getLivewire()->dispatch('notify', type: 'warning', message: 'Unit of measure is already approved.');

                            return;
                        }

                        $record->update(['active' => true]);

                        $this->getLivewire()->dispatch('notify', type: 'success', message: 'Unit of measure approved.');
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                // BulkActionGroup::make([
                //     DeleteBulkAction::make(),
                // ]),
            ]);
    }
}
