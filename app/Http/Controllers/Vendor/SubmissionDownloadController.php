<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 10 — authenticated vendor download of a generated submission file.
 * The file returned here is the exact same file stored during Phase 9/
 * delivered to VIT in Phase 11 — never regenerated on the fly.
 */
class SubmissionDownloadController extends Controller
{
    public function __invoke(Submission $submission): StreamedResponse
    {
        $client = Auth::guard('client')->user();

        // Vendor-level isolation: any client at this vendor may download,
        // regardless of which client originally generated the submission.
        abort_unless($client->vendor_id === $submission->vendor_id, 403);

        abort_unless(Storage::disk('local')->exists($submission->file_path), 404);

        $filename = basename($submission->file_path);

        return Storage::disk('local')->download($submission->file_path, $filename);
    }
}
