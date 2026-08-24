<x-layouts.vendor title="Catalogs">
    <div class="space-y-6">
        <div class="flex flex-col gap-4 md:items-center md:justify-between md:flex-row">
            <div>
                <h1 class="text-xl font-semibold">Catalogs</h1>
                <p class="text-sm text-gray-500">{{ $vendor->name }}</p>
            </div>
            <a href="{{ route('vendor.catalogs.create') }}" class="w-full md:w-auto btn btn-primary">
                <i class="fas fa-plus"></i>
                Create Catalog
            </a>
        </div>

        <div class="p-2 bg-white rounded-lg shadow-lg">
            @if ($catalogs->isEmpty())
                <div class="py-16 text-center">
                    <h3 class="text-sm font-semibold text-gray-900">No catalogs yet</h3>
                    <p class="mt-1 text-sm text-gray-500">Create a catalog to start adding items.</p>
                    <a href="{{ route('vendor.catalogs.create') }}" class="mt-4 btn btn-primary">
                        <i class="fas fa-plus"></i>
                        Create Catalog
                    </a>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 whitespace-nowrap">
                        <thead class="bg-gray-50">
                            <tr>
                                <th
                                    class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                    Catalog</th>
                                <th
                                    class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                    Items</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach ($catalogs as $catalog)
                                <tr @click="window.location='{{ route('vendor.catalog.items', $catalog) }}'"
                                    class="text-sm cursor-pointer hover:bg-gray-50">
                                    <td class="px-6 py-4 font-medium text-gray-900">{{ $catalog->name }}</td>
                                    <td class="px-6 py-4 text-gray-500">{{ $catalog->items_count }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <a href="{{ route('vendor.catalog.items', $catalog) }}"
                                            class="py-1.5 btn btn-gray">
                                            <i class="fas fa-list"></i>
                                            View Items
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-layouts.vendor>
