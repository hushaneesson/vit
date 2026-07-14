<x-layouts.vendor title="My Catalogs">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">My Catalogs</h1>
                <p class="text-sm text-gray-500">{{ $vendor->name }}</p>
            </div>
            <a href="{{ route('vendor.catalog.create') }}"
                class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">
                + Add Catalog Item
            </a>
        </div>

        @if ($items !== null)
            <div class="p-2 bg-white rounded-lg shadow-lg">
                @if ($items->isEmpty())
                    <p class="p-4 text-sm text-gray-500">No items in this catalog.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Image</th>
                                    <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Product</th>
                                    <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Vendor Part #</th>
                                    <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Status</th>
                                    <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase"></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach ($items as $item)
                                    <tr class="text-sm hover:bg-gray-50 whitespace-nowrap">
                                        <td class="px-6 py-4">
                                            @if ($item->images->isNotEmpty())
                                                <img src="{{ route('vendor.catalog-images.show', $item->images->first()) }}" class="object-cover w-12 h-12 border rounded">
                                            @endif
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="font-medium">{{ $item->name }}</div>
                                        </td>
                                        <td class="px-6 py-4 text-gray-500">{{ $item->vendor_sku }}</td>
                                        <td class="px-6 py-4">
                                            <span @class([
                                                'px-2 py-1 text-xs font-semibold leading-5 uppercase rounded-full',
                                                'text-emerald-600 bg-emerald-100' => $item->status === 'active',
                                                'text-gray-600 bg-gray-100' => $item->status !== 'active',
                                            ])>
                                                implement
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 space-x-3">
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
                    </div>

                    <div class="p-2 mt-4">
                        {{ $items->links('pagination::tailwind') }}
                    </div>

                @endif
            </div>
        @endif
    </div>
</x-layouts.vendor>
