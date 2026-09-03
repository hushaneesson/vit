<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('A password reset link is emailed after the user is created.'),

                Checkbox::make('has_appointments')
                    ->label('Has Appointments')
                    ->helperText('If checked, this user will be the default recipient of appointment notifications and be able to manage appointments.'),
            ]);
    }
}
