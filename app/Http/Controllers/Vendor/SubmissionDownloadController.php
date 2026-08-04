<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\CatalogSubmission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authenticated vendor download of a generated catalog submission file.
 */
class SubmissionDownloadController extends Controller
{
    public function __invoke(CatalogSubmission $submission): StreamedResponse
    {
        $client = Auth::guard('client')->user();

        // Vendor-level isolation: any client at this vendor may download,
        // regardless of which client originally generated the submission.
        abort_unless($client->vendor_id === $submission->vendor_id, 403);

        abort_unless(Storage::disk($submission->disk ?? 'local')->exists($submission->file_path), 404);

        $filename = basename($submission->file_path);

        return Storage::disk($submission->disk ?? 'local')->download($submission->file_path, $filename);
    }
}
