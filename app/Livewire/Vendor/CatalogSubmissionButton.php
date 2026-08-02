<?php

namespace App\Livewire\Vendor;

use App\Enums\CatalogSubmissionStatus;
use App\Jobs\GenerateCatalogExportJob;
use App\Models\CatalogItem;
use App\Models\CatalogSubmission;
use App\Notifications\CatalogReadyForReviewNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Dedicated Livewire component for the "Request VIT Upload" button
 * on the catalog items index page.
 *
 * Creates a CatalogSubmission and attaches the selected CatalogItems
 * through a simple pivot relationship. The Excel file is generated
 * from the CatalogItems directly.
 */
class CatalogSubmissionButton extends Component
{
    /**
     * Catalog item stats scoped to the authenticated vendor.
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
     * Check if there is already an active (pending) submission for this vendor.
     *
     * A submission is considered "active" while the vendor is waiting for
     * admin review. This covers both ReviewRequested (just submitted, Excel
     * generation may be in progress) and ReadyForReview (Excel generated,
     * awaiting admin action). In both states the vendor should see the
     * "Review Requested" panel and the "Withdraw Review Request" button
     * instead of the "Request Review" button.
     */
    #[Computed]
    public function existingPendingSubmission(): ?CatalogSubmission
    {
        $client = Auth::guard('client')->user();

        if (! $client) {
            return null;
        }

        return CatalogSubmission::where('vendor_id', $client->vendor_id)
            ->whereIn('status', [
                CatalogSubmissionStatus::ReviewRequested,
                CatalogSubmissionStatus::ReadyForReview,
            ])
            ->latest()
            ->first();
    }

    /**
     * Vendor requests admin review of their catalog.
     *
     * Creates a CatalogSubmission for ALL current CatalogItems belonging to this vendor.
     * There is no item selection — the entire vendor catalog is always submitted.
     * The generated Excel file is the submission artifact.
     *
     * Dispatches GenerateCatalogExportJob to build the Excel file from
     * all current CatalogItems for this vendor.
     */
    public function requestReview(): void
    {
        $client = Auth::guard('client')->user();

        if (! $client) {
            $this->addError('review', 'You must be logged in to request a review.');
            return;
        }

        $vendorId = $client->vendor_id;

        try {
            // Guard: vendor must have at least one catalog item
            $totalItems = CatalogItem::where('vendor_id', $vendorId)->count();

            if ($totalItems === 0) {
                $this->addError('review', 'Your catalog is empty. Please upload catalog items before requesting review.');
                return;
            }

            // Guard: no duplicate pending review for this vendor
            $pending = CatalogSubmission::where('vendor_id', $vendorId)
                ->whereIn('status', [
                    CatalogSubmissionStatus::ReviewRequested,
                    CatalogSubmissionStatus::ReadyForReview,
                ])
                ->exists();

            if ($pending) {
                $this->addError('review', 'A review request is already pending for this catalog. Please wait for admin review.');
                return;
            }

            $submission = null;

            DB::transaction(function () use ($client, $vendorId, $totalItems, $completeItems, $incompleteItems, &$submission) {
                $submission = CatalogSubmission::create([
                    'vendor_id'              => $vendorId,
                    'catalog_upload_id'      => null,
                    'requested_by_client_id' => $client->id,
                    'status'                 => CatalogSubmissionStatus::ReviewRequested,
                    'total_items'            => $totalItems,
                    'requested_at'           => now(),
                ]);
            });

            // Dispatch the Excel generation job after successful transaction commit
            if ($submission) {
                try {
                    GenerateCatalogExportJob::dispatch($submission->id);

                    //logic to send notification to admin about the new review re

                    $vendorName = 'Unknown Vendor';
                    if ($client->vendor && is_object($client->vendor) && is_string($client->vendor->name)) {
                        $vendorName = $client->vendor->name;
                    }

                    $adminEmail = config('vit.admin_email');

                    // Ensure admin_email is a valid string before sending notification
                    if (is_string($adminEmail) && $adminEmail !== '') {
                        \Illuminate\Support\Facades\Notification::route('mail', $adminEmail)
                            ->notify(new CatalogReadyForReviewNotification(
                                vendorId: $vendorId,
                            ));
                    }
                } catch (\Throwable $e) {
                    // Log notification failure but don't block the review request
                    \Illuminate\Support\Facades\Log::error('Catalog review notification failed', [
                        'submission_id' => $submission->id,
                        'vendor_id' => $vendorId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            // Invalidate the cached computed property so the re-render
            // picks up the newly created submission immediately.
            unset($this->existingPendingSubmission);

            $this->dispatch('review-requested');
        } catch (\Throwable $e) {
            // Log detailed error for debugging
            \Illuminate\Support\Facades\Log::error('Catalog review request failed', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Show friendly error to vendor
            $this->addError('review', 'Unable to submit catalog for review. Our team has been notified.');
        }
    }

    /**
     * Vendor withdraws a pending review request.
     *
     * Changes the submission status to Withdrawn so the vendor can
     * submit a new review request later if needed.
     */
    public function withdrawReview(CatalogSubmission $submission): void
    {
        $client = Auth::guard('client')->user();

        if (! $client) {
            $this->addError('withdraw', 'You must be logged in to withdraw a review request.');
            return;
        }

        // Verify ownership
        if ($submission->vendor_id !== $client->vendor_id) {
            $this->addError('withdraw', 'You do not have permission to withdraw this submission.');
            return;
        }

        // Guard: can only withdraw if still pending review
        if (! in_array($submission->status, [CatalogSubmissionStatus::ReviewRequested, CatalogSubmissionStatus::ReadyForReview], true)) {
            $this->addError('withdraw', 'This submission cannot be withdrawn in its current state.');
            return;
        }

        try {
            $submission->update([
                'status' => CatalogSubmissionStatus::Withdrawn,
            ]);

            // Invalidate the cached computed property so the re-render
            // reflects the withdrawn state immediately.
            unset($this->existingPendingSubmission);

            $this->dispatch('review-withdrawn');
        } catch (\Throwable $e) {
            $this->addError('withdraw', 'Failed to withdraw review request: ' . $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.vendor.catalog-submission-button');
    }
}
