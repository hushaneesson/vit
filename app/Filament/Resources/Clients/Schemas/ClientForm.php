<?php

namespace App\Filament\Resources\Clients\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Admin-only form for creating/editing client (login) accounts under a
 * vendor. Note: there is no password field — clients authenticate via
 * email-OTP only. Creating a client automatically emails an invitation
 * (see App\Observers\ClientObserver).
 */
class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('vendor_id')
                    ->label('Vendor (Company)')
                    ->relationship('vendor', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('phone')
                    ->tel(),
                TextInput::make('title')
                    ->label('Job Title'),
                Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'disabled' => 'Disabled',
                    ])
                    ->default('active')
                    ->required()
                    ->helperText('Clients cannot log in via OTP until their status is Active.'),
            ]);
    }
}
