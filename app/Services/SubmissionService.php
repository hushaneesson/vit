<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Client;
use App\Models\Submission;
use App\Models\Vendor;
use App\Services\Excel\CatalogExcelGenerator;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 9: after Phase 8 mapping/validation completes (or a direct catalog
 * export is requested), generate the final Excel file, store it, and
 * create the submissions record with status = pending_upload.
 */
class SubmissionService
{
    public function __construct(protected CatalogExcelGenerator $generator) {}

    public function createSubmission(Vendor $vendor, Client $client): Submission
    {
        $items = CatalogItem::query()
            ->where('vendor_id', $vendor->id)
            ->with('images')
            ->get();

        $catalogName = 'Catalog Submission ' . $vendor->name;
        $disk = 'local';
        $path = $this->generator->generateAndStore($vendor, $catalogName, $items, $disk);

        $fileSize = Storage::disk($disk)->size($path);

        return Submission::create([
            'vendor_id' => $vendor->id,
            'client_id' => $client->id,
            'file_path' => $path,
            'file_size' => $fileSize,
            'product_count' => $items->count(),
            'submission_date' => now(),
            'status' => 'pending_upload',
            'upload_attempts' => 0,
        ]);
    }
}
