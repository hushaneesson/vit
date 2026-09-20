<?php

namespace App\Livewire\Vendor;

use App\Enums\CatalogUploadStatus;
use App\Models\CatalogUpload;
use App\Services\Catalog\CatalogUploadNotificationAcknowledger;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CatalogProcessingBanner extends Component
{
    public ?int $catalogId = null;

    public ?CatalogUpload $completedUpload = null;

    public bool $showCompletionNotification = false;

    #[Computed]
    public function activeUpload(): ?CatalogUpload
    {
        if (! $this->catalogId) {
            return null;
        }

        $client = Auth::guard('client')->user();

        if (! $client) {
            return null;
        }

        return CatalogUpload::where('vendor_id', $client->vendor_id)
            ->where('catalog_id', $this->catalogId)
            ->whereIn('status', [
                CatalogUploadStatus::Queued,
                CatalogUploadStatus::Processing,
                CatalogUploadStatus::ProcessingItems,
            ])
            ->latest()
            ->first();
    }

    #[Computed]
    public function pendingCompletionUpload(): ?CatalogUpload
    {
        if (! $this->catalogId) {
            return null;
        }

        $client = Auth::guard('client')->user();

        if (! $client) {
            return null;
        }

        $completed = CatalogUpload::where('vendor_id', $client->vendor_id)
            ->where('catalog_id', $this->catalogId)
            ->where('status', CatalogUploadStatus::Completed)
            ->whereNotNull('processing_completed_at')
            ->latest('processing_completed_at')
            ->first();

        if (! $completed) {
            return null;
        }

        if (CatalogUploadNotificationAcknowledger::isAcknowledged($completed->id)) {
            return null;
        }

        return $completed;
    }

    public function dismissCompletionNotification(): void
    {
        $this->markNotificationShown();

        $this->showCompletionNotification = false;
        $this->completedUpload = null;
    }

    public function viewReport(): void
    {
        $this->markNotificationShown();

        $this->redirectRoute('vendor.catalog-upload.summary', ['catalogId' => $this->catalogId, 'catalogUpload' => $this->completedUpload->id]);
    }

    private function markNotificationShown(): void
    {
        if (! $this->completedUpload) {
            return;
        }

        CatalogUploadNotificationAcknowledger::acknowledge($this->completedUpload->id);
    }

    public function render()
    {
        $this->completedUpload = $this->pendingCompletionUpload();
        $this->showCompletionNotification = $this->completedUpload !== null;

        return view('livewire.vendor.catalog-processing-banner');
    }
}
