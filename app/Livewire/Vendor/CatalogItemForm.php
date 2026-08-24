<?php

namespace App\Livewire\Vendor;

use App\Mail\NewCommodityTypeMail;
use App\Mail\NewUnitOfMeasureMail;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\CatalogItemImage;
use App\Models\ClassificationType;
use App\Models\CommodityType;
use App\Models\CountryCode;
use App\Models\ProductHierarchy;
use App\Models\UnitOfMeasure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class CatalogItemForm extends Component
{
    use WithFileUploads;

    public ?string $catalogItemId = null;
    public ?int $catalogId = null;
    public bool $showAddUnitOfMeasureModal = false;
    public int $unitOfMeasureSelectKey = 0;
    public string $newUnitOfMeasureCode = '';
    public string $newUnitOfMeasureDescription = '';

    // Identification
    public string $name = '';
    public string $sellerSku = '';
    public array $replacementSkus = [''];
    public string $manufacturerSku = '';

    public string $manufacturer = '';
    public string $brandName = '';

    public string $productCategory = '';
    public string $hierarchy = '';

    public string $description = '';

    public ?string $availability = null;
    public string $unitOfMeasure = '';
    public ?string $quantityPerUnit = null;
    public ?string $minQtyPerOrder = null;
    public ?string $maxQtyPerOrder = null;
    public ?string $multiples = null;

    public ?string $itemWeight = null;
    public string $leadTime = '';

    public ?string $listPrice = null;
    public ?string $sellingPricePerUnit = null;

    public array $searchTerms = [''];
    public array $sellingPoints = [''];

    // Images
    public array $newImages = [];
    public array $existingImages = [];

    public array $specifications = [['key' => '', 'value' => '']];
    public array $classifications = [['type' => '', 'value' => '']];


    public function mount(?CatalogItem $catalogItem = null, ?int $catalogId = null): void
    {
        if ($catalogItem && $catalogItem->exists) {
            $this->authorizeVendorOwnership($catalogItem);
            $this->fillFromModel($catalogItem);

            return;
        }

        $this->catalogId = $catalogId;

        $this->classifications = $this->ensureRequiredClassificationRows($this->classifications);
    }

    protected function authorizeVendorOwnership(CatalogItem $catalogItem): void
    {
        $client = Auth::guard('client')->user();
        abort_unless($client && $client->vendor_id === $catalogItem->vendor_id, 403);
    }

    protected function fillFromModel(CatalogItem $catalogItem): void
    {
        $this->catalogItemId = $catalogItem->id;
        $this->catalogId = $catalogItem->catalog_id;

        $this->name = $catalogItem->name;
        $this->sellerSku = $catalogItem->dealer_sku;
        $this->replacementSkus = ! empty($catalogItem->replacement_sku) ? array_values($catalogItem->replacement_sku) : [''];
        $this->manufacturerSku = $catalogItem->manufacturer_sku ?? '';
        $this->manufacturer = $catalogItem->manufacturer ?? '';
        $this->brandName = $catalogItem->brand_name ?? '';
        $this->productCategory = $catalogItem->category ?? '';
        $this->hierarchy = $catalogItem->hierarchy ?? '';
        $this->description = $catalogItem->description ?? '';
        $this->unitOfMeasure = $catalogItem->unit_of_measure ?? '';
        $this->quantityPerUnit = $catalogItem->quantity_per_unit;
        $this->itemWeight = $catalogItem->item_weight;
        $this->availability = $catalogItem->availability !== null ? (string) $catalogItem->availability : null;
        $this->leadTime = $catalogItem->lead_time ?? '';
        $this->minQtyPerOrder = $catalogItem->min_qty_per_order;
        $this->maxQtyPerOrder = $catalogItem->max_qty_per_order;
        $this->multiples = $catalogItem->multiples;
        $this->listPrice = $catalogItem->list_price;
        $this->sellingPricePerUnit = $catalogItem->selling_price;

        $this->searchTerms = ! empty($catalogItem->search_terms) ? $catalogItem->search_terms : [''];
        $this->sellingPoints = ! empty($catalogItem->selling_points) ? $catalogItem->selling_points : [''];
        $this->specifications = ! empty($catalogItem->specifications) ? $catalogItem->specifications : [['key' => '', 'value' => '']];
        $this->classifications = $this->ensureRequiredClassificationRows(
            $this->mapStoredClassificationsToRows($catalogItem->classifications)
        );

        // $this->existingImages = $catalogItem->images->map(fn(CatalogItemImage $image) => [
        //     'id' => $image->id,
        //     'url' => route('vendor.catalog-images.show', $image),
        // ])->all();
    }

    #[Computed]
    public function catalogOptions()
    {
        $client = Auth::guard('client')->user();

        return Catalog::query()
            ->where('vendor_id', $client->vendor_id)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function commodityTypeOptions()
    {
        return CommodityType::query()->orderBy('name')->get();
    }

    #[Computed]
    public function unitOfMeasureOptions()
    {
        return UnitOfMeasure::query()->orderBy('code')->get();
    }

    #[Computed]
    public function hierarchyOptions()
    {
        return ProductHierarchy::query()
            ->whereNotNull('hierarchy_number')
            ->where('level', 3)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function classificationTypeOptions()
    {
        return ClassificationType::query()
            ->orderBy('is_always_required', 'desc')
            ->orderBy('label')
            ->get();
    }

    #[Computed]
    public function countries()
    {
        return CountryCode::orderBy('name')
            ->get();
    }

    public function addSearchTerm(): void
    {
        $this->searchTerms[] = '';
    }

    public function addReplacementSku(): void
    {
        if (count($this->replacementSkus) < 4) {
            $this->replacementSkus[] = '';
        }
    }

    public function removeReplacementSku(int $index): void
    {
        unset($this->replacementSkus[$index]);
        $this->replacementSkus = array_values($this->replacementSkus);
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

    public function addClassification(): void
    {
        $this->classifications[] = ['type' => '', 'value' => ''];
    }

    public function removeClassification(int $index): void
    {
        if (! array_key_exists($index, $this->classifications)) {
            return;
        }

        $type = trim((string) ($this->classifications[$index]['type'] ?? ''));

        if ($type !== '' && in_array($type, $this->requiredClassificationTypeKeys(), true)) {
            return;
        }

        unset($this->classifications[$index]);
        $this->classifications = array_values($this->classifications);
        $this->classifications = $this->ensureRequiredClassificationRows($this->classifications);
    }

    public function removeExistingImage(int $imageId): void
    {
        $image = CatalogItemImage::find($imageId);

        if ($image) {
            Storage::disk($image->disk)->delete($image->path);
            $image->delete();
        }

        $this->existingImages = collect($this->existingImages)->reject(fn($i) => $i['id'] === $imageId)->values()->all();
    }

    public function openAddUnitOfMeasureModal(): void
    {
        $this->resetValidation([
            'newUnitOfMeasureCode',
            'newUnitOfMeasureDescription',
        ]);
        $this->newUnitOfMeasureCode = '';
        $this->newUnitOfMeasureDescription = '';
        $this->showAddUnitOfMeasureModal = true;
    }

    public function closeAddUnitOfMeasureModal(): void
    {
        $this->showAddUnitOfMeasureModal = false;
    }

    public function saveUnitOfMeasure(): void
    {
        $validated = $this->validate([
            'newUnitOfMeasureCode' => ['required', 'string', 'max:10', Rule::unique('units_of_measure', 'code')],
            'newUnitOfMeasureDescription' => ['required', 'string', 'max:255'],
        ], [], [
            'newUnitOfMeasureCode' => 'unit of measure code',
            'newUnitOfMeasureDescription' => 'unit of measure description',
        ]);

        $unitOfMeasure = UnitOfMeasure::create([
            'code' => Str::upper(trim($validated['newUnitOfMeasureCode'])),
            'description' => trim($validated['newUnitOfMeasureDescription']),
            'active' => false,
        ]);

        // notify management of new category creation
        Mail::to(config('vit.gateway_email'))
            ->send(new NewUnitOfMeasureMail(
                $unitOfMeasure->code,
                $unitOfMeasure->description
            ));

        $this->unitOfMeasure = (string) $unitOfMeasure->id;
        $this->unitOfMeasureSelectKey++;
        $this->showAddUnitOfMeasureModal = false;
        $this->newUnitOfMeasureCode = '';
        $this->newUnitOfMeasureDescription = '';
        $this->resetValidation([
            'newUnitOfMeasureCode',
            'newUnitOfMeasureDescription',
            'unitOfMeasure',
        ]);
    }

    public function rules(): array
    {
        $client = Auth::guard('client')->user();

        return [
            'catalogId' => [
                'required',
                Rule::exists('catalogs', 'id')->where('vendor_id', $client->vendor_id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'sellerSku' => [
                'required',
                'string',
                'max:255',
                Rule::unique('catalog_items', 'dealer_sku')
                    ->where('vendor_id', $client->vendor_id)
                    ->ignore($this->catalogItemId),
            ],
            'replacementSkus' => ['nullable', 'array', 'max:4'],
            'replacementSkus.*' => ['nullable', 'string', 'max:255', 'exists:catalog_items:dealer_sku'],
            'manufacturerSku' => ['nullable', 'string', 'max:255'],

            'productCategory' => ['required'],
            'hierarchy' => ['required', 'string', 'max:255'],

            'description' => ['required', 'string', 'max:4000'],
            'unitOfMeasure' => ['required'],
            'quantityPerUnit' => ['nullable', 'integer', 'min:1'],

            'newImages' => [$this->catalogItemId || count($this->existingImages) ? 'nullable' : 'array'],
            'newImages.*' => ['image', 'max:8192'],

            'manufacturer' => ['nullable', 'string', 'max:255'],
            'brandName' => ['nullable', 'string', 'max:255'],

            'searchTerms' => ['required', 'array', 'min:1'],
            'searchTerms.*' => ['nullable', 'string'],

            'listPrice' => ['required', 'numeric', 'min:0'],
            'sellingPricePerUnit' => ['required', 'numeric', 'min:0', 'lte:listPrice'],
            'itemWeight' => ['required', 'numeric', 'min:0'],
            'availability' => ['nullable', 'integer', 'min:0'],
            'leadTime' => ['nullable', Rule::in(['0-3 days', '3-5 days', '5-10 days', '10 & over'])],

            'sellingPoints' => ['required', 'array', 'min:1'],
            'sellingPoints.*' => ['nullable', 'string'],

            'specifications' => ['required', 'array', 'min:1'],
            'specifications.*.key' => ['nullable', 'string'],
            'specifications.*.value' => ['nullable', 'string'],
            'classifications' => ['nullable', 'array'],
            'classifications.*.type' => ['nullable', 'required_with:classifications.*.value',],
            'classifications.*.value' => ['nullable', 'required_with:classifications.*.type',],

            'minQtyPerOrder' => ['nullable', 'integer', 'min:0'],
            'maxQtyPerOrder' => ['nullable', 'integer', 'min:0'],
            'multiples' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'classifications.*.type.required_with' => 'All classification rows must include both a type and a value.',
            'classifications.*.value.required_with' => 'All classification rows must include both a type and a value.',
        ];
    }

    public function save()
    {
        $client = Auth::guard('client')->user();
        $vendor = $client->vendor;
        $this->classifications = $this->ensureRequiredClassificationRows($this->classifications);

        try {
            $this->validate();
        } catch (ValidationException $e) {
            $this->dispatch('scroll-to-first-error');

            throw $e;
        }

        if ($this->hasDuplicateClassificationTypes()) {
            $this->addError('classifications', 'Each classification type can only be selected once.');
            $this->dispatch('scroll-to-first-error');

            return;
        }

        $searchTermsClean = array_values(array_filter(array_map('trim', $this->searchTerms)));
        $replacementSkusClean = array_values(array_filter(array_map('trim', $this->replacementSkus)));
        $sellingPointsClean = array_values(array_filter(array_map('trim', $this->sellingPoints)));
        $classificationsClean = [];
        foreach ($this->classifications as $classification) {
            $type = trim((string) ($classification['type'] ?? ''));
            $value = trim((string) ($classification['value'] ?? ''));

            if ($type !== '' && $value !== '') {
                $classificationsClean[] = "{$type}={$value}";
            }
        }

        $specPairs = [];
        foreach ($this->specifications as $spec) {
            $k = trim($spec['key'] ?? '');
            $v = trim($spec['value'] ?? '');
            if ($k !== '' && $v !== '') {
                $specPairs[] = ['key' => $k, 'value' => $v];
            }
        }

        // create category and get id if new
        if (!is_numeric($this->productCategory)) {
            $commodityType = CommodityType::firstOrCreate(['approved' => false, 'name' => Str::headline($this->productCategory)]);
            $this->productCategory = $commodityType->id;

            // notify management of new category creation
            Mail::to(config('vit.gateway_email'))
                ->send(new NewCommodityTypeMail($commodityType->name));
        }

        $catalogItem = DB::transaction(function () use ($vendor, $searchTermsClean, $replacementSkusClean, $sellingPointsClean, $specPairs, $classificationsClean) {
            $attrs = [
                'catalog_id' => $this->catalogId,
                'name' => $this->name,
                'dealer_sku' => $this->sellerSku,
                'replacement_sku' => $replacementSkusClean !== [] ? $replacementSkusClean : null,
                'manufacturer_sku' => $this->manufacturerSku,
                'manufacturer' => $this->manufacturer,
                'brand_name' => $this->brandName,
                'category' => $this->productCategory,
                'hierarchy' => $this->hierarchy,
                'description' => $this->description,
                'unit_of_measure' => $this->unitOfMeasure,
                'quantity_per_unit' => $this->quantityPerUnit !== null && $this->quantityPerUnit !== '' ? (int) $this->quantityPerUnit : null,
                'item_weight' => $this->itemWeight,
                'availability' => $this->availability !== null && $this->availability !== '' ? (int) $this->availability : 999,
                'lead_time' => $this->leadTime !== '' ? $this->leadTime : null,
                'min_qty_per_order' => $this->minQtyPerOrder !== null && $this->minQtyPerOrder !== '' ? (int) $this->minQtyPerOrder : null,
                'max_qty_per_order' => $this->maxQtyPerOrder !== null && $this->maxQtyPerOrder !== '' ? (int) $this->maxQtyPerOrder : null,
                'multiples' => $this->multiples !== null && $this->multiples !== '' ? (int) $this->multiples : null,
                'search_terms' => $searchTermsClean,
                'selling_points' => $sellingPointsClean,
                'specifications' => $specPairs,
                'classifications' => $classificationsClean,
                'list_price' => number_format((float) $this->listPrice, 2, '.', ''),
                'selling_price' => number_format((float) $this->sellingPricePerUnit, 2, '.', ''),
            ];

            // Explicit update/create branch instead of updateOrCreate() — when
            // catalogItemId was null, updateOrCreate([], $attrs) matched with an
            // empty WHERE clause and could silently overwrite an unrelated item.
            // This also adds a vendor_id check on update so a tampered
            // catalogItemId can't write to another vendor's item.

            if ($this->catalogItemId) {
                $item = CatalogItem::where('id', $this->catalogItemId)
                    ->where('vendor_id', $vendor->id)
                    ->firstOrFail();

                $item->update($attrs);
            } else {
                $item = CatalogItem::create(array_merge(['vendor_id' => $vendor->id], $attrs));
            }

            foreach ($this->newImages as $index => $upload) {
                $path = $upload->store('catalog-images/vendor-' . $vendor->id, 'local');

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

        return redirect()->route('vendor.catalog.items', ['catalog' => $catalogItem->catalog_id]);
    }

    public function render()
    {
        return view('livewire.vendor.catalog-item-form');
    }

    /**
     * @param  mixed  $stored
     * @return array<int, array{type:string, value:string}>
     */
    protected function mapStoredClassificationsToRows($stored): array
    {
        if (! is_array($stored) || empty($stored)) {
            return [['type' => '', 'value' => '']];
        }

        $rows = [];

        foreach ($stored as $entry) {
            if (is_array($entry)) {
                $rows[] = [
                    'type' => (string) ($entry['type'] ?? $entry['key'] ?? ''),
                    'value' => (string) ($entry['value'] ?? ''),
                ];

                continue;
            }

            $text = trim((string) $entry);

            if ($text === '') {
                continue;
            }

            if (str_contains($text, '=')) {
                [$type, $value] = explode('=', $text, 2);

                $rows[] = [
                    'type' => trim($type),
                    'value' => trim($value),
                ];

                continue;
            }

            $rows[] = [
                'type' => '',
                'value' => $text,
            ];
        }

        return $rows !== [] ? $rows : [['type' => '', 'value' => '']];
    }

    protected function hasDuplicateClassificationTypes(): bool
    {
        $types = collect($this->classifications)
            ->map(fn(array $row) => trim((string) ($row['type'] ?? '')))
            ->filter()
            ->values();

        return $types->count() !== $types->unique()->count();
    }

    /**
     * @return array<int, string>
     */
    protected function requiredClassificationTypeKeys(): array
    {
        return ClassificationType::query()
            ->where('is_always_required', true)
            ->pluck('key')
            ->map(fn($key) => trim((string) $key))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{type:string, value:string}>
     */
    protected function ensureRequiredClassificationRows(array $rows): array
    {
        $normalized = array_values(array_map(
            fn(array $row) => [
                'type' => trim((string) ($row['type'] ?? '')),
                'value' => trim((string) ($row['value'] ?? '')),
            ],
            $rows,
        ));

        $requiredKeys = $this->requiredClassificationTypeKeys();

        if ($requiredKeys === []) {
            return $normalized !== [] ? $normalized : [['type' => '', 'value' => '']];
        }

        $rowsByType = collect($normalized)
            ->filter(fn(array $row) => $row['type'] !== '')
            ->keyBy('type');

        $requiredRows = collect($requiredKeys)
            ->map(function (string $key) use ($rowsByType): array {
                $row = $rowsByType->get($key);

                return [
                    'type' => $key,
                    'value' => trim((string) ($row['value'] ?? '')),
                ];
            })
            ->values()
            ->all();

        $nonRequiredRows = collect($normalized)
            ->filter(fn(array $row) => ! in_array($row['type'], $requiredKeys, true))
            ->values()
            ->all();

        return array_values(array_merge($requiredRows, $nonRequiredRows));
    }
}
