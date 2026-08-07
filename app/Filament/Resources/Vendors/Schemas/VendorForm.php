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
                TextInput::make('primary_contact_name'),
                TextInput::make('primary_contact_email')
                    ->email(),
                TextInput::make('primary_contact_phone')
                    ->tel(),
                Select::make('tier')
                    ->options([
                        'tier_1' => 'Tier 1',
                        'tier_2' => 'Tier 2',
                        'tier_3' => 'Tier 3',
                    ]),
                Textarea::make('notes')
                    ->rows(3)
                    ->maxLength(255),
                Select::make('status')
                    ->options(['active' => 'Active', 'inactive' => 'Inactive', 'pending' => 'Pending'])
                    ->default('pending')
                    ->required(),
            ]);
    }
}
