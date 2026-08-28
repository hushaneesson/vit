<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Models\Client;
use App\Notifications\ClientInvitationNotification;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
                        'invited' => 'warning',
                        'disabled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('last_login_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Never logged in'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('vendor_id')
                    ->label('Vendor')
                    ->relationship('vendor', 'name')
                    ->searchable(),
                SelectFilter::make('status')
                    ->options([
                        'invited' => 'Invited',
                        'active' => 'Active',
                        'disabled' => 'Disabled',
                    ]),
            ])
            ->recordActions([
                Action::make('resendInvitation')
                    ->label('Resend Invite')
                    ->icon('heroicon-o-envelope')
                    ->visible(fn(Client $record) => $record->status !== 'active')
                    ->action(function (Client $record) {
                        $token = $record->generateInvitationToken();
                        $record->notify(new ClientInvitationNotification($token));

                        $this->getLivewire()->dispatch('notify', type: 'success', message: 'Invitation resent to ' . $record->email);
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
