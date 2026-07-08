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
                    Hover the <span
                        class="underline decoration-dotted decoration-gray-400 underline-offset-2">underlined</span>
                    labels for guidance.
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
                    <label>Vendor Catalog Name <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="catalogName" placeholder="e.g. XYZ Company-General Catalog" />
                    @error('catalogName')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-2 gap-5">
                    <div>
                        <label>Vendor Part Number <span class="text-red-600">*</span></label>
                        <input type="text" wire:model="vendorPartNumber" />
                        <p class="text-xs text-gray-400 mt-1.5">Must be unique. Also populates Customer/Search/Vendor
                            SKU internally.</p>
                        @error('vendorPartNumber')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Manufacturer's Part Number <span class="text-red-600">*</span></label>
                        <input type="text" wire:model="manufacturerPartNumber" />
                        @error('manufacturerPartNumber')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- Hierarchy & Commodity --}}
        <section class="overflow-hidden bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">2</span>
                <h2 class="font-semibold text-gray-900">Category &amp; Commodity</h2>
            </div>
            <div class="p-6 space-y-5">
                <div class="grid grid-cols-3 gap-5">
                    <div>
                        <label>Category Level 1 <span class="text-red-600">*</span></label>
                        <select wire:model.live="categoryLevel1Id">
                            <option value="">Select...</option>
                            @foreach ($this->level1Options as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        @error('categoryLevel1Id')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Category Level 2 <span class="text-red-600">*</span></label>
                        <select wire:model.live="categoryLevel2Id" @if (!$categoryLevel1Id) disabled @endif
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1 disabled:bg-gray-50 disabled:text-gray-400">
                            <option value="">Select...</option>
                            @foreach ($this->level2Options as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        @error('categoryLevel2Id')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Category Level 3 <span class="text-red-600">*</span></label>
                        <select wire:model="categoryLevel3Id" @if (!$categoryLevel2Id) disabled @endif
                            class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1 disabled:bg-gray-50 disabled:text-gray-400">
                            <option value="">Select...</option>
                            @foreach ($this->level3Options as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        @error('categoryLevel3Id')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                <p class="flex items-start gap-1.5 text-xs text-sky-700 bg-sky-50 rounded-lg px-3 py-2">
                    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                    </svg>
                    If this combination doesn't exist yet, our team is automatically notified to add it.
                </p>

                <div>
                    <label>Product Commodity Type <span class="text-red-600">*</span></label>
                    <select wire:model="commodityType">
                        <option value="">Select...</option>
                        @foreach ($this->commodityTypeOptions as $option)
                            <option value="{{ $option->name }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                    @error('commodityType')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </section>

        {{-- Descriptions --}}
        <section class="overflow-hidden bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">3</span>
                <h2 class="font-semibold text-gray-900">Descriptions</h2>
            </div>
            <div class="p-6 space-y-5">
                <div>
                    <label>Short Description <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="shortDescription" />
                    <p class="text-xs text-gray-400 mt-1.5">Quantity + Unit of Measure will be appended automatically on
                        save.</p>
                    @error('shortDescription')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label>Long Description <span class="text-red-600">*</span></label>
                    <textarea wire:model="longDescription" rows="4"></textarea>
                    @error('longDescription')
                        <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-3 gap-5">
                    <div>
                        <label>Unit of Measure <span class="text-red-600">*</span></label>
                        <select wire:model="unitOfMeasure">
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
                        <label>Quantity in Unit of Measure <span class="text-red-600">*</span></label>
                        <input type="text" wire:model="quantity" placeholder="e.g. 10" />
                        @error('quantity')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Unit Type Label</label>
                        <input type="text" wire:model="quantityUnitType" placeholder="e.g. Reams" />
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
                    <span class="text-sm text-gray-600"><span class="font-medium text-sky-600">Click to upload</span>
                        or drag and drop images</span>
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
                <div class="grid grid-cols-2 gap-5">
                    <div>
                        <label>Manufacturer's Name <span class="text-red-600">*</span></label>
                        <input type="text" wire:model="manufacturerName" />
                        @error('manufacturerName')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Brand Name <span class="text-red-600">*</span></label>
                        <input type="text" wire:model="brandName" />
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
                <div class="grid grid-cols-3 gap-5">
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
                        <label>Item Weight (lbs) <span class="text-red-600">*</span></label>
                        <input type="number" step="0.01" wire:model="itemWeight" />
                        @error('itemWeight')
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
                <h2 class="font-semibold text-gray-900">Product Classifications</h2>
            </div>
            <div class="p-6 space-y-6">
                <div class="grid grid-cols-2 gap-5">
                    <div>
                        <label>UNSPSC Code <span class="text-red-600">*</span></label>
                        <input type="text" wire:model="unspscCode" />
                        <p class="text-xs text-gray-400 mt-1.5">
                            Don't know it? <a href="https://www.unspsc.org/search-code" target="_blank"
                                class="underline text-sky-600 hover:text-sky-700">Look it up here</a>.
                        </p>
                        @error('unspscCode')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Country of Origin <span class="text-red-600">*</span></label>
                        <select wire:model="countryOfOrigin">
                            <option value="">Select...</option>
                            @foreach ($this->countryOptions as $option)
                                <option value="{{ $option->code }}">{{ $option->name }} ({{ $option->code }})
                                </option>
                            @endforeach
                        </select>
                        @error('countryOfOrigin')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label class="block mb-2 text-sm font-medium text-gray-700">Additional Classifications <span
                            class="font-normal text-gray-400">(optional — check any that apply)</span></label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ($this->classificationOptions as $option)
                            <label
                                class="flex items-center gap-2 text-sm text-gray-700 rounded-lg border border-gray-200 px-3 py-2 cursor-pointer hover:border-sky-300 hover:bg-sky-50/40 transition-colors has-[:checked]:border-sky-400 has-[:checked]:bg-sky-50">
                                <input type="checkbox" wire:model="selectedClassifications"
                                    value="{{ $option->key }}"
                                    class="border-gray-300 rounded text-sky-600 focus:ring-sky-500">
                                {{ $option->label }}
                            </label>
                        @endforeach
                    </div>
                </div>

                @if (array_intersect(
                        [
                            'UPC_RTL',
                            'GTIN',
                            'MSDS URL',
                            'National Stock Number',
                            'NIGP Code',
                            'Warranty Information',
                            'Green_Indicator',
                        ],
                        $selectedClassifications))
                    <div class="p-4 space-y-4 border border-gray-200 rounded-lg bg-gray-50">
                        @if (in_array('UPC_RTL', $selectedClassifications))
                            <div>
                                <label>UPC <span class="text-red-600">*</span></label>
                                <input type="text" wire:model="upc" />
                                @error('upc')
                                    <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                        @if (in_array('GTIN', $selectedClassifications))
                            <div>
                                <label>GTIN <span class="text-red-600">*</span></label>
                                <input type="text" wire:model="gtin" />
                                @error('gtin')
                                    <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                        @if (in_array('MSDS URL', $selectedClassifications))
                            <div>
                                <label>MSDS Link (Hazmat) <span class="text-red-600">*</span></label>
                                <input type="url" wire:model="msdsLink" />
                                @error('msdsLink')
                                    <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                        @if (in_array('National Stock Number', $selectedClassifications))
                            <div>
                                <label>National Stock Number <span class="text-red-600">*</span></label>
                                <input type="text" wire:model="nationalStockNumber" />
                                @error('nationalStockNumber')
                                    <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                        @if (in_array('NIGP Code', $selectedClassifications))
                            <div>
                                <label>NIGP Code (5 or 7 digits) <span class="text-red-600">*</span></label>
                                <input type="text" wire:model="nigpCode" />
                                @error('nigpCode')
                                    <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                        @if (in_array('Warranty Information', $selectedClassifications))
                            <div>
                                <label>Warranty Indicator <span class="text-red-600">*</span></label>
                                <input type="text" wire:model="warrantyIndicator" />
                                @error('warrantyIndicator')
                                    <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                        @if (in_array('Green_Indicator', $selectedClassifications))
                            <div>
                                <label>Green Information <span class="text-red-600">*</span></label>
                                <input type="text" wire:model="greenInformation" />
                                @error('greenInformation')
                                    <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </section>

        {{-- Ordering / Lifecycle --}}
        <section class="overflow-hidden bg-white border border-gray-200 shadow-sm rounded-xl">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/60">
                <span
                    class="flex items-center justify-center flex-shrink-0 text-xs font-semibold text-white rounded-full w-7 h-7 bg-sky-600">9</span>
                <h2 class="font-semibold text-gray-900">Order Quantities &amp; Lifecycle</h2>
            </div>
            <div class="p-6 space-y-5">
                <div class="grid grid-cols-3 gap-5">
                    <div>
                        <label>Minimum Order Qty</label>
                        <input type="number" wire:model="minimumOrderQty" />
                        @error('minimumOrderQty')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Multiple Order Qty</label>
                        <input type="number" wire:model="multipleOrderQty" />
                        @error('multipleOrderQty')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label>Maximum Order Qty</label>
                        <input type="number" wire:model="maximumOrderQty" />
                        @error('maximumOrderQty')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <label
                    class="flex items-center gap-2 text-sm font-medium text-gray-700 rounded-lg border border-gray-200 px-3 py-2.5 cursor-pointer hover:bg-gray-50 w-fit">
                    <input type="checkbox" wire:model.live="discontinued"
                        class="border-gray-300 rounded text-sky-600 focus:ring-sky-500">
                    Discontinue this item
                </label>

                @if ($discontinued)
                    <div class="grid grid-cols-2 gap-5 p-4 border rounded-lg bg-amber-50 border-amber-200">
                        <div>
                            <label>Discontinued Date <span class="text-red-600">*</span></label>
                            <input type="date" wire:model="discontinuedDate" />
                            @error('discontinuedDate')
                                <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label>Replacement Part Number(s) <span class="text-red-600">*</span></label>
                            <div class="space-y-2 mt-1.5">
                                @foreach ($replacementPartNumbers as $index => $part)
                                    <div class="flex gap-2">
                                        <input type="text" wire:model="replacementPartNumbers.{{ $index }}"
                                            class="block w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                                        @if (count($replacementPartNumbers) > 1)
                                            <button type="button"
                                                wire:click="removeReplacementPartNumber({{ $index }})"
                                                class="flex-shrink-0 px-3 text-sm text-red-600 transition-colors rounded-lg hover:bg-red-100">Remove</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            <button type="button" wire:click="addReplacementPartNumber"
                                class="inline-flex items-center gap-1 mt-2 text-sm font-medium text-sky-600 hover:text-sky-700">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                    stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                                Add another
                            </button>
                            <p class="text-xs text-gray-500 mt-1.5">New replacement products must be added as their own
                                catalog entry first.</p>
                            @error('replacementPartNumbers')
                                <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                @endif
            </div>
        </section>

        {{-- Sticky action bar --}}
        <div
            class="sticky bottom-0 flex justify-end gap-3 px-6 py-4 -mx-6 border-t border-gray-200 md:mx-0 bg-white/90 backdrop-blur rounded-b-xl">
            <a href="{{ route('vendor.dashboard') }}"
                class="px-4 py-2 text-sm font-medium text-gray-600 transition-colors rounded-lg hover:bg-gray-100 hover:text-gray-900">Cancel</a>
            <button type="submit"
                class="px-6 py-2 text-sm font-medium text-white transition-colors rounded-lg shadow-sm bg-sky-600 hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2">
                {{ $catalogItemId ? 'Update Item' : 'Add Item' }}
            </button>
        </div>
    </form>
</div>
