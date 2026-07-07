<?php

namespace App\Livewire\Vendor;

use App\Models\CatalogFieldDefinition;
use App\Models\CatalogItem;
use App\Models\CatalogItemImage;
use App\Models\ClassificationType;
use App\Models\CommodityType;
use App\Models\CountryCode;
use App\Models\ProductHierarchy;
use App\Models\UnitOfMeasure;
use App\Services\ClassificationEngine;
use App\Services\HierarchyPathResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Phase 4/5/6/12 — the vendor-facing product entry form. Renders every
 * active, visible field defined in catalog_field_definitions (labels,
 * tooltips, required/optional state) so admin changes to that table are
 * reflected here without a code change. The underlying business rules that
 * are inherently specific to VIT's spec (hierarchy path resolution,
 * classification key=value assembly, multi-value joins, image embedding)
 * live in dedicated service classes rather than being "guessed" generically
 * from a single config row, since they involve multiple related fields.
 *
 * Handles both "Add" (Phase 12 — new item in an existing or new catalog)
 * and "Edit" (Phase 12 — update specific fields on an existing item,
 * identified by vendor + catalog + part number) via the same component.
 */
class CatalogItemForm extends Component
{
    use WithFileUploads;

    public ?int $catalogItemId = null;

    // Identification
    public string $catalogName = '';

    public string $vendorPartNumber = '';

    public string $manufacturerPartNumber = '';

    // Hierarchy
    public ?int $categoryLevel1Id = null;

    public ?int $categoryLevel2Id = null;

    public ?int $categoryLevel3Id = null;

    public string $commodityType = '';

    // Descriptions
    public string $shortDescription = '';

    public string $longDescription = '';

    public string $unitOfMeasure = '';

    public string $quantity = '';

    public string $quantityUnitType = '';

    // Images
    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newImages = [];

    /** @var array<int, array{id:int, url:string}> */
    public array $existingImages = [];

    // Manufacturer / brand
    public string $manufacturerName = '';

    public string $brandName = '';

    /** @var array<int, string> */
    public array $searchTerms = [''];

    // Pricing / weight
    public ?string $listPrice = null;

    public ?string $sellingPrice = null;

    public ?string $itemWeight = null;

    /** @var array<int, string> */
    public array $sellingPoints = [''];

    /** @var array<int, array{key:string, value:string}> */
    public array $specifications = [['key' => '', 'value' => '']];

    // Classifications
    public string $unspscCode = '';

    public string $countryOfOrigin = '';

    /** @var array<int, string> classification_types.key values the vendor has toggled on */
    public array $selectedClassifications = [];

    public string $upc = '';

    public string $gtin = '';

    public string $msdsLink = '';

    public string $nationalStockNumber = '';

    public string $nigpCode = '';

    public string $warrantyIndicator = '';

    public string $greenInformation = '';

    // Ordering
    public ?string $minimumOrderQty = null;

    public ?string $multipleOrderQty = null;

    public ?string $maximumOrderQty = null;

    public bool $discontinued = false;

    public ?string $discontinuedDate = null;

    /** @var array<int, string> */
    public array $replacementPartNumbers = [''];

    /** Maps a classification_types.key to the bound property that holds its value. */
    protected const CLASSIFICATION_VALUE_PROPERTY = [
        'UPC_RTL' => 'upc',
        'GTIN' => 'gtin',
        'MSDS URL' => 'msdsLink',
        'National Stock Number' => 'nationalStockNumber',
        'NIGP Code' => 'nigpCode',
        'Warranty Information' => 'warrantyIndicator',
        'Green_Indicator' => 'greenInformation',
    ];

    public function mount(?CatalogItem $catalogItem = null): void
    {
        if ($catalogItem && $catalogItem->exists) {
            $this->authorizeVendorOwnership($catalogItem);
            $this->fillFromModel($catalogItem);
        }
    }

    protected function authorizeVendorOwnership(CatalogItem $catalogItem): void
    {
        $client = Auth::guard('client')->user();
        abort_unless($client && $client->vendor_id === $catalogItem->vendor_id, 403);
    }

