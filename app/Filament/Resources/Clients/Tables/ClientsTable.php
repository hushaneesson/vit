<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Models\Client;
use App\Notifications\ClientInvitationNotification;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->description(fn(Client $record): string => $record->vendor?->name)
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'active' => 'success',
                        'disabled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('last_login_at')
                    ->dateTime('M d, Y g:i A', 'America/New_York')
                    ->sortable()
                    ->placeholder('Never logged in'),
            ])
            ->filters([
                SelectFilter::make('vendor_id')
                    ->label('Vendor')
                    ->relationship('vendor', 'name')
                    ->searchable(),
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'disabled' => 'Disabled',
                    ]),
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
