<?php

namespace App\Filament\Resources\CatalogSubmissions;

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


    public static function getNavigationBadge(): ?string
    {
        return (string) CatalogSubmission::where('status', 'ready_for_review')
            ->count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Schemas\Components\Section::make('Submission Information')
                ->description('Details about this catalog submission request')
                ->icon('heroicon-o-clipboard-document-list')
                ->schema([
                    \Filament\Schemas\Components\View::make('vendor-name')
                        ->view('filament.forms.components.text-input')
                        ->default(fn($record) => $record->vendor->name ?? 'N/A'),
                    \Filament\Schemas\Components\View::make('status')
                        ->view('filament.forms.components.text-input')
                        ->default(fn($record) => $record->status->value ?? $record->status),
                    \Filament\Schemas\Components\View::make('requested_at')
                        ->view('filament.forms.components.text-input')
                        ->default(fn($record) => $record->requested_at?->format('M j, Y g:i A') ?? '—'),
                ])->columns(2),

            \Filament\Schemas\Components\Section::make('Review Details')
                ->description('Approval, rejection, and upload information')
                ->icon('heroicon-o-information-circle')
                ->schema([
                    \Filament\Schemas\Components\View::make('approved_by')
                        ->view('filament.forms.components.text-input')
                        ->default('—'),
                    \Filament\Schemas\Components\View::make('approved_at')
                        ->view('filament.forms.components.text-input')
                        ->default(fn($record) => $record->approved_at?->format('M j, Y g:i A') ?? '—'),
                    \Filament\Schemas\Components\View::make('rejection_reason')
                        ->view('filament.forms.components.text-input')
                        ->default(fn($record) => $record->rejection_reason ?? '—'),
                    \Filament\Schemas\Components\View::make('uploaded_at')
                        ->view('filament.forms.components.text-input')
                        ->default(fn($record) => $record->uploaded_at?->format('M j, Y g:i A') ?? '—'),
                ])->columns(2)->visible(fn($record) => $record->status !== \App\Enums\CatalogSubmissionStatus::Draft),
        ]);
    }

    public static function table(Table $table): Table
    {
        return CatalogSubmissionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCatalogSubmissions::route('/'),
        ];
    }
}
