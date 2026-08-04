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
    public ?CatalogSubmission $existingPendingSubmission = null;

    public function mount(): void
    {
        $this->loadPendingSubmission();
    }

    private function loadPendingSubmission(): void
    {
        $client = Auth::guard('client')->user();

        if (! $client) {
            $this->existingPendingSubmission = null;
            return;
        }

        $this->existingPendingSubmission = CatalogSubmission::where('vendor_id', $client->vendor_id)
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
    public function submitCatalog(): void
    {
        $client = Auth::guard('client')->user();

        if (! $client) {
            $this->dispatch('notify', type: 'error', message: 'You must be logged in to complete this action.');
            return;
        }

        $vendorId = $client->vendor_id;

        try {
            $totalItems = CatalogItem::where('vendor_id', $vendorId)->count();

            if ($totalItems === 0) {
                $this->dispatch('notify', type: 'error', message: 'Your catalog is empty. Please upload catalog items before submitting.');
                return;
            }

            $pending = CatalogSubmission::where('vendor_id', $vendorId)
                ->whereIn('status', [
                    CatalogSubmissionStatus::ReviewRequested,
                    CatalogSubmissionStatus::ReadyForReview,
                ])
                ->exists();

            if ($pending) {
                $this->dispatch('notify', type: 'error', message: 'A submission is already pending for this catalog.');
                return;
            }

            $completeItems = CatalogItem::where('vendor_id', $vendorId)
                ->whereIn('status', ['acceptable', 'excellent'])
                ->count();
            $incompleteItems = $totalItems - $completeItems;

            $submission = null;

            DB::transaction(function () use ($client, $vendorId, $totalItems, $completeItems, $incompleteItems, &$submission) {
                $submission = CatalogSubmission::create([
                    'vendor_id'              => $vendorId,
                    'requested_by_client_id' => $client->id,
                    'status'                 => CatalogSubmissionStatus::ReviewRequested,
                    'total_items'            => $totalItems,
                    'requested_at'           => now(),
                ]);
            });

            if ($submission) {
                try {
                    GenerateCatalogExportJob::dispatch($submission->id);

                    $vendorName = 'Unknown Vendor';
                    if ($client->vendor && is_object($client->vendor) && is_string($client->vendor->name)) {
                        $vendorName = $client->vendor->name;
                    }

                    $adminEmail = config('vit.admin_email');

                    if (is_string($adminEmail) && $adminEmail !== '') {
                        \Illuminate\Support\Facades\Notification::route('mail', $adminEmail)
                            ->notify(new CatalogReadyForReviewNotification(
                                vendorId: $vendorId,
                            ));
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Catalog admin notification failed', [
                        'submission_id' => $submission->id,
                        'vendor_id' => $vendorId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            $this->loadPendingSubmission();

            $this->dispatch('notify', type: 'success', message: 'Your catalog has been submitted.');
            // $this->dispatch('review-requested');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Catalog submission failed', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->dispatch('notify', type: 'error', message: 'Unable to submit catalog. Our team has been notified.');
        }
    }

    public function withdrawSubmission(CatalogSubmission $submission): void
    {
        $client = Auth::guard('client')->user();

        if (! $client) {
            $this->dispatch('notify', type: 'error', message: 'You must be logged in to withdraw a submission.');
            return;
        }

        if ($submission->vendor_id !== $client->vendor_id) {
            $this->dispatch('notify', type: 'error', message: 'You do not have permission to withdraw this submission.');
            return;
        }

        if (! in_array($submission->status, [CatalogSubmissionStatus::ReviewRequested, CatalogSubmissionStatus::ReadyForReview], true)) {
            $this->dispatch('notify', type: 'error', message: 'This submission cannot be withdrawn in its current state.');
            return;
        }

        try {
            $submission->update([
                'status' => CatalogSubmissionStatus::Withdrawn,
            ]);

            $this->loadPendingSubmission();

            $this->dispatch('notify', type: 'success', message: 'Submission withdrawn.');
            // $this->dispatch('review-withdrawn');
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: 'Failed to withdraw submission: ' . $e->getMessage());
        }
    }



    public function render()
    {
        return view('livewire.vendor.catalog-submission-button');
    }
}
