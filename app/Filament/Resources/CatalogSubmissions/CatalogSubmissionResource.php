<?php

namespace App\Filament\Resources\CatalogSubmissions;

use App\Filament\Resources\CatalogSubmissions\Pages\EditCatalogSubmission;
use App\Filament\Resources\CatalogSubmissions\Pages\ListCatalogSubmissions;
use App\Filament\Resources\CatalogSubmissions\Tables\CatalogSubmissionsTable;
use App\Models\CatalogSubmission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CatalogSubmissionResource extends Resource
{
    protected static ?string $model = CatalogSubmission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Schemas\Components\Section::make('Submission Information')
                ->description('Details about this catalog submission request')
                ->icon('heroicon-o-clipboard-document-list')
                ->schema([
                    \Filament\Schemas\Components\Text::make('vendor.name')
                        ->label('Vendor')
                        ->default(fn($record) => $record->vendor->name ?? 'N/A'),
                    \Filament\Schemas\Components\Text::make('status')
                        ->badge()
                        ->color(fn(string $state): string => match ($state) {
                            'draft' => 'gray',
                            'review_requested' => 'warning',
                            'approved' => 'success',
                            'rejected' => 'danger',
                            'ready_for_upload' => 'info',
                            'uploaded' => 'success',
                            default => 'gray',
                        }),
                    \Filament\Schemas\Components\Text::make('requested_at')
                        ->dateTime()
                        ->label('Requested Date'),
                    \Filament\Schemas\Components\Text::make('catalogUpload.catalog_name')
                        ->label('Catalog Name')
                        ->default('—'),
                ])->columns(2),

            \Filament\Schemas\Components\Section::make('Submission Statistics')
                ->description('Item counts and completeness metrics')
                ->icon('heroicon-o-chart-bar')
                ->schema([
                    \Filament\Schemas\Components\Text::make('total_items')
                        ->label('Total Items')
                        ->numeric(),
                    \Filament\Schemas\Components\Text::make('complete_items')
                        ->label('Complete Items')
                        ->numeric()
                        ->color('success'),
                    \Filament\Schemas\Components\Text::make('incomplete_items')
                        ->label('Incomplete Items')
                        ->numeric()
                        ->color('danger'),
                    \Filament\Schemas\Components\Text::make('completeness_percent')
                        ->label('Completeness')
                        ->state(fn($record) => $record->total_items > 0
                            ? round(($record->complete_items / $record->total_items) * 100) . '%'
                            : '0%')
                        ->color(fn($record) => $record->total_items > 0 && round(($record->complete_items / $record->total_items) * 100) >= 80
                            ? 'success'
                            : 'warning'),
                ])->columns(2),

            \Filament\Schemas\Components\Section::make('Review Details')
                ->description('Approval, rejection, and upload information')
                ->icon('heroicon-o-information-circle')
                ->schema([
                    \Filament\Schemas\Components\Text::make('approved_by')
                        ->label('Approved By')
                        ->default('—'),
                    \Filament\Schemas\Components\Text::make('approved_at')
                        ->label('Approved At')
                        ->dateTime()
                        ->default('—'),
                    \Filament\Schemas\Components\Text::make('rejection_reason')
                        ->label('Rejection Reason')
                        ->default('—'),
                    \Filament\Schemas\Components\Text::make('uploaded_at')
                        ->label('Uploaded At')
                        ->dateTime()
                        ->default('—'),
                ])->columns(2)->visible(fn($record) => $record->status !== \App\Enums\CatalogSubmissionStatus::Draft),
        ]);
    }

    public static function table(Table $table): Table
    {
        return CatalogSubmissionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\CatalogSubmissions\RelationManagers\CatalogItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCatalogSubmissions::route('/'),
            'edit' => EditCatalogSubmission::route('/{record}/edit'),
        ];
    }
}