    protected function fillFromModel(CatalogItem $catalogItem): void
    {
        $this->catalogItemId = $catalogItem->id;
        $values = $catalogItem->field_values ?? [];

        $this->catalogName = $catalogItem->catalog_name;
        $this->vendorPartNumber = $catalogItem->vendor_part_number;
        $this->manufacturerPartNumber = $values['manufacturer_part_number'] ?? '';

        $this->categoryLevel1Id = $values['category_level_1_id'] ?? null;
        $this->categoryLevel2Id = $values['category_level_2_id'] ?? null;
        $this->categoryLevel3Id = $values['category_level_3_id'] ?? null;
        $this->commodityType = $values['commodity_type'] ?? '';

        $this->shortDescription = $values['short_description_base'] ?? '';
        $this->longDescription = $values['long_description'] ?? '';
        $this->unitOfMeasure = $values['unit_of_measure'] ?? '';
        $this->quantity = $values['quantity'] ?? '';
        $this->quantityUnitType = $values['quantity_unit_type'] ?? '';

        $this->manufacturerName = $values['manufacturer_name'] ?? '';
        $this->brandName = $values['brand_name'] ?? '';
        $this->searchTerms = ! empty($values['search_terms_raw']) ? $values['search_terms_raw'] : [''];

        $this->listPrice = $values['list_price'] ?? null;
        $this->sellingPrice = $values['selling_price'] ?? null;
        $this->itemWeight = $values['item_weight'] ?? null;

        $this->sellingPoints = ! empty($values['selling_points_raw']) ? $values['selling_points_raw'] : [''];
        $this->specifications = ! empty($values['specifications_raw']) ? $values['specifications_raw'] : [['key' => '', 'value' => '']];

        $this->unspscCode = $values['unspsc_code'] ?? '';
        $this->countryOfOrigin = $values['country_of_origin'] ?? '';
        $this->selectedClassifications = $values['selected_classifications'] ?? [];
        $this->upc = $values['upc'] ?? '';
        $this->gtin = $values['gtin'] ?? '';
        $this->msdsLink = $values['msds_link'] ?? '';
        $this->nationalStockNumber = $values['national_stock_number'] ?? '';
        $this->nigpCode = $values['nigp_code'] ?? '';
        $this->warrantyIndicator = $values['warranty_indicator'] ?? '';
        $this->greenInformation = $values['green_information'] ?? '';

        $this->minimumOrderQty = $values['minimum_order_qty'] ?? null;
        $this->multipleOrderQty = $values['multiple_order_qty'] ?? null;
        $this->maximumOrderQty = $values['maximum_order_qty'] ?? null;
        $this->discontinued = (bool) ($values['discontinued'] ?? false);
        $this->discontinuedDate = $values['discontinued_date'] ?? null;
        $this->replacementPartNumbers = ! empty($values['replacement_part_numbers_raw']) ? $values['replacement_part_numbers_raw'] : [''];

        $this->existingImages = $catalogItem->images->map(fn (CatalogItemImage $image) => [
            'id' => $image->id,
            'url' => route('vendor.catalog-images.show', $image),
        ])->all();
    }

    #[Computed]
    public function fieldDefinitions()
    {
        return CatalogFieldDefinition::query()
            ->active()
            ->visibleInWebApp()
            ->ordered()
            ->get()
            ->keyBy('field_key');
    }

    public function fieldMeta(string $key): ?CatalogFieldDefinition
    {
        return $this->fieldDefinitions[$key] ?? null;
    }

    #[Computed]
    public function level1Options()
    {
        return ProductHierarchy::query()->level(1)->active()->orderBy('name')->get();
    }

    #[Computed]
    public function level2Options()
    {
        if (! $this->categoryLevel1Id) {
            return collect();
        }

        return ProductHierarchy::query()->level(2)->active()->where('parent_id', $this->categoryLevel1Id)->orderBy('name')->get();
    }

    #[Computed]
    public function level3Options()
    {
        if (! $this->categoryLevel2Id) {
            return collect();
        }

        return ProductHierarchy::query()->level(3)->active()->where('parent_id', $this->categoryLevel2Id)->orderBy('name')->get();
    }

    #[Computed]
    public function commodityTypeOptions()
    {
        return CommodityType::query()->active()->orderBy('sort_order')->get();
    }

