<x-layouts.vendor title="Add Catalog Item">
    @isset($catalog)
        <div class="mb-4 text-sm text-gray-600">
            Adding item to: <span class="font-medium text-gray-900">{{ $catalog->name }}</span>
        </div>
    @endisset
    @livewire('vendor.catalog-item-form', ['catalogId' => $catalogId])
</x-layouts.vendor>
