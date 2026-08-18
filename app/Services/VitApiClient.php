<?php

namespace App\Services;

use App\Models\CatalogSubmission;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Thin wrapper around VIT's catalog upload API.
 * Endpoint, auth token, and timeout come from config/vit.php (backed by .env).
 */
class VitApiClient
{
    /**
     * POST a CatalogSubmission's generated Excel file to VIT.
     *
     * @throws ConnectionException
     */
    public function uploadCatalogSubmission(CatalogSubmission $submission): Response
    {
        $disk = $submission->disk ?? 'local';
        $absolutePath = Storage::disk($disk)->path($submission->file_path);

        $request = Http::timeout(config('vit.api.timeout', 30));

        if ($token = config('vit.api.token')) {
            $request = $request->withToken($token);
        }

        return $request
            ->attach('file', file_get_contents($absolutePath), basename($submission->file_path))
            ->post(config('vit.api.endpoint'), [
                'vendor_name' => $submission->vendor?->name,
            ]);
    }
}
