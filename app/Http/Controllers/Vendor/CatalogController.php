<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function index()
    {
        $client = Auth::guard('client')->user();

        $catalogs = Catalog::query()
            ->where('vendor_id', $client->vendor_id)
            ->withCount('items')
            ->orderBy('name')
            ->get();

        return view('vendor.catalogs.index', [
            'vendor' => $client->vendor,
            'catalogs' => $catalogs,
        ]);
    }

    public function create()
    {
        return view('vendor.catalogs.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $client = Auth::guard('client')->user();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('catalogs', 'name')->where('vendor_id', $client->vendor_id),
            ],
        ]);

        $catalog = Catalog::create([
            'vendor_id' => $client->vendor_id,
            'name' => $validated['name'],
        ]);

        return redirect()
            ->route('vendor.catalog.items', ['catalog' => $catalog->id])
            ->with('notify', [
                'type' => 'success',
                'message' => 'Catalog created. You can now add items to it.',
            ]);
    }
}
