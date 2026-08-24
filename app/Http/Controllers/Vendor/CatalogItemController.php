<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\CatalogItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CatalogItemController extends Controller
{
    /**
     * List all catalogs items.
     */
    public function index(Catalog $catalog, Request $request)
    {
        $client = Auth::guard('client')->user();
        abort_unless($client->vendor_id === $catalog->vendor_id, 403);

        $items = CatalogItem::query()
            ->where('vendor_id', $client->vendor_id)
            ->where('catalog_id', $catalog->id)
            ->when($request->filled('search'), function ($query) use ($request) {
                // search name, dealer_sku, manufacturer_sku
                $query->where(function ($query) use ($request) {
                    $query->where('name', 'like', '%' . $request->search . '%')
                        ->orWhere('dealer_sku', 'like', '%' . $request->search . '%')
                        ->orWhere('manufacturer_sku', 'like', '%' . $request->search . '%');
                });
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->status);
            })
            ->orderBy('name')
            ->paginate(25);

        $items->appends($request->only(['search', 'status']));

        return view('vendor.catalog.index', [
            'vendor' => $client->vendor,
            'catalog' => $catalog,
            'items' => $items,
        ]);
    }


    public function renderUpload()
    {
        return view('vendor.catalog.upload');
    }

    public function create(Catalog $catalog)
    {
        $client = Auth::guard('client')->user();
        abort_unless($client->vendor_id === $catalog->vendor_id, 403);

        return view('vendor.catalog.create', [
            'catalogId' => (int) $catalog->id,
            'catalog' => $catalog,
        ]);
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

        $catalogId = $catalogItem->catalog_id;

        foreach ($catalogItem->images()->get() as $image) {
            \Illuminate\Support\Facades\Storage::disk($image->disk)->delete($image->path);
        }

        $catalogItem->delete();

        return redirect()
            ->route('vendor.catalog.items', ['catalog' => $catalogId])
            ->with('status', 'Catalog item deleted.');
    }
}
