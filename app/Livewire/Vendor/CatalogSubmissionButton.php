<?php

namespace App\Livewire\Vendor;

use App\Enums\CatalogSubmissionStatus;
use App\Models\CatalogItem;
use App\Models\CatalogSubmission;
use App\Models\CatalogSubmissionItem;
use App\Notifications\CatalogReadyForReviewNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Dedicated Livewire component for the "Request VIT Upload" button
 * on the catalog items index page.
 *
 * This component replaces the submission logic that was previously
 * embedded in the CSV upload summary screen. It operates against
 * the vendor's full catalog state, not a single upload session.
 */
class CatalogSubmissionButton extends Component
{
    /**
     * Vendor ID is resolved from the authenticated client on each request.
     * No property needed — we read from the guard.
     */

    /**
     * Catalog item stats scoped to the authenticated vendor.
     * Counts items across all catalog_upload_ids (the vendor's full catalog).
     */
    #[Computed]
    public function catalogItemStats(): ?array
    {
        $client = Auth::guard('client')->user();

        if (! $client) {
            return null;
        }

        $total = CatalogItem::where('vendor_id', $client->vendor_id)->count();
        $complete = CatalogItem::where('vendor_id', $client->vendor_id)
            ->whereIn('status', ['acceptable', 'excellent'])
            ->count();
        $incomplete = $total - $complete;

        return [
            'total' => $total,
            'complete' => $complete,
            'incomplete' => $incomplete,
            'completeness_percent' => $total > 0 ? round(($complete / $total) * 100) : 0,
        ];
    }

    /**
     * Check if there is already a pending (review_requested) submission
     * for this vendor.
     */
    #[Computed]
    public function existingPendingSubmission(): ?CatalogSubmission
    {
        $client = Auth::guard('client')->user();

        if (! $client) {
            return null;
        }

        return CatalogSubmission::where('vendor_id', $client->vendor_id)
            ->where('status', CatalogSubmissionStatus::ReviewRequested)
            ->latest()
            ->first();
    }

    /**
     * Vendor requests admin review of their catalog.
     *
     * Creates a CatalogSubmission with a snapshot of the current eligible
     * catalog items, wrapped in a database transaction for atomicity.
     *
     * Unlike the old upload-scoped version, this operates against the
     * vendor's full catalog. catalog_upload_id is left null because
     * this submission represents the current catalog state, not a
     * specific upload event.
     */
    public function requestReview(): void
    {
        $client = Auth::guard('client')->user();

        if (! $client) {
            $this->addError('review', 'You must be logged in to request a review.');
            return;
        }

        $vendorId = $client->vendor_id;

        // Guard: must have exportable items across vendor's catalog
        $exportableItems = CatalogItem::where('vendor_id', $vendorId)
            ->whereIn('status', ['acceptable', 'excellent'])
            ->get();

        if ($exportableItems->isEmpty()) {
            $this->addError('review', 'Your catalog has no complete items to submit for review. Please upload a catalog with valid data first.');
            return;
        }

        // Guard: no duplicate pending review for this vendor
        $pending = CatalogSubmission::where('vendor_id', $vendorId)
            ->where('status', CatalogSubmissionStatus::ReviewRequested)
            ->exists();

        if ($pending) {
            $this->addError('review', 'A review request is already pending for this catalog. Please wait for admin review.');
            return;
        }

        // Count stats
        $totalItems = CatalogItem::where('vendor_id', $vendorId)->count();
        $completeItems = $exportableItems->count();
        $incompleteItems = $totalItems - $completeItems;

        $submission = null;

        try {
            DB::transaction(function () use ($client, $vendorId, $exportableItems, $totalItems, $completeItems, $incompleteItems, &$submission) {
                $submission = CatalogSubmission::create([
                    'vendor_id'              => $vendorId,
                    'catalog_upload_id'      => null, // Not tied to a specific upload
                    'requested_by_client_id' => $client->id,
                    'status'                 => CatalogSubmissionStatus::ReviewRequested,
                    'total_items'            => $totalItems,
                    'complete_items'         => $completeItems,
                    'incomplete_items'       => $incompleteItems,
                    'requested_at'           => now(),
                ]);

                // Create snapshot records for each exportable catalog item
                // This preserves the exact data at submission time for auditability
                foreach ($exportableItems as $item) {
                    CatalogSubmissionItem::create([
                        'catalog_submission_id' => $submission->id,
                        'catalog_item_id'       => $item->id,
                        'vendor_id'             => $item->vendor_id,
                        'vendor_sku'            => $item->vendor_sku,
                        'name'                  => $item->name,
                        'description'           => $item->description,
                        'product_type'          => $item->product_type,
                        'unit_of_measure'       => $item->unit_of_measure,
                        'manufacturer_sku'      => $item->manufacturer_sku,
                        'manufacturer_name'     => $item->manufacturer_name,
                        'brand_name'            => $item->brand_name,
                        'list_price'            => $item->list_price,
                        'selling_price'         => $item->selling_price,
                        'weight'                => $item->weight,
                        'unspsc_code'           => $item->unspsc_code,
                        'search_terms'          => $item->search_terms,
                        'selling_points'        => $item->selling_points,
                        'specifications'        => $item->specifications,
                        'classifications'       => $item->classifications,
                    ]);
                }
            });

            // Dispatch notification only after successful transaction commit
            if ($submission) {
                \Illuminate\Support\Facades\Notification::route('mail', config('vit.admin_email'))
                    ->notify(new CatalogReadyForReviewNotification(
                        vendorName: $client->vendor->name,
                    ));
            }

            $this->dispatch('review-requested');
        } catch (\Throwable $e) {
            $this->addError('review', 'Failed to submit review request: ' . $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.vendor.catalog-submission-button');
    }
}
