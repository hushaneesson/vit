<?php

namespace App\Filament\Pages;

use App\Models\CatalogItem;
use App\Models\CatalogSubmission;
use App\Models\Vendor;
use BackedEnum;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 13: lets the Onboarding Manager clear a vendor's catalog data from
 * the portal once VIT has confirmed it's been loaded into eLink. Optionally
 * also removes (or just archives) the associated submissions records and
 * their stored Excel files.
 */
class PurgeVendorData extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static ?string $navigationLabel = 'Data Purge Tool';

    protected static ?string $title = 'Purge Vendor Catalog Data';

    protected string $view = 'filament.pages.purge-vendor-data';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('vendor_id')
                    ->label('Vendor')
                    ->options(fn() => Vendor::query()->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                Checkbox::make('delete_submissions')
                    ->label('Also delete submission records and their stored Excel files (unchecked = archive/keep submissions, only catalog items are purged)')
                    ->default(false),
            ])
            ->statePath('data');
    }

    public function purge(): void
    {
        $state = $this->form->getState();

        $vendor = Vendor::findOrFail($state['vendor_id']);

        $itemCount = CatalogItem::where('vendor_id', $vendor->id)->count();
        CatalogItem::where('vendor_id', $vendor->id)->delete();

        $submissionCount = 0;

        if ($state['delete_submissions'] ?? false) {
            $submissions = CatalogSubmission::where('vendor_id', $vendor->id)->get();
            $submissionCount = $submissions->count();

            foreach ($submissions as $submission) {
                if ($submission->file_path) {
                    Storage::disk($submission->disk ?? 'local')->delete($submission->file_path);
                }
                $submission->delete();
            }
        }

        Notification::make()
            ->title('Vendor data purged')
            ->body("Removed {$itemCount} catalog item(s) for {$vendor->name}" .
                ($submissionCount > 0 ? ", and deleted {$submissionCount} submission record(s)/file(s)." : '.'))
            ->success()
            ->send();

        $this->form->fill();
    }
}
