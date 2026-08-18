<?php

namespace App\Filament\Resources\CommodityTypes\Tables;

use App\Models\CommodityType;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CommodityTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query): Builder => $query
                ->orderBy('approved', 'asc')
                ->orderBy('name', 'asc'))
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                IconColumn::make('approved')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('approved')
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
                    ->visible(fn(CommodityType $record): bool => ! $record->approved)
                    ->requiresConfirmation()
                    ->modalHeading('Approve commodity type')
                    ->modalDescription('This will allow vendors to submit catalogs including this commodity type.')
                    ->action(function (CommodityType $record): void {
                        if ($record->approved) {
                            Notification::make()
                                ->title('Commodity type is already approved.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $record->update(['approved' => true]);

                        Notification::make()
                            ->title('Commodity type approved.')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }
}
