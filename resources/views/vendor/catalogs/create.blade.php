<x-layouts.vendor title="Create Catalog">
    <div class="max-w-2xl mx-auto space-y-6">
        <div class="p-6 bg-white border border-gray-200 shadow-sm rounded-xl">
            <h1 class="text-xl font-semibold text-gray-900">Create Catalog</h1>
            <p class="mt-1 text-sm text-gray-600">Step 1: create a catalog. Step 2: add items to that catalog.</p>

            <form method="POST" action="{{ route('vendor.catalogs.store') }}" class="mt-6 space-y-4">
                @csrf

                <div>
                    <label for="catalog_name" class="block text-sm font-medium text-gray-700">Catalog Name</label>
                    <input id="catalog_name" name="name" type="text" value="{{ old('name') }}"
                        placeholder="Example: General"
                        class="w-full mt-1.5 rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500"
                        required />
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="btn btn-primary">Create Catalog</button>
                    <a href="{{ route('vendor.catalog.index') }}" class="btn btn-gray">Back</a>
                </div>
            </form>
        </div>
    </div>
</x-layouts.vendor>