    #[Computed]
    public function unitOfMeasureOptions()
    {
        return UnitOfMeasure::query()->active()->orderBy('sort_order')->get();
    }

    #[Computed]
    public function countryOptions()
    {
        return CountryCode::query()->active()->orderBy('name')->get();
    }

    #[Computed]
    public function classificationOptions()
    {
        // UNSPSC & Country of Origin are always-required standalone fields,
        // never shown as togglable "classification" checkboxes.
        return ClassificationType::query()
            ->active()
            ->where('is_always_required', false)
            ->orderBy('sort_order')
            ->get();
    }

    public function updatedCategoryLevel1Id(): void
    {
        $this->categoryLevel2Id = null;
        $this->categoryLevel3Id = null;
        unset($this->level2Options, $this->level3Options);
    }

    public function updatedCategoryLevel2Id(): void
    {
        $this->categoryLevel3Id = null;
        unset($this->level3Options);

        // "Use the name of Category Level 2" auto-fill rule.
        if ($this->commodityType === 'Use the name of Category Level 2' && $this->categoryLevel2Id) {
            // handled at submit time via resolveCommodityType(); nothing to do live.
        }
    }

    public function addSearchTerm(): void
    {
        $this->searchTerms[] = '';
    }

    public function removeSearchTerm(int $index): void
    {
        unset($this->searchTerms[$index]);
        $this->searchTerms = array_values($this->searchTerms);
    }

    public function addSellingPoint(): void
    {
        $this->sellingPoints[] = '';
    }

    public function removeSellingPoint(int $index): void
    {
        unset($this->sellingPoints[$index]);
        $this->sellingPoints = array_values($this->sellingPoints);
    }

    public function addSpecification(): void
    {
        $this->specifications[] = ['key' => '', 'value' => ''];
    }

    public function removeSpecification(int $index): void
    {
        unset($this->specifications[$index]);
        $this->specifications = array_values($this->specifications);
    }

    public function addReplacementPartNumber(): void
    {
        $this->replacementPartNumbers[] = '';
    }

    public function removeReplacementPartNumber(int $index): void
    {
        unset($this->replacementPartNumbers[$index]);
        $this->replacementPartNumbers = array_values($this->replacementPartNumbers);
    }

    public function removeExistingImage(int $imageId): void
    {
        $image = CatalogItemImage::find($imageId);

        if ($image) {
            Storage::disk($image->disk)->delete($image->path);
            $image->delete();
        }

        $this->existingImages = collect($this->existingImages)->reject(fn ($i) => $i['id'] === $imageId)->values()->all();
    }

    protected function resolveCommodityType(string $level2Name): string
    {
        if ($this->commodityType === 'Use the name of Category Level 2') {
            return $level2Name;
        }

        return $this->commodityType;
    }

