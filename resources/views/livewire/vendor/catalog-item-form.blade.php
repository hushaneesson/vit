<div class="mx-auto space-y-6" x-data="{
    scrollToFirstError() {
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                const firstError = this.$el.querySelector('p.text-sm.text-red-600');

                if (!firstError) {
                    return;
                }

                const field = firstError.previousElementSibling?.matches('input, textarea, select') ?
                    firstError.previousElementSibling :
                    firstError.closest('div')?.querySelector('input, textarea, select');

                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });

                if (field) {
                    field.focus({ preventScroll: true });
                }
            });
        });
    }
}" x-on:scroll-to-first-error.window="scrollToFirstError()">
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
                    <label>Product Name / Short Description <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="name"
                        class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1" />
                    <p class="text-sm text-gray-500 mt-1.5">Enter the customer facing product title. Example: Premium
                        2-Ply Bath Tissue.</p>
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
                        <div class="flex items-center justify-between gap-3">
                            <label>Product Category <span class="text-red-600">*</span></label>
                            <button type="button" wire:click="openAddProductCategoryModal"
                                class="text-sm font-medium text-sky-600 hover:text-sky-700">
                                Add New
                            </button>
                        </div>
                        <x-searchable-select wire:key="product-category-select-{{ $productCategorySelectKey }}"
                            wire-model="productCategory" :options="$this->commodityTypeOptions->map(
                                fn($o) => ['value' => $o->id, 'label' => $o->name],
                            )" :allow-create="false"
                            placeholder="Select a product type..." />

                        <p class="text-sm text-gray-500 mt-1.5">Pick the broad commodity group for reporting and search.
                        </p>
                        @error('productCategory')
                            <p class="text-sm text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <label>Hierarchy <span class="text-red-600">*</span></label>
                            <button type="button" wire:click="openAddHierarchyModal"
                                class="text-sm font-medium text-sky-600 hover:text-sky-700">
                                Add New
                            </button>
                        </div>
                        <x-searchable-select wire:key="hierarchy-select-{{ $hierarchySelectKey }}"
                            wire-model="hierarchy" :options="$this->hierarchyOptions->map(fn($h) => ['value' => $h->id, 'label' => $h->path])" :allow-create="false"
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
                <h2 class="font-semibold text-gray-900">Product Images</h2>
            </div>
            @php
                $totalImages = count($newImages) + count(array_filter(array_map('trim', $imageUrls)));
            @endphp
            <div class="p-6 space-y-4">
                <p class="text-xs text-gray-500">Add up to five images total, mixing image names/URLs and uploaded
                    files as needed. ({{ $totalImages }}/5 used)</p>

                <div class="space-y-2">
                    @foreach ($imageUrls as $index => $imageUrl)
                        <div class="flex gap-2">
                            <input type="text" wire:model="imageUrls.{{ $index }}"
                                placeholder="Image name or https://example.com/image.jpg"
                                class="block w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                            @if (count($imageUrls) > 1)
                                <button type="button" wire:click="removeImageUrl({{ $index }})"
                                    class="flex-shrink-0 px-3 text-sm text-red-600 transition-colors rounded-lg hover:bg-red-50">Remove</button>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($totalImages < 5)
                    <button type="button" wire:click="addImageUrl"
                        class="inline-flex items-center gap-1 mt-2 text-sm font-medium text-sky-600 hover:text-sky-700">
                        <i class="fas fa-plus"></i>
                        Add another
                    </button>
                @endif

                @error('imageUrls')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
                @error('imageUrls.*')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror

                {{-- @if (count($existingImages))
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
                @endif --}}

                @if ($totalImages < 5)
                    <label
                        class="flex flex-col items-center justify-center gap-2 px-6 py-8 text-center transition-colors border-2 border-gray-300 border-dashed rounded-lg cursor-pointer hover:border-sky-400 hover:bg-sky-50/40">
                        <i class="text-3xl text-gray-400 fas fa-cloud-upload-alt"></i>
                        <span class="text-sm text-gray-600"><span class="font-medium text-sky-600">Click to
                                upload</span>
                            or drag and drop images</span>
                        <span class="text-xs text-gray-500">{{ 5 - $totalImages }} slot(s) remaining</span>
                        <input type="file" wire:model="newImages" multiple accept="image/*" class="hidden" />
                    </label>
                @endif
                @error('newImages')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
                @error('newImages.*')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror

                @if ($newImages)
                    <div class="flex flex-wrap gap-3">
                        @foreach ($newImages as $index => $upload)
                            <div class="relative">
                                <img src="{{ $upload->temporaryUrl() }}"
                                    class="object-cover w-24 h-24 border border-gray-200 rounded-lg">

                                <button type="button" wire:click="removeNewImage({{ $index }})"
                                    class="absolute flex items-center justify-center w-6 h-6 text-white bg-red-600 rounded-full -top-2 -right-2 hover:bg-red-700">
                                    &times;
                                </button>
                            </div>
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

                <div>

                    <p class="text-sm text-gray-500 mt-1.5">Select a classification type, then provide its value.
                        Example: UPC_RTL = 012345678905.</p>
                    <div class="space-y-2 mt-1.5">
                        @foreach ($classifications as $index => $classification)
                            @php
                                $selectedType = $classification['key'] ?? '';
                                $isRequiredType =
                                    $selectedType !== '' &&
                                    $this->classificationTypeOptions
                                        ->where('key', $selectedType)
                                        ->where('is_always_required', true)
                                        ->isNotEmpty();
                                $selectedTypesInOtherRows = collect($classifications)
                                    ->map(fn($row, $i) => $i === $index ? null : $row['key'] ?? null)
                                    ->filter();
                            @endphp
                            <div class="flex items-center gap-2">
                                <x-searchable-select wire-model="classifications.{{ $index }}.key"
                                    class="flex-1" :options="$this->classificationTypeOptions
                                        ->filter(
                                            fn($t) => !$selectedTypesInOtherRows->contains($t->key) ||
                                                $selectedType === $t->key,
                                        )
                                        ->map(
                                            fn($t) => [
                                                'value' => $t->key,
                                                'label' => $t->label,
                                                'default_value' => $t->default_value,
                                                'index' => $index,
                                            ],
                                        )
                                        ->values()
                                        ->toArray()" :allow-create="false" :disabled="$isRequiredType"
                                    placeholder="Select an option..." />


                                @if ($classification['key'] === 'Country of Origin')
                                    <x-searchable-select wire-model="classifications.{{ $index }}.value"
                                        class="flex-1" :options="$this->countries
                                            ->map(fn($t) => ['value' => $t->code, 'label' => $t->name])
                                            ->values()
                                            ->toArray()" :allow-create="false" :disabled="$isRequiredType"
                                        placeholder="Select an option..." />
                                @elseif($this->classificationTypeOptions->firstWhere('key', $classification['key'])?->default_value)
                                    <p class="w-1/2 text-xs text-gray-500">Default value applied from classification
                                        type.
                                    </p>
                                @else
                                    <input type="text" wire:model="classifications.{{ $index }}.value"
                                        placeholder="Enter value"
                                        class="block w-1/2 text-sm border-gray-300 rounded-lg shadow-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1">
                                @endif


                                @if (!$isRequiredType && count($classifications) > 1)
                                    <button type="button" wire:click="removeClassification({{ $index }})"
                                        class="px-3 text-sm text-red-600 transition-colors rounded-lg shrink-0 hover:bg-red-50">Remove</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @error('classifications.*.key')
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
                        <div class="flex items-center justify-between gap-3">
                            <label>Unit of Measure <span class="text-red-600">*</span></label>
                            <button type="button" wire:click="openAddUnitOfMeasureModal"
                                class="text-sm font-medium text-sky-600 hover:text-sky-700">
                                Add New
                            </button>
                        </div>
                        <x-searchable-select wire:key="unit-of-measure-select-{{ $unitOfMeasureSelectKey }}"
                            wire-model="unitOfMeasure" :options="$this->unitOfMeasureOptions->map(
                                fn($m) => ['value' => $m->id, 'label' => $m->code . ' - ' . $m->description],
                            )" :allow-create="false"
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

    <div x-data x-show="$wire.showAddProductCategoryModal" x-cloak
        x-on:keydown.escape.window="$wire.closeAddProductCategoryModal()"
        class="fixed inset-0 z-50 flex items-center justify-center px-4">
        <div class="fixed inset-0 transition-opacity bg-gray-900/50" x-on:click="$wire.closeAddProductCategoryModal()"
            x-show="$wire.showAddProductCategoryModal" x-transition.opacity></div>

        <div class="relative w-full max-w-md p-6 bg-white shadow-xl rounded-xl"
            x-show="$wire.showAddProductCategoryModal" x-transition>
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Add Product Category</h3>
                    <p class="mt-1 text-sm text-gray-500">Create a new category and use it on this item immediately.
                    </p>
                </div>

                <button type="button" wire:click="closeAddProductCategoryModal"
                    class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="space-y-4">
                <div>
                    <label>Category Name</label>
                    <input type="text" wire:model.defer="newProductCategoryName"
                        class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1"
                        placeholder="Example: Janitorial Supplies" />
                    @error('newProductCategoryName')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <button type="button" wire:click="closeAddProductCategoryModal" class="btn btn-gray">
                    Cancel
                </button>
                <button type="button" wire:click="saveProductCategory" wire:loading.attr="disabled"
                    wire:target="saveProductCategory" class="btn btn-primary">
                    <span wire:loading.remove wire:target="saveProductCategory">Save Category</span>
                    <span wire:loading wire:target="saveProductCategory">Saving...</span>
                </button>
            </div>
        </div>
    </div>

    <div x-data x-show="$wire.showAddHierarchyModal" x-cloak
        x-on:keydown.escape.window="$wire.closeAddHierarchyModal()"
        class="fixed inset-0 z-50 flex items-center justify-center px-4">
        <div class="fixed inset-0 transition-opacity bg-gray-900/50" x-on:click="$wire.closeAddHierarchyModal()"
            x-show="$wire.showAddHierarchyModal" x-transition.opacity></div>

        <div class="relative w-full max-w-2xl p-6 bg-white shadow-xl rounded-xl" x-show="$wire.showAddHierarchyModal"
            x-transition>
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Add Product Hierarchy</h3>
                    <p class="mt-1 text-sm text-gray-500">Provide all three levels. You can select existing values or
                        type new ones.</p>
                </div>

                <button type="button" wire:click="closeAddHierarchyModal" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="grid gap-4">
                <div>
                    <label>Category Level 1</label>
                    <x-searchable-select wire:key="new-hierarchy-level1" wire-model="newHierarchyLevel1"
                        :options="$this->hierarchyLevel1NameOptions
                            ->map(fn($name) => ['value' => $name, 'label' => $name])
                            ->toArray()" :allow-create="true" placeholder="Select or type level 1" />
                    @error('newHierarchyLevel1')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label>Category Level 2</label>
                    <x-searchable-select wire:key="new-hierarchy-level2-{{ md5($newHierarchyLevel1) }}"
                        wire-model="newHierarchyLevel2" :options="$this->hierarchyLevel2NameOptions
                            ->map(fn($name) => ['value' => $name, 'label' => $name])
                            ->toArray()" :allow-create="true"
                        placeholder="Select or type level 2" />
                    @error('newHierarchyLevel2')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label>Category Level 3</label>
                    <input type="text" wire:model.defer="newHierarchyLevel3"
                        class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1"
                        placeholder="Example: Janitorial Supplies" />

                    @error('newHierarchyLevel3')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <button type="button" wire:click="closeAddHierarchyModal" class="btn btn-gray">
                    Cancel
                </button>
                <button type="button" wire:click="saveHierarchy" wire:loading.attr="disabled"
                    wire:target="saveHierarchy" class="btn btn-primary">
                    <span wire:loading.remove wire:target="saveHierarchy">Save Hierarchy</span>
                    <span wire:loading wire:target="saveHierarchy">Saving...</span>
                </button>
            </div>
        </div>
    </div>

    <div x-data x-show="$wire.showAddUnitOfMeasureModal" x-cloak
        x-on:keydown.escape.window="$wire.closeAddUnitOfMeasureModal()"
        class="fixed inset-0 z-50 flex items-center justify-center px-4">
        <div class="fixed inset-0 transition-opacity bg-gray-900/50" x-on:click="$wire.closeAddUnitOfMeasureModal()"
            x-show="$wire.showAddUnitOfMeasureModal" x-transition.opacity></div>

        <div class="relative w-full max-w-md p-6 bg-white shadow-xl rounded-xl"
            x-show="$wire.showAddUnitOfMeasureModal" x-transition>
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Add Unit of Measure</h3>
                    <p class="mt-1 text-sm text-gray-500">Create a new unit and use it on this item immediately.</p>
                </div>

                <button type="button" wire:click="closeAddUnitOfMeasureModal"
                    class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="space-y-4">
                <div>
                    <label>Code</label>
                    <input type="text" wire:model.defer="newUnitOfMeasureCode" maxlength="10"
                        class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm uppercase focus:border-sky-500 focus:ring-sky-500 focus:ring-1"
                        placeholder="Example: EA" />
                    @error('newUnitOfMeasureCode')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label>Description</label>
                    <input type="text" wire:model.defer="newUnitOfMeasureDescription"
                        class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500 focus:ring-1"
                        placeholder="Example: Each" />
                    @error('newUnitOfMeasureDescription')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <button type="button" wire:click="closeAddUnitOfMeasureModal" class="btn btn-gray">
                    Cancel
                </button>
                <button type="button" wire:click="saveUnitOfMeasure" wire:loading.attr="disabled"
                    wire:target="saveUnitOfMeasure" class="btn btn-primary">
                    <span wire:loading.remove wire:target="saveUnitOfMeasure">Save Unit</span>
                    <span wire:loading wire:target="saveUnitOfMeasure">Saving...</span>
                </button>
            </div>
        </div>
    </div>
</div>
