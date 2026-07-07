<?php

namespace App\Services;

use App\Models\Submission;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Thin wrapper around VIT's catalog upload API (Phase 11). Endpoint, auth
 * token, and timeout all come from config/vit.php (backed by .env) — no
 * secrets or URLs are hardcoded here. The real endpoint/auth scheme is a
 * placeholder until VIT provides production details; swap the request
 * shape below once real API docs are available.
 */
class VitApiClient
{
    /**
     * POST the submission's stored Excel file to VIT as multipart form
     * data with `file`, `vendor_name`, `catalog_name`.
     *
     * @throws ConnectionException
     */
    public function upload(Submission $submission): Response
    {
        $disk = 'local';
        $absolutePath = Storage::disk($disk)->path($submission->file_path);

        $request = Http::timeout(config('vit.api.timeout', 30));

        if ($token = config('vit.api.token')) {
            $request = $request->withToken($token);
        }

        return $request
            ->attach('file', file_get_contents($absolutePath), basename($submission->file_path))
            ->post(config('vit.api.endpoint'), [
                'vendor_name' => $submission->vendor?->name,
                'catalog_name' => $submission->catalog_name,
            ]);
    }
}