    public function rules(): array
    {
        $client = Auth::guard('client')->user();

        return [
            'catalogName' => ['required', 'string', 'max:255'],
            'vendorPartNumber' => [
                'required', 'string', 'max:255',
                Rule::unique('catalog_items', 'vendor_part_number')
                    ->where('vendor_id', $client->vendor_id)
                    ->ignore($this->catalogItemId),
            ],
            'manufacturerPartNumber' => ['required', 'string', 'max:255'],

            'categoryLevel1Id' => ['required', 'exists:product_hierarchies,id'],
            'categoryLevel2Id' => ['required', 'exists:product_hierarchies,id'],
            'categoryLevel3Id' => ['required', 'exists:product_hierarchies,id'],
            'commodityType' => ['required', 'string'],

            'shortDescription' => ['required', 'string', 'max:255'],
            'longDescription' => ['required', 'string', 'max:4000'],
            'unitOfMeasure' => ['required', 'string'],
            'quantity' => ['required', 'string', 'max:50'],

            'newImages' => [$this->catalogItemId || count($this->existingImages) ? 'nullable' : 'required', 'array'],
            'newImages.*' => ['image', 'max:8192'],

            'manufacturerName' => ['required', 'string', 'max:255'],
            'brandName' => ['required', 'string', 'max:255'],

            'searchTerms' => ['required', 'array', 'min:1'],
            'searchTerms.*' => ['nullable', 'string'],

            'listPrice' => ['required', 'numeric', 'min:0'],
            'sellingPrice' => ['required', 'numeric', 'min:0', 'lte:listPrice'],
            'itemWeight' => ['required', 'numeric', 'min:0'],

            'sellingPoints' => ['required', 'array', 'min:1'],
            'sellingPoints.*' => ['nullable', 'string'],

            'specifications' => ['required', 'array', 'min:1'],
            'specifications.*.key' => ['nullable', 'string'],
            'specifications.*.value' => ['nullable', 'string'],

            'unspscCode' => ['required', 'string', 'max:255'],
            'countryOfOrigin' => ['required', 'string'],

            'upc' => [in_array('UPC_RTL', $this->selectedClassifications) ? 'required' : 'nullable', 'string', 'max:255'],
            'gtin' => [in_array('GTIN', $this->selectedClassifications) ? 'required' : 'nullable', 'string', 'max:255'],
            'msdsLink' => [in_array('MSDS URL', $this->selectedClassifications) ? 'required' : 'nullable', 'url'],
            'nationalStockNumber' => [in_array('National Stock Number', $this->selectedClassifications) ? 'required' : 'nullable', 'string'],
            'nigpCode' => [in_array('NIGP Code', $this->selectedClassifications) ? 'required' : 'nullable', 'regex:/^\d{5}(\d{2})?$/'],
            'warrantyIndicator' => [in_array('Warranty Information', $this->selectedClassifications) ? 'required' : 'nullable', 'string'],
            'greenInformation' => [in_array('Green_Indicator', $this->selectedClassifications) ? 'required' : 'nullable', 'string'],

            'minimumOrderQty' => ['nullable', 'integer', 'digits_between:1,11'],
            'multipleOrderQty' => ['nullable', 'integer', 'digits_between:1,11'],
            'maximumOrderQty' => ['nullable', 'integer', 'digits_between:1,11'],

            'discontinued' => ['boolean'],
            'discontinuedDate' => [$this->discontinued ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'replacementPartNumbers' => [$this->discontinued ? 'required' : 'nullable', 'array'],
        ];
    }

    public function save()
    {
        $client = Auth::guard('client')->user();
        $vendor = $client->vendor;

        $this->validate();

        // Vendor Catalog Name must be prefixed with the vendor name.
        if (! Str::startsWith(Str::lower(trim($this->catalogName)), Str::lower($vendor->name))) {
            $this->addError('catalogName', "Catalog name must be prefixed with your vendor name, e.g. \"{$vendor->name}-General Catalog\".");

            return;
        }

        $level1 = ProductHierarchy::findOrFail($this->categoryLevel1Id);
        $level2 = ProductHierarchy::findOrFail($this->categoryLevel2Id);
        $level3 = ProductHierarchy::findOrFail($this->categoryLevel3Id);

        $hierarchy = app(HierarchyPathResolver::class)->resolve(
            $level1->name, $level2->name, $level3->name, $vendor->name, $this->catalogName
        );

        $commodityTypeValue = $this->resolveCommodityType($level2->name);

        $uomCode = $this->unitOfMeasure;
        $finalShortDescription = trim($this->shortDescription).', '.trim($this->quantity).'/'.$uomCode;

        $searchTermsClean = array_values(array_filter(array_map('trim', $this->searchTerms)));
        $sellingPointsClean = array_values(array_filter(array_map('trim', $this->sellingPoints)));

        $specPairs = [];
        foreach ($this->specifications as $spec) {
            $k = trim($spec['key'] ?? '');
            $v = trim($spec['value'] ?? '');
            if ($k !== '' && $v !== '') {
                $specPairs[] = "{$k}={$v}";
            }
        }
        if (in_array('Green_Indicator', $this->selectedClassifications) && trim($this->greenInformation) !== '') {
            $specPairs[] = 'Green_Information='.trim($this->greenInformation);
        }

        $classificationValues = [
            'UNSPSC' => $this->unspscCode,
            'Country of Origin' => $this->countryOfOrigin,
        ];
        foreach ($this->selectedClassifications as $key) {
            if ($key === 'Green_Indicator') {
                continue; // feeds specifications, not classifications
            }
            $property = self::CLASSIFICATION_VALUE_PROPERTY[$key] ?? null;
            if ($property) {
                $classificationValues[$key] = $this->{$property};
            }
        }

        $classificationsString = app(ClassificationEngine::class)->build($classificationValues, $vendor->name, $this->catalogName);

        $replacementPartNumbersClean = array_values(array_filter(array_map('trim', $this->replacementPartNumbers)));

        $fieldValues = [
            'vendor_name' => $vendor->name,
            'catalog_name' => $this->catalogName,
            'vendor_part_number' => $this->vendorPartNumber,
            'customer_sku' => $this->vendorPartNumber,
            'search_sku' => $this->vendorPartNumber,
            'vendor_sku' => $this->vendorPartNumber,
            'manufacturer_part_number' => $this->manufacturerPartNumber,
            'type' => 'ELINK',

            'category_level_1_id' => $this->categoryLevel1Id,
            'category_level_2_id' => $this->categoryLevel2Id,
            'category_level_3_id' => $this->categoryLevel3Id,
            'hierarchy_path' => $hierarchy['path'],
            'hierarchy_number' => $hierarchy['hierarchy_number'],

            'commodity_type' => $commodityTypeValue,

            'short_description_base' => $this->shortDescription,
            'short_description' => $finalShortDescription,
            'long_description' => $this->longDescription,
            'unit_of_measure' => $this->unitOfMeasure,
            'quantity' => $this->quantity,
            'quantity_unit_type' => $this->quantityUnitType,

            'manufacturer_name' => $this->manufacturerName,
            'brand_name' => $this->brandName,

            'search_terms_raw' => $searchTermsClean,
            'search_terms' => implode(',', $searchTermsClean),

            'list_price' => number_format((float) $this->listPrice, 2, '.', ''),
            'selling_price' => number_format((float) $this->sellingPrice, 2, '.', ''),
            'item_weight' => number_format((float) $this->itemWeight, 2, '.', ''),

            'selling_points_raw' => $sellingPointsClean,
            'selling_points' => implode('|', $sellingPointsClean),

            'specifications_raw' => $this->specifications,
            'specifications' => implode('|', $specPairs),

            'unspsc_code' => $this->unspscCode,
            'country_of_origin' => $this->countryOfOrigin,
            'selected_classifications' => $this->selectedClassifications,
            'upc' => $this->upc,
            'gtin' => $this->gtin,
            'msds_link' => $this->msdsLink,
            'national_stock_number' => $this->nationalStockNumber,
            'nigp_code' => $this->nigpCode,
            'warranty_indicator' => $this->warrantyIndicator,
            'green_information' => $this->greenInformation,
            'classifications' => $classificationsString,

            'minimum_order_qty' => $this->minimumOrderQty,
            'multiple_order_qty' => $this->multipleOrderQty,
            'maximum_order_qty' => $this->maximumOrderQty,

            'discontinued' => $this->discontinued,
            'discontinued_date' => $this->discontinuedDate,
            'replacement_part_numbers_raw' => $replacementPartNumbersClean,
            'replacement_part_number' => implode(',', $replacementPartNumbersClean),
        ];

        $catalogItem = DB::transaction(function () use ($vendor, $client, $fieldValues) {
            $item = CatalogItem::updateOrCreate(
                ['id' => $this->catalogItemId],
                [
                    'vendor_id' => $vendor->id,
                    'client_id' => $client->id,
                    'catalog_name' => $this->catalogName,
                    'vendor_part_number' => $this->vendorPartNumber,
                    'field_values' => $fieldValues,
                    'status' => 'ready',
                ]
            );

            foreach ($this->newImages as $index => $upload) {
                $path = $upload->store('catalog-images/vendor-'.$vendor->id, 'local');

                CatalogItemImage::create([
                    'catalog_item_id' => $item->id,
                    'disk' => 'local',
                    'path' => $path,
                    'sort_order' => $item->images()->count() + $index,
                ]);
            }

            return $item;
        });

        session()->flash('status', $this->catalogItemId
            ? 'Catalog item updated successfully.'
            : 'Catalog item added successfully.');

        return redirect()->route('vendor.catalog.index', ['catalog' => $catalogItem->catalog_name]);
    }

    public function render()
    {
        return view('livewire.vendor.catalog-item-form');
    }
}
