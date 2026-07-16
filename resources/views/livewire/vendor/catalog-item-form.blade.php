<div class="mx-auto space-y-6">

    {{-- Page header --}}
    <div class="px-6 py-5 bg-white border border-gray-200 shadow-sm rounded-xl">
        <div class="flex items-center gap-3">
            <div class="flex items-center justify-center flex-shrink-0 w-10 h-10 rounded-lg bg-sky-50">
                <svg class="w-5 h-5 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                    stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375C2.754 3.75 2.25 4.254 2.25 4.875v1.5c0 .621.504 1.125 1.125 1.125z" />
                </svg>
            </div>
            <div>
                <h1 class="text-xl font-semibold text-gray-900">
                    {{ $catalogItemId ? 'Edit Catalog Item' : 'Add Catalog Item' }}
                </h1>
                <p class="text-sm text-gray-500 mt-0.5">
                    Fields marked <span class="font-medium text-red-600">*</span> are required.
                </p>
            </div>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Identification --}}
        <section class="overflow-hidden bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">1</span>
                <h2 class="font-semibold text-gray-900">Catalog &amp; Identification</h2>
            </div>
            <div class="p-6 space-y-5">
                <div>
                    <label>Product Name <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="name"
                        class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                    @error('name')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label>Vendor SKU <span class="text-red-600">*</span></label>
                        <input type="text" wire:model="vendorSku"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        <p class="text-xs text-gray-400 mt-1.5">Must be unique across your catalog.</p>
                        @error('vendorSku')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Manufacturer SKU</label>
                        <input type="text" wire:model="manufacturerSku"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        @error('manufacturerSku')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- Product Type --}}
        <section class="overflow-hidden bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">2</span>
                <h2 class="font-semibold text-gray-900">Product Type</h2>
            </div>
            <div class="p-6">
                <div>
                    <label>Product Type <span class="text-red-600">*</span></label>
                    <select wire:model="productType"
                        class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                        <option value="">Select...</option>
                        @foreach ($this->commodityTypeOptions as $option)
                            <option value="{{ $option->name }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                    @error('productType')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </section>

        {{-- Description --}}
        <section class="overflow-hidden bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">3</span>
                <h2 class="font-semibold text-gray-900">Description</h2>
            </div>
            <div class="p-6 space-y-5">
                <div>
                    <label>Description <span class="text-red-600">*</span></label>
                    <textarea wire:model="description" rows="4"
                        class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1"></textarea>
                    @error('description')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label>Unit of Measure <span class="text-red-600">*</span></label>
                        <select wire:model="unitOfMeasure"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                            <option value="">Select...</option>
                            @foreach ($this->unitOfMeasureOptions as $option)
                                <option value="{{ $option->code }}">{{ $option->code }} — {{ $option->description }}
                                </option>
                            @endforeach
                        </select>
                        @error('unitOfMeasure')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Quantity per Unit</label>
                        <input type="number" step="0.01" wire:model="quantityPerUnit"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        @error('quantityPerUnit')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- Images --}}
        <section class="overflow-hidden bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">4</span>
                <h2 class="font-semibold text-gray-900">Product Images <span class="font-normal text-red-600">*</span>
                </h2>
            </div>
            <div class="p-6 space-y-4">
                <p class="text-xs text-gray-400">Upload actual image files (Primary first). These are embedded directly
                    into the delivered Excel file.</p>

                @if (count($existingImages))
                    <div class="flex flex-wrap gap-3">
                        @foreach ($existingImages as $image)
                            <div class="relative group">
                                <img src="{{ $image['url'] }}"
                                    class="object-cover w-24 h-24 border border-gray-200 rounded-lg">
                                <button type="button" wire:click="removeExistingImage({{ $image['id'] }})"
                                    class="absolute w-5 h-5 text-xs leading-5 text-white transition-colors bg-red-600 rounded-full shadow-sm -top-2 -right-2 hover:bg-red-700">&times;</button>
                            </div>
                        @endforeach
                    </div>
                @endif

                <label
                    class="flex flex-col items-center justify-center gap-2 px-6 py-8 text-center transition-colors border-2 border-gray-300 border-dashed rounded-lg cursor-pointer hover:border-sky-400 hover:bg-sky-50/40">
                    <svg class="w-8 h-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                    <span class="text-sm text-gray-600"><span class="font-medium text-sky-600">Click to upload</span> or
                        drag and drop images</span>
                    <input type="file" wire:model="newImages" multiple accept="image/*" class="hidden" />
                </label>
                @error('newImages')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
                @error('newImages.*')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror

                @if ($newImages)
                    <div class="flex flex-wrap gap-3">
                        @foreach ($newImages as $upload)
                            <img src="{{ $upload->temporaryUrl() }}"
                                class="object-cover w-24 h-24 border border-gray-200 rounded-lg">
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        {{-- Manufacturer / Brand / Search --}}
        <section class="overflow-hidden bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">5</span>
                <h2 class="font-semibold text-gray-900">Manufacturer &amp; Discovery</h2>
            </div>
            <div class="p-6 space-y-5">
                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label>Manufacturer's Name</label>
                        <input type="text" wire:model="manufacturerName"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        @error('manufacturerName')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Brand Name</label>
                        <input type="text" wire:model="brandName"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        @error('brandName')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label>Search Terms/Keywords <span class="text-red-600">*</span></label>
                    <div class="space-y-2 mt-1.5">
                        @foreach ($searchTerms as $index => $term)
                            <div class="flex gap-2">
                                <input type="text" wire:model="searchTerms.{{ $index }}"
                                    class="block w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                                @if (count($searchTerms) > 1)
                                    <button type="button" wire:click="removeSearchTerm({{ $index }})"
                                        class="flex-shrink-0 px-3 text-sm text-red-600 transition-colors rounded-lg hover:bg-red-50">Remove</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <button type="button" wire:click="addSearchTerm"
                        class="inline-flex items-center gap-1 mt-2 text-sm font-medium text-sky-600 hover:text-sky-700">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Add another term
                    </button>
                    @error('searchTerms')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </section>

        {{-- Pricing --}}
        <section class="overflow-hidden bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">6</span>
                <h2 class="font-semibold text-gray-900">Pricing &amp; Weight</h2>
            </div>
            <div class="p-6">
                <div class="grid gap-5 md:grid-cols-3">
                    <div>
                        <label>List Price/MSRP <span class="text-red-600">*</span></label>
                        <div class="relative mt-1.5">
                            <span
                                class="absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">$</span>
                            <input type="number" step="0.01" wire:model="listPrice"
                                class="block w-full pl-6 text-sm border-gray-300 rounded-lg shadow-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                        </div>
                        @error('listPrice')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Selling Price per Unit <span class="text-red-600">*</span></label>
                        <div class="relative mt-1.5">
                            <span
                                class="absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">$</span>
                            <input type="number" step="0.01" wire:model="sellingPrice"
                                class="block w-full pl-6 text-sm border-gray-300 rounded-lg shadow-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                        </div>
                        <p class="text-xs text-gray-400 mt-1.5">Cannot exceed List Price.</p>
                        @error('sellingPrice')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Weight (lbs) <span class="text-red-600">*</span></label>
                        <input type="number" step="0.01" wire:model="weight"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        @error('weight')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- Selling Points & Specifications --}}
        <section class="overflow-hidden bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">7</span>
                <h2 class="font-semibold text-gray-900">Selling Points &amp; Specifications</h2>
            </div>
            <div class="p-6 space-y-6">
                <div>
                    <label>Selling Points <span class="text-red-600">*</span></label>
                    <div class="space-y-2 mt-1.5">
                        @foreach ($sellingPoints as $index => $point)
                            <div class="flex gap-2">
                                <input type="text" wire:model="sellingPoints.{{ $index }}"
                                    class="block w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                                @if (count($sellingPoints) > 1)
                                    <button type="button" wire:click="removeSellingPoint({{ $index }})"
                                        class="flex-shrink-0 px-3 text-sm text-red-600 transition-colors rounded-lg hover:bg-red-50">Remove</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <button type="button" wire:click="addSellingPoint"
                        class="inline-flex items-center gap-1 mt-2 text-sm font-medium text-sky-600 hover:text-sky-700">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Add another selling point
                    </button>
                    @error('sellingPoints')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label>Product Attributes / Specifications <span class="text-red-600">*</span></label>
                    <div class="space-y-2 mt-1.5">
                        @foreach ($specifications as $index => $spec)
                            <div class="flex gap-2">
                                <input type="text" wire:model="specifications.{{ $index }}.key"
                                    placeholder="e.g. Color"
                                    class="block w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                                <input type="text" wire:model="specifications.{{ $index }}.value"
                                    placeholder="e.g. Red"
                                    class="block w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                                @if (count($specifications) > 1)
                                    <button type="button" wire:click="removeSpecification({{ $index }})"
                                        class="flex-shrink-0 px-3 text-sm text-red-600 transition-colors rounded-lg hover:bg-red-50">Remove</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <button type="button" wire:click="addSpecification"
                        class="inline-flex items-center gap-1 mt-2 text-sm font-medium text-sky-600 hover:text-sky-700">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Add another attribute
                    </button>
                    @error('specifications')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </section>

        {{-- Classifications --}}
        <section class="overflow-hidden bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">8</span>
                <h2 class="font-semibold text-gray-900">Classifications</h2>
            </div>
            <div class="p-6 space-y-6">
                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label>UNSPSC Code <span class="text-red-600">*</span></label>
                        <input type="text" wire:model="unspscCode"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        <p class="text-xs text-gray-400 mt-1.5">
                            Don't know it? <a href="https://www.unspsc.org/search-code" target="_blank"
                                class="underline text-sky-600 hover:text-sky-700">Look it up here</a>.
                        </p>
                        @error('unspscCode')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>MSDS Link (Hazmat)</label>
                        <input type="url" wire:model="msdsLink"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        @error('msdsLink')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label>Additional Classifications <span class="font-normal text-gray-400">(optional)</span></label>
                    <div class="space-y-2 mt-1.5">
                        @foreach ($classifications as $index => $classification)
                            <div class="flex gap-2">
                                <input type="text" wire:model="classifications.{{ $index }}"
                                    class="block w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                                @if (count($classifications) > 1)
                                    <button type="button" wire:click="removeClassification({{ $index }})"
                                        class="flex-shrink-0 px-3 text-sm text-red-600 transition-colors rounded-lg hover:bg-red-50">Remove</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <button type="button" wire:click="addClassification"
                        class="inline-flex items-center gap-1 mt-2 text-sm font-medium text-sky-600 hover:text-sky-700">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Add another
                    </button>
                    @error('classifications')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </section>

        {{-- Ordering --}}
        <section class="overflow-hidden bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">9</span>
                <h2 class="font-semibold text-gray-900">Order Quantities</h2>
            </div>
            <div class="p-6">
                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label>Minimum Order Qty</label>
                        <input type="number" step="0.01" wire:model="minOrderQuantity"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        @error('minOrderQuantity')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Maximum Order Qty</label>
                        <input type="number" step="0.01" wire:model="maxOrderQuantity"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        @error('maxOrderQuantity')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        </section>

        <div class="flex justify-end mt-2">
            <button class="btn btn-primary" type="submit">
                {{ $catalogItemId ? 'Save Changes' : 'Create Item' }}
            </button>
        </div>

    </form>

</div>
