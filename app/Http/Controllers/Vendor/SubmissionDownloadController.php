<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Enums\CatalogSubmissionStatus;
use App\Models\CatalogSubmission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authenticated vendor download of a generated catalog submission file.
 */
class SubmissionDownloadController extends Controller
{
    private const DOWNLOADABLE_STATUSES = [
        CatalogSubmissionStatus::ReviewRequested,
        CatalogSubmissionStatus::ReadyForReview,
    ];

    public function __invoke(CatalogSubmission $catalogSubmission): StreamedResponse
    {
        $client = Auth::guard('client')->user();

        $this->authorizeAccess($client, $catalogSubmission);

        $disk = Storage::disk($catalogSubmission->disk ?? 'local');

        abort_unless($disk->exists($catalogSubmission->file_path), 404);

        return $disk->download(
            $catalogSubmission->file_path,
            basename($catalogSubmission->file_path)
        );
    }

    /**
     * Runs all vendor/status/catalog isolation checks, aborting on failure.
     */
    private function authorizeAccess($client, CatalogSubmission $catalogSubmission): void
    {
        // Vendor-level isolation: any client at this vendor may download,
        // regardless of which client originally generated the submission.
        abort_unless($client->vendor_id === $catalogSubmission->vendor_id, 403);

        // Only active (in-review) submissions may be downloaded by vendors.
        // Withdrawn/rejected/draft/uploaded historical submissions are excluded.
        abort_unless(
            in_array($catalogSubmission->status, self::DOWNLOADABLE_STATUSES, true),
            403
        );

        // Catalog-level isolation: the submission's catalog must belong to this
        // vendor (legacy NULL-catalog submissions remain downloadable for
        // backward compatibility).
        abort_unless(
            $catalogSubmission->catalog_id === null
                || $catalogSubmission->catalog?->vendor_id === $client->vendor_id,
            403
        );
    }
}
