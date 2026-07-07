<?php

namespace App\Filament\Resources\CatalogFieldDefinitions\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Admin screen for `catalog_field_definitions` (Phase 3B) — the single
 * source of truth for every VIT column. Reorderable via sort_order so the
 * admin controls entry-form field order without a developer touching code.
 */
class CatalogFieldDefinitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('web_app_label')
                    ->label('Vendor-Facing Label')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('vit_column_header')
                    ->label('VIT Column')
                    ->searchable()
                    ->fontFamily('mono'),
                TextColumn::make('field_key')
                    ->label('Field Key')
                    ->searchable()
                    ->fontFamily('mono')
                    ->toggleable(),
                TextColumn::make('field_type')
                    ->badge(),
                TextColumn::make('requirement_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'required' => 'danger',
                        'conditional' => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('is_multi_value')
                    ->label('Multi')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('join_separator')
                    ->label('Join')
                    ->toggleable(),
                IconColumn::make('visible_in_web_app')
                    ->label('Visible')
                    ->boolean(),
                IconColumn::make('triggers_email_alert')
                    ->label('Email Alert')
                    ->boolean()
                    ->toggleable(),
                IconColumn::make('active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('field_type')
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
                    ]),
                SelectFilter::make('requirement_type')
                    ->options([
                        'required' => 'Required',
                        'conditional' => 'Conditional',
                        'optional' => 'Optional',
                    ]),
                TernaryFilter::make('visible_in_web_app'),
                TernaryFilter::make('active'),
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
