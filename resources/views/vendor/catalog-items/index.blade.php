<x-layouts.vendor title="Catalog Items">
    <div class="space-y-6">
        <div class="flex flex-col gap-4 md:items-center md:justify-between md:flex-row">
            <div>
                <h1 class="text-xl font-semibold">Catalog Items</h1>
                <p class="text-sm text-gray-500">{{ $vendor->name }} - {{ $catalog->name }}</p>
            </div>
            <div class="flex items-center justify-end w-full gap-2 md:w-auto">
                <a href="{{ route('vendor.catalog.index') }}" class="w-full md:w-auto btn btn-gray">
                    <i class="fas fa-arrow-left"></i>
                    Back to Catalogs
                </a>
                <a href="{{ route('vendor.catalog-upload', ['catalogId' => $catalog->id]) }}" class="w-full text-white md:w-auto btn btn-gray">
                    <i class="fas fa-upload"></i>
                    Batch Upload
                </a>
                <a href="{{ route('vendor.catalog.create', ['catalog' => $catalog->id]) }}"
                    class="w-full md:w-auto btn btn-primary">
                    <i class="fas fa-plus"></i>
                    Add Item
                </a>
            </div>
        </div>

        {{-- Catalog submission status and button --}}
        @livewire('vendor.catalog-submission-button')

        <div class="p-2 bg-white rounded-lg shadow-lg">
            <form method="GET" action="{{ route('vendor.catalog.items', ['catalog' => $catalog->id]) }}"
                class="px-4 py-8 mb-4">
                <div class="gap-4 mb-4 md:flex">
                    <div class="relative flex-1">
                        <i
                            class="absolute text-gray-400 -translate-y-1/2 pointer-events-none fas fa-search left-3 top-5 md:top-6"></i>
                        <input type="text" name="search" value="{{ request('search') }}"
                            placeholder="Search by product name or sku..."
                            class="w-full py-2 pr-8 text-sm border border-gray-300 rounded-md pl-9 focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500" />
                        @if (request('search'))
                            <button type="button"
                                onclick="this.closest('form').querySelector('[name=search]').value=''; this.closest('form').querySelector('[name=status]').value=''; this.closest('form').submit();"
                                class="absolute p-1 text-gray-400 transition -translate-y-1/2 rounded right-2 top-1/2 hover:text-gray-600 hover:bg-gray-100">
                                <i class="text-xs fas fa-times"></i>
                            </button>
                        @endif
                    </div>

                    <select name="status"
                        class="py-2 pl-3 pr-8 text-sm text-gray-700 border border-gray-300 rounded-md focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500 sm:w-48">
                        <option value="">All Status</option>
                        <option value="incomplete" @selected(request('status') === 'incomplete')>
                            Incomplete
                        </option>
                        <option value="acceptable" @selected(request('status') === 'acceptable')>
                            Acceptable
                        </option>
                        <option value="excellent" @selected(request('status') === 'excellent')>
                            Excellent
                        </option>
                    </select>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="submit" class="py-1.5 btn btn-success">
                        Apply Filters
                    </button>

                    @if (request('search') || request('status'))
                        <a href="{{ route('vendor.catalog.items', ['catalog' => $catalog->id]) }}"
                            class="w-20 py-1.5 btn btn-gray">
                            Clear
                        </a>
                    @endif
                </div>
            </form>

            <div class="relative">
                @if ($items->isEmpty())
                    <div class="py-16 text-center">
                        <i class="text-4xl text-gray-300 fas fa-search"></i>
                        <h3 class="mt-3 text-sm font-semibold text-gray-900">No catalog items found</h3>
                        <p class="mt-1 text-sm text-gray-500">Try adjusting your search or filters.</p>
                        @if (request('search') || request('status'))
                            <a href="{{ route('vendor.catalog.items', ['catalog' => $catalog->id]) }}"
                                class="inline-flex items-center gap-1.5 px-4 py-2 mt-4 text-sm font-medium text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50">
                                <i class="fas fa-times"></i>
                                Clear filters
                            </a>
                        @endif
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 whitespace-nowrap">
                            <thead class="bg-gray-50 ">
                                <tr>
                                    <th
                                        class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                        Product</th>
                                    <th
                                        class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                        Seller SKU</th>
                                    <th
                                        class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                        Status</th>
                                    <th
                                        class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach ($items as $item)
                                    <tr class="text-sm hover:bg-gray-50 whitespace-nowrap">
                                        <td class="px-6 py-4">
                                            <div class="font-medium">{{ $item->name }}</div>
                                        </td>
                                        <td class="px-6 py-4 text-gray-500">{{ $item->dealer_sku }}</td>
                                        <td class="px-6 py-4">
                                            @php $status = $item->status ?? 'incomplete'; @endphp
                                            <span @class([
                                                'inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold leading-5 rounded-full',
                                                'text-rose-700 bg-rose-100' => $status === 'incomplete',
                                                'text-amber-700 bg-amber-100' => $status === 'acceptable',
                                                'text-emerald-700 bg-emerald-100' => $status === 'excellent',
                                            ])>
                                                @if ($status === 'excellent')
                                                    <i class="fas fa-circle-check"></i>
                                                @endif
                                                @if ($status === 'incomplete')
                                                    <i class="fas fa-circle-exclamation"></i>
                                                @endif
                                                <span>{{ $status }}</span>
                                                @if ($item->completeness_score > 0)
                                                    <span class="opacity-60">({{ $item->completeness_score }}%)</span>
                                                @endif
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 space-x-3">
                                            <a href="{{ route('vendor.catalog.edit', $item) }}"
                                                class="text-sky-600 hover:text-sky-800">Edit</a>
                                            <form method="POST" action="{{ route('vendor.catalog.destroy', $item) }}"
                                                class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:text-red-800"
                                                    onclick="return confirm('Delete this catalog item?')">Delete</button>
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
        </div>
    </div>

    <script>
        document.querySelector('form[action$="catalog"]')?.addEventListener('submit', function() {
            document.getElementById('catalog-loading-overlay').classList.remove('hidden');
            document.getElementById('catalog-loading-overlay').classList.add('flex');
        });
    </script>
</x-layouts.vendor>
