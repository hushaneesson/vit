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

    public function createSubmissionForCatalog(Vendor $vendor, Client $client, string $catalogName): Submission
    {
        $items = CatalogItem::query()
            ->where('vendor_id', $vendor->id)
            ->where('catalog_name', $catalogName)
            ->with('images')
            ->get();

        $disk = 'local';
        $path = $this->generator->generateAndStore($vendor, $catalogName, $items, $disk);

        $fileSize = Storage::disk($disk)->size($path);

        return Submission::create([
            'vendor_id' => $vendor->id,
            'client_id' => $client->id,
            'catalog_name' => $catalogName,
            'file_path' => $path,
            'file_size' => $fileSize,
            'product_count' => $items->count(),
            'submission_date' => now(),
            'status' => 'pending_upload',
            'upload_attempts' => 0,
        ]);
    }
}
