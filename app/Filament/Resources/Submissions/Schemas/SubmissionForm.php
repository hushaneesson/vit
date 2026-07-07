<?php

namespace App\Filament\Resources\Submissions\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class SubmissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('vendor_id')
                    ->relationship('vendor', 'name')
                    ->required(),
                Select::make('client_id')
                    ->relationship('client', 'name')
                    ->required(),
                TextInput::make('catalog_name')
                    ->required(),
                TextInput::make('file_path')
                    ->required(),
                TextInput::make('file_size')
                    ->numeric(),
                TextInput::make('product_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                DateTimePicker::make('submission_date'),
                Select::make('status')
                    ->options([
            'pending_upload' => 'Pending upload',
            'uploaded' => 'Uploaded',
            'failed' => 'Failed',
            'processing' => 'Processing',
        ])
                    ->default('pending_upload')
                    ->required(),
                TextInput::make('upload_attempts')
                    ->required()
                    ->numeric()
                    ->default(0),
                Textarea::make('last_upload_error')
                    ->columnSpanFull(),
                DateTimePicker::make('uploaded_at'),
                TextInput::make('vit_api_response'),
            ]);
    }
}
