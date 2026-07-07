<x-layouts.vendor title="My Catalogs">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">My Catalogs</h1>
                <p class="text-sm text-gray-500">{{ $vendor->name }}</p>
            </div>
            <a href="{{ route('vendor.catalog.create') }}"
                class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                + Add Catalog Item
            </a>
        </div>

        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 font-medium">Catalogs</div>
            @if ($catalogSummaries->isEmpty())
                <p class="p-4 text-sm text-gray-500">No catalog items yet. Click "Add Catalog Item" to get started.</p>
            @else
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th class="px-4 py-2">Catalog Name</th>
                            <th class="px-4 py-2">Items</th>
                            <th class="px-4 py-2">Last Updated</th>
                            <th class="px-4 py-2"></th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($catalogSummaries as $summary)
                            <tr class="{{ $selectedCatalog === $summary->catalog_name ? 'bg-indigo-50' : '' }}">
                                <td class="px-4 py-2 font-medium">{{ $summary->catalog_name }}</td>
                                <td class="px-4 py-2">{{ $summary->item_count }}</td>
                                <td class="px-4 py-2">{{ \Illuminate\Support\Carbon::parse($summary->last_updated)->format('M j, Y g:ia') }}</td>
                                <td class="px-4 py-2">
                                    <a href="{{ route('vendor.catalog.index', ['catalog' => $summary->catalog_name]) }}" class="text-indigo-600 hover:text-indigo-800">View Items</a>
                                </td>
                                <td class="px-4 py-2">
                                    <form method="POST" action="{{ route('vendor.catalog.submit') }}">
                                        @csrf
                                        <input type="hidden" name="catalog_name" value="{{ $summary->catalog_name }}">
                                        <button type="submit" class="text-green-700 hover:text-green-900"
                                            onclick="return confirm('Generate the final Excel file and submit this catalog for VIT upload?')">
                                            Generate & Submit
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if ($selectedCatalog && $items !== null)
            <div class="bg-white shadow rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 font-medium">
                    Items in "{{ $selectedCatalog }}"
                </div>
                @if ($items->isEmpty())
                    <p class="p-4 text-sm text-gray-500">No items in this catalog.</p>
                @else
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th class="px-4 py-2">Image</th>
                                <th class="px-4 py-2">Vendor Part #</th>
                                <th class="px-4 py-2">Description</th>
                                <th class="px-4 py-2">Status</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($items as $item)
                                <tr>
                                    <td class="px-4 py-2">
                                        @if ($item->images->isNotEmpty())
                                            <img src="{{ route('vendor.catalog-images.show', $item->images->first()) }}" class="w-12 h-12 object-cover rounded border">
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">{{ $item->vendor_part_number }}</td>
                                    <td class="px-4 py-2">{{ $item->field_values['short_description_base'] ?? '' }}</td>
                                    <td class="px-4 py-2">{{ ucfirst($item->status) }}</td>
                                    <td class="px-4 py-2 space-x-3">
                                        <a href="{{ route('vendor.catalog.edit', $item) }}" class="text-indigo-600 hover:text-indigo-800">Edit</a>
                                        <form method="POST" action="{{ route('vendor.catalog.destroy', $item) }}" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-800" onclick="return confirm('Delete this catalog item?')">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif
    </div>
</x-layouts.vendor>
