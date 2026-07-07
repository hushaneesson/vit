<?php

namespace App\Filament\Resources\Vendors\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class VendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('legal_company_name'),
                TextInput::make('address_line_1'),
                TextInput::make('address_line_2'),
                TextInput::make('city'),
                TextInput::make('state'),
                TextInput::make('postal_code'),
                TextInput::make('country'),
                TextInput::make('primary_contact_name'),
                TextInput::make('primary_contact_email')
                    ->email(),
                TextInput::make('primary_contact_phone')
                    ->tel(),
                Select::make('status')
                    ->options(['active' => 'Active', 'inactive' => 'Inactive', 'pending' => 'Pending'])
                    ->default('pending')
                    ->required(),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
