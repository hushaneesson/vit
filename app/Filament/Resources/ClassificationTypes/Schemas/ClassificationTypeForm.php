<?php

namespace App\Filament\Resources\ClassificationTypes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ClassificationTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->required(),
                TextInput::make('label')
                    ->required(),
                Toggle::make('is_always_required')
                    ->label('Required')
                    ->required(),
            ]);
    }
}
