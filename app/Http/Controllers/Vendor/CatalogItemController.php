<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CatalogItemController extends Controller
{
    /**
     * List all catalogs items.
     */
    public function index(Request $request)
    {
        $client = Auth::guard('client')->user();
        $vendor = $client->vendor;

        $items = CatalogItem::query()
            ->where('vendor_id', $vendor->id)
            ->when($request->filled('search'), function ($query) use ($request) {
                // search name, seller_sku, manufacturer_sku
                $query->where(function ($query) use ($request) {
                    $query->where('name', 'like', '%' . $request->search . '%')
                        ->orWhere('seller_sku', 'like', '%' . $request->search . '%')
                        ->orWhere('manufacturer_sku', 'like', '%' . $request->search . '%');
                });
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->status);
            })
            ->orderBy('name')
            ->paginate(25);

        return view('vendor.catalog.index', [
            'vendor' => $vendor,
            'items' => $items,
        ]);
    }


    public function renderUpload()
    {
        return view('vendor.catalog.upload');
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

        $catalogName = $catalogItem->name;

        foreach ($catalogItem->images as $image) {
            \Illuminate\Support\Facades\Storage::disk($image->disk)->delete($image->path);
        }

        $catalogItem->delete();

        return redirect()
            ->route('vendor.catalog.index', ['catalog' => $catalogName])
            ->with('status', 'Catalog item deleted.');
    }
}
