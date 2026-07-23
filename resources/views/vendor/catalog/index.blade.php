<x-layouts.vendor title="My Catalogs">
    <div class="space-y-6">
        <div class="flex flex-col items-center gap-3 md:justify-between md:flex-row">
            <div>
                <h1 class="text-xl font-semibold">My Catalogs</h1>
                <p class="text-sm text-gray-500">{{ $vendor->name }}</p>
            </div>
            <div class="flex items-center w-full gap-2 md:w-auto">
                <a href="{{ route('vendor.catalog-upload') }}"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white transition rounded-lg bg-slate-900 hover:bg-slate-800">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 7.5 12 3m0 0L7.5 7.5M12 3v13.5" />
                    </svg>
                    Batch Upload
                </a>
                <a href="{{ route('vendor.catalog.create') }}"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white transition rounded-lg bg-emerald-600 hover:bg-emerald-700">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Add Item
                </a>
            </div>
        </div>

        {{-- Catalog submission status and button --}}
        @livewire('vendor.catalog-submission-button')

        @if ($items !== null)
            <div class="p-2 bg-white rounded-lg shadow-lg">
                @if ($items->isEmpty())
                    <p class="p-4 text-sm text-gray-500">No items in this catalog.</p>
                @else
                    <form method="GET" action="{{ route('vendor.catalog.index') }}"
                        class="flex flex-col gap-3 px-4 py-8 mb-4 sm:flex-row sm:items-end">
                        <div class="relative flex-1">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor"
                                class="absolute w-4 h-4 text-gray-400 -translate-y-1/2 pointer-events-none left-3 top-1/2">
                                <circle cx="11" cy="11" r="8" stroke-width="2" />
                                <path d="m21 21-4.35-4.35" stroke-width="2" stroke-linecap="round" />
                            </svg>
                            <input type="text" name="search" value="{{ request('search') }}"
                                placeholder="Search by product name or sku..."
                                class="w-full py-2 pr-3 text-sm border border-gray-300 rounded-md pl-9 focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500" />
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

                        <div class="flex gap-2">
                            <button type="submit"
                                class="px-4 py-2 text-sm font-medium text-white rounded-md bg-sky-600 hover:bg-sky-500">
                                Apply
                            </button>

                            @if (request('search') || request('status'))
                                <a href="{{ route('vendor.catalog.index') }}"
                                    class="px-4 py-2 text-sm font-medium text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50">
                                    Clear
                                </a>
                            @endif
                        </div>
                    </form>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 whitespace-nowrap">
                            <thead class="bg-gray-50 ">
                                <tr>
                                    <th
                                        class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                        Image</th>
                                    <th
                                        class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                        Product</th>
                                    <th
                                        class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                        Vendor Part #</th>
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
                                            @if ($item->images->isNotEmpty())
                                                <img src="{{ route('vendor.catalog-images.show', $item->images->first()) }}"
                                                    class="object-cover w-12 h-12 border rounded">
                                            @endif
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="font-medium">{{ $item->name }}</div>
                                        </td>
                                        <td class="px-6 py-4 text-gray-500">{{ $item->vendor_sku }}</td>
                                        <td class="px-6 py-4">
                                            @php $status = $item->status ?? 'incomplete'; @endphp
                                            <span @class([
                                                'inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold leading-5 rounded-full',
                                                'text-rose-700 bg-rose-100' => $status === 'incomplete',
                                                'text-amber-700 bg-amber-100' => $status === 'acceptable',
                                                'text-emerald-700 bg-emerald-100' => $status === 'excellent',
                                            ])>
                                                @if ($status === 'excellent')
                                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd"
                                                            d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z"
                                                            clip-rule="evenodd" />
                                                    </svg>
                                                @endif
                                                @if ($status === 'incomplete')
                                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24"
                                                        stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                                                    </svg>
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
        @endif
    </div>
</x-layouts.vendor>
