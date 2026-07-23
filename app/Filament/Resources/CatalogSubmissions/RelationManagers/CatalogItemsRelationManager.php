<?php

namespace App\Filament\Resources\CatalogSubmissions\RelationManagers;

use App\Models\CatalogItem;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\LinkAction;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class CatalogItemsRelationManager extends RelationManager
{
    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $title = 'Submitted Catalog Items';

    protected static string|BackedEnum|null $icon = 'heroicon-o-rectangle-stack';

    public function table(Table $table): Table
    {
        return $table
            ->recordUrl(null)
            ->columns([
                TextColumn::make('name')
                    ->label('Item Name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('vendor_sku')
                    ->label('Vendor SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('manufacturer_sku')
                    ->label('Manufacturer SKU')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('brand_name')
                    ->label('Brand')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product_type')
                    ->label('Product Type')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'incomplete' => 'danger',
                        'acceptable' => 'warning',
                        'excellent' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('completeness_score')
                    ->label('Completeness')
                    ->suffix('%')
                    ->numeric()
                    ->sortable()
                    ->color(fn($record) => $record->completeness_score >= 80 ? 'success' : 'warning'),
                TextColumn::make('list_price')
                    ->label('List Price')
                    ->money()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('selling_price')
                    ->label('Selling Price')
                    ->money()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'incomplete' => 'Incomplete',
                        'acceptable' => 'Acceptable',
                        'excellent' => 'Excellent',
                    ])
                    ->label('Status'),
            ])
            ->defaultSort('name', 'asc')
            ->actions([
                LinkAction::make('view')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->url(fn($record) => route('filament.admin.resources.catalog-items.edit', $record))
                    ->openUrlInNewTab(),
            ]);
    }

    public static function getModelLabel(): string
    {
        return 'Catalog Item';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Catalog Items';
    }
}
