<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\CatalogItemImage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a single catalog item image for authenticated preview in the
 * vendor-facing entry form (Phase 4/5). Images live on the private "local"
 * disk (never publicly accessible), so previews are streamed through this
 * vendor-scoped, authenticated endpoint rather than a public Storage URL.
 */
class CatalogItemImageController extends Controller
{
    public function __invoke(CatalogItemImage $catalogItemImage): StreamedResponse
    {
        $client = Auth::guard('client')->user();

        abort_unless(
            $client && $client->vendor_id === $catalogItemImage->catalogItem->vendor_id,
            403
        );

        abort_unless(Storage::disk($catalogItemImage->disk)->exists($catalogItemImage->path), 404);

        return Storage::disk($catalogItemImage->disk)->response($catalogItemImage->path);
    }
}
