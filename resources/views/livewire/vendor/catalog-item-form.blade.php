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
                <p class="mt-1 text-sm text-gray-600">
                    Fields marked <span class="font-medium text-red-600">*</span> are required for submission.
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
                <h2 class="font-semibold text-gray-900">Product Identification</h2>
            </div>
            <div class="p-6 space-y-8">
                <div>
                    <label>Product Name <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="name"
                        class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                    <p class="text-sm text-gray-500 mt-1.5">Enter the customer facing product title. Example: Premium
                        2-Ply Bath Tissue, 48 Rolls.</p>
                    @error('name')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid gap-8 md:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label>Seller SKU <span class="text-red-600">*</span></label>
                        <input type="text" wire:model="sellerSku"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        <p class="text-sm text-gray-500 mt-1.5">Must be unique across your catalog.</p>
                        @error('sellerSku')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Manufacturer SKU</label>
                        <input type="text" wire:model="manufacturerSku"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        <p class="text-sm text-gray-500 mt-1.5">Manufacturer part number from packaging or spec sheet.
                            Example: GP-27120.</p>
                        @error('manufacturerSku')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Replacement SKUs</label>
                        <p class="text-sm text-gray-500 mt-1.5">Use when this item replaces other Seller SKUs. Add up to
                            four values.</p>
                        <div class="space-y-2 mt-1.5">
                            @foreach ($replacementSkus as $index => $sku)
                                <div class="flex gap-2">
                                    <input type="text" wire:model="replacementSkus.{{ $index }}"
                                        class="block w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                                    @if (count($replacementSkus) > 1)
                                        <button type="button" wire:click="removeReplacementSku({{ $index }})"
                                            class="flex-shrink-0 px-3 text-sm text-red-600 transition-colors rounded-lg hover:bg-red-50">Remove</button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        @error('replacementSkus')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                        @error('replacementSkus.*')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                        @if (count($replacementSkus) < 4)
                            <button type="button" wire:click="addReplacementSku"
                                class="inline-flex items-center gap-1 mt-2 text-sm font-medium text-sky-600 hover:text-sky-700">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                    stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                                Add another SKU
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        {{-- Product Category --}}
        <section class="bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">2</span>
                <h2 class="font-semibold text-gray-900">Product Category</h2>
            </div>
            <div class="p-6">
                <div class="grid gap-8 lg:grid-cols-2">
                    <div>
                        <label>Product Category <span class="text-red-600">*</span></label>
                        <x-searchable-select wire-model="productCategory" :options="$this->commodityTypeOptions->map(
                            fn($o) => ['value' => $o->id, 'label' => $o->name],
                        )" :allow-create="true"
                            placeholder="Select a product type..." />

                        <p class="text-sm text-gray-500 mt-1.5">Pick the broad commodity group for reporting and search.
                        </p>
                        @error('productCategory')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Hierarchy <span class="text-red-600">*</span></label>
                        <x-searchable-select wire-model="hierarchy" :options="$this->hierarchyOptions->map(
                            fn($h) => ['value' => $h->hierarchy_number, 'label' => $h->name],
                        )" :allow-create="false"
                            placeholder="Select a product hierarchy..." />

                        <p class="text-sm text-gray-500 mt-1.5">Choose the most specific category path for this item.
                        </p>
                        @error('hierarchy')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
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
            <div class="p-6">
                <div>
                    <label>Description <span class="text-red-600">*</span></label>
                    <textarea wire:model="description" rows="4"
                        class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1"></textarea>
                    <p class="text-sm text-gray-500 mt-1.5">This is the full product description with key information
                        that would help the buyers to want to
                        buy this product.</p>
                    @error('description')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
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
                <p class="text-xs text-gray-500">Upload actual image files (Primary first). These are embedded directly
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
                    <span class="text-sm text-gray-600"><span class="font-medium text-sky-600">Click to upload</span>
                        or
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
            <div class="p-6 space-y-8">
                <div class="grid gap-8 md:grid-cols-2">
                    <div>
                        <label>Manufacturer's Name</label>
                        <input type="text" wire:model="manufacturer"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        <p class="text-sm text-gray-500 mt-1.5">Brand owner or producer. Example: PepsiCo.</p>
                        @error('manufacturer')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Brand Name</label>
                        <input type="text" wire:model="brandName"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        <p class="text-sm text-gray-500 mt-1.5">Customer recognizable brand. Example: Gatorade.</p>
                        @error('brandName')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label>Search Terms/Keywords <span class="text-red-600">*</span></label>
                    <p class="text-sm text-gray-500 mt-1.5">Add words buyers might search. Examples: energy drink,
                        electrolytes, hydration, sugar free.</p>
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
                <h2 class="font-semibold text-gray-900">Pricing</h2>
            </div>
            <div class="p-6">
                <div class="grid gap-8 md:grid-cols-2">
                    <div>
                        <label>List Price/MSRP <span class="text-red-600">*</span></label>
                        <div class="relative mt-1.5">
                            <span
                                class="absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">$</span>
                            <input type="number" step="0.01" wire:model="listPrice"
                                class="block w-full pl-6 text-sm border-gray-300 rounded-lg shadow-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                        </div>
                        <p class="text-sm text-gray-500 mt-1.5">Published or suggested retail price per sellable unit.
                        </p>
                        @error('listPrice')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Selling Price per Unit <span class="text-red-600">*</span></label>
                        <div class="relative mt-1.5">
                            <span
                                class="absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">$</span>
                            <input type="number" step="0.01" wire:model="sellingPricePerUnit"
                                class="block w-full pl-6 text-sm border-gray-300 rounded-lg shadow-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                        </div>
                        <p class="text-sm text-gray-500 mt-1.5">Actual customer price per unit. Cannot exceed List
                            Price.</p>
                        @error('sellingPricePerUnit')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- Shipping --}}
        <section class="overflow-hidden bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">7</span>
                <h2 class="font-semibold text-gray-900">Shipping</h2>
            </div>
            <div class="p-6">
                <div class="grid gap-8 md:grid-cols-2">
                    <div>
                        <label>Shipping Weight (lbs) <span class="text-red-600">*</span></label>
                        <input type="number" step="0.01" wire:model="itemWeight"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        <p class="text-sm text-gray-500 mt-1.5">Weight of one sellable unit in pounds. Example: 12.5.
                        </p>
                        @error('itemWeight')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Shipping Lead Time</label>
                        <select wire:model="leadTime"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                            <option value="">Select an option</option>
                            <option value="0-3 days">0-3 days</option>
                            <option value="3-5 days">3-5 days</option>
                            <option value="5-10 days">5-10 days</option>
                            <option value="10 & over">10 & over</option>
                        </select>
                        <p class="text-sm text-gray-500 mt-1.5">Typical time for order to be shipped.</p>
                        @error('leadTime')
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
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">8</span>
                <h2 class="font-semibold text-gray-900">Selling Points &amp; Specifications</h2>
            </div>
            <div class="p-6 space-y-6">
                <div>
                    <label>Selling Points <span class="text-red-600">*</span></label>
                    <p class="text-sm text-gray-500 mt-1.5">Short benefit statements. Example: Soft texture, high
                        absorbency, reduced roll changes.</p>
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
                    <p class="text-sm text-gray-500 mt-1.5">Product attributes such as size, dimensions,
                        color, weight, materials, and other product
                        specifications. Example Color -> Red, Size -> Small</p>
                    <div class="space-y-2 mt-1.5">
                        @foreach ($specifications as $index => $spec)
                            <div class="flex gap-2">
                                <input type="text" wire:model="specifications.{{ $index }}.key"
                                    placeholder="Attribute e.g. Color"
                                    class="block w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                                <input type="text" wire:model="specifications.{{ $index }}.value"
                                    placeholder="Value e.g. Red"
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
        <section class="bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">9</span>
                <h2 class="font-semibold text-gray-900">Classifications</h2>
            </div>
            <div class="p-6 space-y-6">
                <div class="grid gap-8 md:grid-cols-2">
                    <div>
                        <label>UNSPSC Code <span class="text-red-600">*</span></label>
                        <input type="text" wire:model="unspscCode"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        <p class="text-sm text-gray-500 mt-1.5">
                            Don't know it? <a href="https://www.ungm.org/public/unspsc" target="_blank"
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
                        <p class="text-sm text-gray-500 mt-1.5">Provide a public safety data sheet URL when applicable.
                        </p>
                        @error('msdsLink')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label>Additional Classifications <span class="font-normal text-gray-400">(optional)</span></label>
                    <p class="text-sm text-gray-500 mt-1.5">Select a classification type, then provide its value.
                        Example: UPC_RTL = 012345678905.</p>
                    <div class="space-y-2 mt-1.5">
                        @foreach ($classifications as $index => $classification)
                            @php
                                $selectedType = $classification['type'] ?? '';
                                $selectedTypesInOtherRows = collect($classifications)
                                    ->map(fn($row, $i) => $i === $index ? null : $row['type'] ?? null)
                                    ->filter();
                            @endphp
                            <div class="flex gap-2">
                                <x-searchable-select wire-model="classifications.{{ $index }}.type"
                                    class="flex-1" :options="$this->classificationTypeOptions
                                        ->filter(
                                            fn($t) => !$selectedTypesInOtherRows->contains($t->key) ||
                                                $selectedType === $t->key,
                                        )
                                        ->map(fn($t) => ['value' => $t->key, 'label' => $t->label])
                                        ->values()
                                        ->toArray()" :allow-create="false"
                                    placeholder="Select an option..." />

                                <input type="text" wire:model="classifications.{{ $index }}.value"
                                    placeholder="Enter value"
                                    class="block w-1/2 text-sm border-gray-300 rounded-lg shadow-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                                @if (count($classifications) > 1)
                                    <button type="button" wire:click="removeClassification({{ $index }})"
                                        class="flex-shrink-0 px-3 text-sm text-red-600 transition-colors rounded-lg hover:bg-red-50">Remove</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @error('classifications.*.type')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
                    @error('classifications.*.value')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
                    @error('classifications')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
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
        <section class="bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">10</span>
                <h2 class="font-semibold text-gray-900">Inventory &amp; Order Quantities</h2>
            </div>
            <div class="p-6 space-y-8">

                <div>
                    <label>Available Stock Count</label>
                    <input type="number" step="1" min="0" wire:model="availability"
                        class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                    <p class="text-sm text-gray-500 mt-1.5">Units currently available to sell. Example: 240.</p>
                    @error('availability')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid gap-8 md:grid-cols-2">
                    <div>
                        <label>Unit of Measure <span class="text-red-600">*</span></label>
                        <x-searchable-select wire-model="unitOfMeasure" :options="$this->unitOfMeasureOptions->map(
                            fn($m) => ['value' => $m->code, 'label' => $m->code . ' - ' . $m->description],
                        )" :allow-create="true"
                            placeholder="Select an option..." />
                        <p class="text-sm text-gray-500 mt-1.5">How the item is sold. Example: EA (each), BX (box), CS
                            (case).</p>
                        @error('unitOfMeasure')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Quantity per Unit</label>
                        <input type="number" step="1" min="0" wire:model="quantityPerUnit"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        <p class="text-sm text-gray-500 mt-1.5">Count contained in one unit of measure. Example: 24 if
                            one case contains 24 packs.</p>
                        @error('quantityPerUnit')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="grid gap-8 md:gap-8 md:grid-cols-2 lg:grid-cols-3">

                    <div>
                        <label>Minimum Order Qty</label>
                        <input type="number" step="1" min="0" wire:model="minQtyPerOrder"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        <p class="text-sm text-gray-500 mt-1.5">Lowest quantity accepted per order. Example: 1.</p>
                        @error('minQtyPerOrder')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Maximum Order Qty</label>
                        <input type="number" step="1" min="0" wire:model="maxQtyPerOrder"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        <p class="text-sm text-gray-500 mt-1.5">Optional cap per order. Example: 500.</p>
                        @error('maxQtyPerOrder')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Multiples</label>
                        <input type="number" step="1" min="0" wire:model="multiples"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                        <p class="text-sm text-gray-500 mt-1.5">Order increment. Example: 6 means orders must be 6, 12,
                            18, etc.</p>
                        @error('multiples')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        </section>

        <div class="flex justify-end mt-2">
            <button type="submit" class="btn btn-primary">
                {{ $catalogItemId ? 'Save Changes' : 'Create Item' }}
            </button>
        </div>

    </form>
</div>
