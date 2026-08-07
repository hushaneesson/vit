<?php

namespace App\Livewire\Vendor;

use App\Models\CatalogItem;
use App\Models\CatalogItemImage;
use App\Models\CommodityType;
use App\Models\UnitOfMeasure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class CatalogItemForm extends Component
{
    use WithFileUploads;

    public ?string $catalogItemId = null;

    // Identification
    public string $name = '';

    public string $sellerSku = '';

    public string $manufacturerSku = '';

    public string $manufacturer = '';

    public string $brandName = '';

    public string $productTypeOrFamily = '';

    public string $description = '';

    public string $unitOfMeasure = '';

    public ?string $quantityPerUnit = null;

    public ?string $itemWeight = null;

    // Images
    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newImages = [];

    /** @var array<int, array{id:int, url:string}> */
    public array $existingImages = [];

    /** @var array<int, string> */
    public array $searchTerms = [''];

    public ?string $listPrice = null;

    public ?string $sellingPricePerUnit = null;

    /** @var array<int, string> */
    public array $sellingPoints = [''];

    /** @var array<int, array{key:string, value:string}> */
    public array $specifications = [['key' => '', 'value' => '']];

    public string $unspscCode = '';

    public string $msdsLink = '';

    /** @var array<int, string> */
    public array $classifications = [''];

    public ?string $minQtyPerOrder = null;

    public ?string $maxQtyPerOrder = null;

    public ?string $multiples = null;

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

        $this->name = $catalogItem->name;
        $this->sellerSku = $catalogItem->dealer_sku;
        $this->manufacturerSku = $catalogItem->manufacturer_sku ?? '';
        $this->manufacturer = $catalogItem->manufacturer ?? '';
        $this->brandName = $catalogItem->brand_name ?? '';
        $this->productTypeOrFamily = $catalogItem->category ?? '';
        $this->description = $catalogItem->description ?? '';
        $this->unitOfMeasure = $catalogItem->unit_of_measure ?? '';
        $this->quantityPerUnit = $catalogItem->quantity_per_unit;
        $this->itemWeight = $catalogItem->item_weight;
        $this->minQtyPerOrder = $catalogItem->min_qty_per_order;
        $this->maxQtyPerOrder = $catalogItem->max_qty_per_order;
        $this->multiples = $catalogItem->multiples;
        $this->listPrice = $catalogItem->list_price;
        $this->sellingPricePerUnit = $catalogItem->selling_price;
        $this->unspscCode = $catalogItem->unspsc_code ?? '';
        $this->msdsLink = $catalogItem->msds_link ?? '';

        $this->searchTerms = ! empty($catalogItem->search_terms) ? $catalogItem->search_terms : [''];
        $this->sellingPoints = ! empty($catalogItem->selling_points) ? $catalogItem->selling_points : [''];
        $this->specifications = ! empty($catalogItem->specifications) ? $catalogItem->specifications : [['key' => '', 'value' => '']];
        $this->classifications = ! empty($catalogItem->classifications) ? $catalogItem->classifications : [''];

        $this->existingImages = $catalogItem->images->map(fn(CatalogItemImage $image) => [
            'id' => $image->id,
            'url' => route('vendor.catalog-images.show', $image),
        ])->all();
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

    public function addClassification(): void
    {
        $this->classifications[] = '';
    }

    public function removeClassification(int $index): void
    {
        unset($this->classifications[$index]);
        $this->classifications = array_values($this->classifications);
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

    public function rules(): array
    {
        $client = Auth::guard('client')->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'sellerSku' => [
                'required',
                'string',
                'max:255',
                Rule::unique('catalog_items', 'dealer_sku')
                    ->where('vendor_id', $client->vendor_id)
                    ->ignore($this->catalogItemId),
            ],
            'manufacturerSku' => ['nullable', 'string', 'max:255'],

            'productTypeOrFamily' => ['required', 'string', 'max:50'],

            'description' => ['required', 'string'],
            'unitOfMeasure' => ['required', 'string', 'max:50'],
            'quantityPerUnit' => ['nullable', 'numeric', 'min:0'],

            'newImages' => [$this->catalogItemId || count($this->existingImages) ? 'nullable' : 'required', 'array'],
            'newImages.*' => ['image', 'max:8192'],

            'manufacturer' => ['nullable', 'string', 'max:255'],
            'brandName' => ['nullable', 'string', 'max:255'],

            'searchTerms' => ['required', 'array', 'min:1'],
            'searchTerms.*' => ['nullable', 'string'],

            'listPrice' => ['required', 'numeric', 'min:0'],
            'sellingPricePerUnit' => ['required', 'numeric', 'min:0', 'lte:listPrice'],
            'itemWeight' => ['required', 'numeric', 'min:0'],

            'sellingPoints' => ['required', 'array', 'min:1'],
            'sellingPoints.*' => ['nullable', 'string'],

            'specifications' => ['required', 'array', 'min:1'],
            'specifications.*.key' => ['nullable', 'string'],
            'specifications.*.value' => ['nullable', 'string'],

            'unspscCode' => ['required', 'string', 'max:255'],
            'msdsLink' => ['nullable', 'url', 'max:300'],

            'classifications' => ['nullable', 'array'],
            'classifications.*' => ['nullable', 'string'],

            'minQtyPerOrder' => ['nullable', 'numeric', 'min:0'],
            'maxQtyPerOrder' => ['nullable', 'numeric', 'min:0'],
            'multiples' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function save()
    {
        $client = Auth::guard('client')->user();
        $vendor = $client->vendor;

        $this->validate();

        $searchTermsClean = array_values(array_filter(array_map('trim', $this->searchTerms)));
        $sellingPointsClean = array_values(array_filter(array_map('trim', $this->sellingPoints)));
        $classificationsClean = array_values(array_filter(array_map('trim', $this->classifications)));

        $specPairs = [];
        foreach ($this->specifications as $spec) {
            $k = trim($spec['key'] ?? '');
            $v = trim($spec['value'] ?? '');
            if ($k !== '' && $v !== '') {
                $specPairs[] = ['key' => $k, 'value' => $v];
            }
        }

        $catalogItem = DB::transaction(function () use ($vendor, $searchTermsClean, $sellingPointsClean, $specPairs, $classificationsClean) {
            $attrs = [
                'name' => $this->name,
                'dealer_sku' => $this->sellerSku,
                'manufacturer_sku' => $this->manufacturerSku,
                'manufacturer' => $this->manufacturer,
                'brand_name' => $this->brandName,
                'category' => $this->productTypeOrFamily,
                'description' => $this->description,
                'unit_of_measure' => $this->unitOfMeasure,
                'quantity_per_unit' => $this->quantityPerUnit,
                'item_weight' => $this->itemWeight,
                'min_qty_per_order' => $this->minQtyPerOrder,
                'max_qty_per_order' => $this->maxQtyPerOrder,
                'multiples' => $this->multiples,
                'search_terms' => $searchTermsClean,
                'selling_points' => $sellingPointsClean,
                'specifications' => $specPairs,
                'unspsc_code' => $this->unspscCode,
                'msds_link' => $this->msdsLink,
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

        return redirect()->route('vendor.catalog.index', ['catalog' => $catalogItem->name]);
    }

    public function render()
    {
        return view('livewire.vendor.catalog-item-form');
    }
}
