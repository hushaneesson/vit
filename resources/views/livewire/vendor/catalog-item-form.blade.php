<div class="space-y-8">
    <div>
        <h1 class="text-xl font-semibold">{{ $catalogItemId ? 'Edit Catalog Item' : 'Add Catalog Item' }}</h1>
        <p class="text-sm text-gray-500">Fields marked <span class="text-red-600">*</span> are required. Hover the <span class="underline decoration-dotted">underlined</span> labels for guidance.</p>
    </div>

    <form wire:submit="save" class="space-y-10">
        {{-- Identification --}}
        <section class="bg-white shadow rounded-lg p-6 space-y-4">
            <h2 class="font-medium text-gray-900">Catalog & Identification</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700">Vendor Catalog Name <span class="text-red-600">*</span></label>
                <input type="text" wire:model="catalogName" placeholder="e.g. XYZ Company-General Catalog"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('catalogName') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Vendor Part Number <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="vendorPartNumber" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <p class="text-xs text-gray-400 mt-1">Must be unique. Also populates Customer/Search/Vendor SKU internally.</p>
                    @error('vendorPartNumber') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Manufacturer's Part Number <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="manufacturerPartNumber" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('manufacturerPartNumber') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- Hierarchy & Commodity --}}
        <section class="bg-white shadow rounded-lg p-6 space-y-4">
            <h2 class="font-medium text-gray-900">Category & Commodity</h2>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Category Level 1 <span class="text-red-600">*</span></label>
                    <select wire:model.live="categoryLevel1Id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">Select...</option>
                        @foreach ($this->level1Options as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                    @error('categoryLevel1Id') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Category Level 2 <span class="text-red-600">*</span></label>
                    <select wire:model.live="categoryLevel2Id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" @if(!$categoryLevel1Id) disabled @endif>
                        <option value="">Select...</option>
                        @foreach ($this->level2Options as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                    @error('categoryLevel2Id') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Category Level 3 <span class="text-red-600">*</span></label>
                    <select wire:model="categoryLevel3Id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" @if(!$categoryLevel2Id) disabled @endif>
                        <option value="">Select...</option>
                        @foreach ($this->level3Options as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                    @error('categoryLevel3Id') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="text-xs text-gray-400">If this combination doesn't exist yet, our team is automatically notified to add it.</p>

            <div>
                <label class="block text-sm font-medium text-gray-700">Product Commodity Type <span class="text-red-600">*</span></label>
                <select wire:model="commodityType" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="">Select...</option>
                    @foreach ($this->commodityTypeOptions as $option)
                        <option value="{{ $option->name }}">{{ $option->name }}</option>
                    @endforeach
                </select>
                @error('commodityType') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </section>

        {{-- Descriptions --}}
        <section class="bg-white shadow rounded-lg p-6 space-y-4">
            <h2 class="font-medium text-gray-900">Descriptions</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700">Short Description <span class="text-red-600">*</span></label>
                <input type="text" wire:model="shortDescription" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <p class="text-xs text-gray-400 mt-1">Quantity + Unit of Measure will be appended automatically on save.</p>
                @error('shortDescription') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Long Description <span class="text-red-600">*</span></label>
                <textarea wire:model="longDescription" rows="4" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
                @error('longDescription') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Unit of Measure <span class="text-red-600">*</span></label>
                    <select wire:model="unitOfMeasure" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">Select...</option>
                        @foreach ($this->unitOfMeasureOptions as $option)
                            <option value="{{ $option->code }}">{{ $option->code }} — {{ $option->description }}</option>
                        @endforeach
                    </select>
                    @error('unitOfMeasure') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Quantity in Unit of Measure <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="quantity" placeholder="e.g. 10" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('quantity') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Unit Type Label</label>
                    <input type="text" wire:model="quantityUnitType" placeholder="e.g. Reams" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                </div>
            </div>
        </section>

        {{-- Images --}}
        <section class="bg-white shadow rounded-lg p-6 space-y-4">
            <h2 class="font-medium text-gray-900">Product Images <span class="text-red-600">*</span></h2>
            <p class="text-xs text-gray-400">Upload actual image files (Primary first). These are embedded directly into the delivered Excel file.</p>

            @if (count($existingImages))
                <div class="flex flex-wrap gap-3">
                    @foreach ($existingImages as $image)
                        <div class="relative">
                            <img src="{{ $image['url'] }}" class="w-24 h-24 object-cover rounded border">
                            <button type="button" wire:click="removeExistingImage({{ $image['id'] }})"
                                class="absolute -top-2 -right-2 bg-red-600 text-white rounded-full w-5 h-5 text-xs leading-5">&times;</button>
                        </div>
                    @endforeach
                </div>
            @endif

            <input type="file" wire:model="newImages" multiple accept="image/*" class="block w-full text-sm">
            @error('newImages') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            @error('newImages.*') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror

            @if ($newImages)
                <div class="flex flex-wrap gap-3">
                    @foreach ($newImages as $upload)
                        <img src="{{ $upload->temporaryUrl() }}" class="w-24 h-24 object-cover rounded border">
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Manufacturer / Brand / Search --}}
        <section class="bg-white shadow rounded-lg p-6 space-y-4">
            <h2 class="font-medium text-gray-900">Manufacturer & Discovery</h2>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Manufacturer's Name <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="manufacturerName" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('manufacturerName') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Brand Name <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="brandName" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('brandName') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Search Terms/Keywords <span class="text-red-600">*</span></label>
                @foreach ($searchTerms as $index => $term)
                    <div class="flex gap-2 mt-1">
                        <input type="text" wire:model="searchTerms.{{ $index }}" class="block w-full rounded-md border-gray-300 shadow-sm">
                        @if (count($searchTerms) > 1)
                            <button type="button" wire:click="removeSearchTerm({{ $index }})" class="text-red-600 text-sm">Remove</button>
                        @endif
                    </div>
                @endforeach
                <button type="button" wire:click="addSearchTerm" class="text-indigo-600 text-sm mt-2">+ Add another term</button>
                @error('searchTerms') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </section>

        {{-- Pricing --}}
        <section class="bg-white shadow rounded-lg p-6 space-y-4">
            <h2 class="font-medium text-gray-900">Pricing & Weight</h2>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">List Price/MSRP <span class="text-red-600">*</span></label>
                    <input type="number" step="0.01" wire:model="listPrice" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('listPrice') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Selling Price per Unit <span class="text-red-600">*</span></label>
                    <input type="number" step="0.01" wire:model="sellingPrice" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <p class="text-xs text-gray-400 mt-1">Cannot exceed List Price.</p>
                    @error('sellingPrice') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Item Weight (lbs) <span class="text-red-600">*</span></label>
                    <input type="number" step="0.01" wire:model="itemWeight" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('itemWeight') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- Selling Points & Specifications (Phase 5 complex fields) --}}
        <section class="bg-white shadow rounded-lg p-6 space-y-4">
            <h2 class="font-medium text-gray-900">Selling Points & Specifications</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700">Selling Points <span class="text-red-600">*</span></label>
                @foreach ($sellingPoints as $index => $point)
                    <div class="flex gap-2 mt-1">
                        <input type="text" wire:model="sellingPoints.{{ $index }}" class="block w-full rounded-md border-gray-300 shadow-sm">
                        @if (count($sellingPoints) > 1)
                            <button type="button" wire:click="removeSellingPoint({{ $index }})" class="text-red-600 text-sm">Remove</button>
                        @endif
                    </div>
                @endforeach
                <button type="button" wire:click="addSellingPoint" class="text-indigo-600 text-sm mt-2">+ Add another selling point</button>
                @error('sellingPoints') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Product Attributes / Specifications <span class="text-red-600">*</span></label>
                @foreach ($specifications as $index => $spec)
                    <div class="flex gap-2 mt-1">
                        <input type="text" wire:model="specifications.{{ $index }}.key" placeholder="e.g. Color" class="block w-full rounded-md border-gray-300 shadow-sm">
                        <input type="text" wire:model="specifications.{{ $index }}.value" placeholder="e.g. Red" class="block w-full rounded-md border-gray-300 shadow-sm">
                        @if (count($specifications) > 1)
                            <button type="button" wire:click="removeSpecification({{ $index }})" class="text-red-600 text-sm">Remove</button>
                        @endif
                    </div>
                @endforeach
                <button type="button" wire:click="addSpecification" class="text-indigo-600 text-sm mt-2">+ Add another attribute</button>
                @error('specifications') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </section>

        {{-- Classifications (Phase 6) --}}
        <section class="bg-white shadow rounded-lg p-6 space-y-4">
            <h2 class="font-medium text-gray-900">Product Classifications</h2>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">UNSPSC Code <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="unspscCode" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <p class="text-xs text-gray-400 mt-1">
                        Don't know it? <a href="https://www.unspsc.org/search-code" target="_blank" class="text-indigo-600 underline">Look it up here</a>.
                    </p>
                    @error('unspscCode') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Country of Origin <span class="text-red-600">*</span></label>
                    <select wire:model="countryOfOrigin" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">Select...</option>
                        @foreach ($this->countryOptions as $option)
                            <option value="{{ $option->code }}">{{ $option->name }} ({{ $option->code }})</option>
                        @endforeach
                    </select>
                    @error('countryOfOrigin') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Additional Classifications (optional — check any that apply)</label>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($this->classificationOptions as $option)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="selectedClassifications" value="{{ $option->key }}">
                            {{ $option->label }}
                        </label>
                    @endforeach
                </div>
            </div>

            @if (in_array('UPC_RTL', $selectedClassifications))
                <div>
                    <label class="block text-sm font-medium text-gray-700">UPC <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="upc" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('upc') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            @endif

            @if (in_array('GTIN', $selectedClassifications))
                <div>
                    <label class="block text-sm font-medium text-gray-700">GTIN <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="gtin" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('gtin') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            @endif

            @if (in_array('MSDS URL', $selectedClassifications))
                <div>
                    <label class="block text-sm font-medium text-gray-700">MSDS Link (Hazmat) <span class="text-red-600">*</span></label>
                    <input type="url" wire:model="msdsLink" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('msdsLink') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            @endif

            @if (in_array('National Stock Number', $selectedClassifications))
                <div>
                    <label class="block text-sm font-medium text-gray-700">National Stock Number <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="nationalStockNumber" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('nationalStockNumber') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            @endif

            @if (in_array('NIGP Code', $selectedClassifications))
                <div>
                    <label class="block text-sm font-medium text-gray-700">NIGP Code (5 or 7 digits) <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="nigpCode" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('nigpCode') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            @endif

            @if (in_array('Warranty Information', $selectedClassifications))
                <div>
                    <label class="block text-sm font-medium text-gray-700">Warranty Indicator <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="warrantyIndicator" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('warrantyIndicator') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            @endif

            @if (in_array('Green_Indicator', $selectedClassifications))
                <div>
                    <label class="block text-sm font-medium text-gray-700">Green Information <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="greenInformation" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('greenInformation') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            @endif
        </section>

        {{-- Ordering / Lifecycle --}}
        <section class="bg-white shadow rounded-lg p-6 space-y-4">
            <h2 class="font-medium text-gray-900">Order Quantities & Lifecycle</h2>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Minimum Order Qty</label>
                    <input type="number" wire:model="minimumOrderQty" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('minimumOrderQty') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Multiple Order Qty</label>
                    <input type="number" wire:model="multipleOrderQty" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('multipleOrderQty') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Maximum Order Qty</label>
                    <input type="number" wire:model="maximumOrderQty" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('maximumOrderQty') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                    <input type="checkbox" wire:model.live="discontinued">
                    Discontinue this item
                </label>
            </div>

            @if ($discontinued)
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Discontinued Date <span class="text-red-600">*</span></label>
                        <input type="date" wire:model="discontinuedDate" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        @error('discontinuedDate') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Replacement Part Number(s) <span class="text-red-600">*</span></label>
                        @foreach ($replacementPartNumbers as $index => $part)
                            <div class="flex gap-2 mt-1">
                                <input type="text" wire:model="replacementPartNumbers.{{ $index }}" class="block w-full rounded-md border-gray-300 shadow-sm">
                                @if (count($replacementPartNumbers) > 1)
                                    <button type="button" wire:click="removeReplacementPartNumber({{ $index }})" class="text-red-600 text-sm">Remove</button>
                                @endif
                            </div>
                        @endforeach
                        <button type="button" wire:click="addReplacementPartNumber" class="text-indigo-600 text-sm mt-2">+ Add another</button>
                        <p class="text-xs text-gray-400 mt-1">New replacement products must be added as their own catalog entry first.</p>
                        @error('replacementPartNumbers') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ route('vendor.dashboard') }}" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
            <button type="submit" class="px-6 py-2 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">
                {{ $catalogItemId ? 'Update Item' : 'Add Item' }}
            </button>
        </div>
    </form>
</div>
