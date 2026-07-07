<?php

namespace App\Filament\Resources\CatalogFieldDefinitions\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CatalogFieldDefinitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('web_app_label')
                    ->required(),
                TextInput::make('vit_column_header')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                Select::make('requirement_type')
                    ->options(['required' => 'Required', 'conditional' => 'Conditional', 'optional' => 'Optional'])
                    ->default('optional')
                    ->required(),
                TextInput::make('conditional_on_field'),
                TextInput::make('conditional_on_value'),
                Select::make('field_type')
                    ->options([
            'text' => 'Text',
            'number' => 'Number',
            'decimal' => 'Decimal',
            'date' => 'Date',
            'boolean' => 'Boolean',
            'dropdown' => 'Dropdown',
            'multi-value-list' => 'Multi value list',
            'key-value-pairs' => 'Key value pairs',
            'image-upload' => 'Image upload',
        ])
                    ->required(),
                TextInput::make('max_length')
                    ->numeric(),
                TextInput::make('decimal_places')
                    ->numeric(),
                Toggle::make('is_multi_value')
                    ->required(),
                TextInput::make('join_separator'),
                Toggle::make('visible_in_web_app')
                    ->required(),
                Toggle::make('triggers_email_alert')
                    ->required(),
                TextInput::make('field_key')
                    ->required(),
                TextInput::make('options_source'),
                TextInput::make('sort_order')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('active')
                    ->required(),
            ]);
    }
}
