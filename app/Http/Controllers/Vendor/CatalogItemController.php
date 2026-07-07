<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Services\SubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 4/9/12 — lists this vendor's catalog items (grouped by catalog),
 * and lets a client kick off Phase 9's submission generation for a given
 * catalog. Every query is scoped by vendor_id (never client_id) so any
 * client at the vendor sees the same catalog data their colleagues built.
 */
class CatalogItemController extends Controller
{
    /**
     * List all catalogs (grouped) for the logged-in client's vendor, with
     * an optional ?catalog= filter to drill into a single catalog's items.
     */
    public function index(Request $request)
    {
        $client = Auth::guard('client')->user();
        $vendor = $client->vendor;

        $selectedCatalog = $request->query('catalog');

        $catalogSummaries = CatalogItem::query()
            ->where('vendor_id', $vendor->id)
            ->selectRaw('catalog_name, count(*) as item_count, max(updated_at) as last_updated')
            ->groupBy('catalog_name')
            ->orderBy('catalog_name')
            ->get();

        $items = null;

        if ($selectedCatalog) {
            $items = CatalogItem::query()
                ->where('vendor_id', $vendor->id)
                ->where('catalog_name', $selectedCatalog)
                ->with('images')
                ->latest('updated_at')
                ->get();
        }

        return view('vendor.catalog.index', [
            'vendor' => $vendor,
            'catalogSummaries' => $catalogSummaries,
            'selectedCatalog' => $selectedCatalog,
            'items' => $items,
        ]);
    }

    public function create()
    {
        return view('vendor.catalog.create');
    }

    public function edit(CatalogItem $catalogItem)
    {
        $client = Auth::guard('client')->user();
        abort_unless($client->vendor_id === $catalogItem->vendor_id, 403);

        return view('vendor.catalog.edit', ['catalogItem' => $catalogItem]);
    }

    public function destroy(CatalogItem $catalogItem): RedirectResponse
    {

        $client = Auth::guard('client')->user();
        abort_unless($client->vendor_id === $catalogItem->vendor_id, 403);

        $catalogName = $catalogItem->catalog_name;

        foreach ($catalogItem->images as $image) {
            \Illuminate\Support\Facades\Storage::disk($image->disk)->delete($image->path);
        }

        $catalogItem->delete();

        return redirect()
            ->route('vendor.catalog.index', ['catalog' => $catalogName])
            ->with('status', 'Catalog item deleted.');
    }

    /**
     * Phase 9: generate the final Excel file for a whole catalog and create
     * the pending_upload submission record. Redirects to the vendor
     * dashboard where the download link (Phase 10) is shown.
     */
    public function submit(Request $request, SubmissionService $submissionService): RedirectResponse
    {
        $request->validate(['catalog_name' => ['required', 'string']]);

        $client = Auth::guard('client')->user();
        $vendor = $client->vendor;

        $itemCount = CatalogItem::query()
            ->where('vendor_id', $vendor->id)
            ->where('catalog_name', $request->catalog_name)
            ->count();

        if ($itemCount === 0) {
            return redirect()
                ->route('vendor.catalog.index')
                ->withErrors(['catalog_name' => 'That catalog has no items to submit.']);
        }

        $submissionService->createSubmissionForCatalog($vendor, $client, $request->catalog_name);

        return redirect()
            ->route('vendor.dashboard')
            ->with('status', "Catalog \"{$request->catalog_name}\" has been generated and is ready for download / VIT upload.");
    }
}
