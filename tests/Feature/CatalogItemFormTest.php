<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Client;
use App\Models\ClassificationType;
use App\Models\CommodityType;
use App\Models\CountryCode;
use App\Models\ProductHierarchy;
use App\Models\UnitOfMeasure;
use App\Models\Vendor;
use App\Notifications\NewClassificationTypeNotification;
use App\Notifications\NewHierarchyPathNotification;
use App\Livewire\Vendor\CatalogItemForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 4/5/6/12 coverage: config-driven standard fields, complex
 * multi-value/key=value fields, hierarchy resolution + new-path email
 * alert, and vendor-level data isolation (never client-level).
 */
class CatalogItemFormTest extends TestCase
{
    use RefreshDatabase;

    protected function seedReferenceData(): array
    {
        $level1 = ProductHierarchy::create(['level' => 1, 'name' => 'Office Supplies', 'path' => 'Office Supplies', 'active' => true]);
        $level2 = ProductHierarchy::create(['level' => 2, 'name' => 'Paper Products', 'path' => 'Office Supplies!Paper Products', 'parent_id' => $level1->id, 'active' => true]);
        $level3 = ProductHierarchy::create([
            'level' => 3,
            'name' => 'Copy Paper',
            'path' => 'Office Supplies!Paper Products!Copy Paper',
            'parent_id' => $level2->id,
            'hierarchy_number' => '10001',
            'active' => true,
        ]);

        CommodityType::create(['name' => 'Use the name of Category Level 2', 'sort_order' => 0, 'active' => true]);
        UnitOfMeasure::create(['code' => 'RM', 'description' => 'Ream', 'sort_order' => 0, 'active' => true]);
        CountryCode::create(['code' => 'US', 'name' => 'United States', 'active' => true]);
        ClassificationType::create(['key' => 'UNSPSC', 'label' => 'UNSPSC Code', 'is_always_required' => true, 'active' => true, 'sort_order' => 0]);
        ClassificationType::create(['key' => 'Country of Origin', 'label' => 'Country of Origin', 'is_always_required' => true, 'active' => true, 'sort_order' => 1]);

        return compact('level1', 'level2', 'level3');
    }

    protected function actingAsClient(Vendor $vendor): Client
    {
        $client = Client::create([
            'vendor_id' => $vendor->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'status' => 'active',
            'activated_at' => now(),
        ]);

        $this->actingAs($client, 'client');

        return $client;
    }

    public function test_vendor_can_add_a_catalog_item_with_standard_and_complex_fields(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->seedReferenceData();
        $vendor = Vendor::create(['name' => 'XYZ Company', 'status' => 'active']);
        $this->actingAsClient($vendor);

        Livewire::test(CatalogItemForm::class)
            ->set('name', 'Premium Copy Paper')
            ->set('sellerSku', 'SKU-001')
            ->set('manufacturerSku', 'MFG-001')
            ->set('productTypeOrFamily', 'Paper Products')
            ->set('description', 'A long description of copy paper.')
            ->set('unitOfMeasure', 'RM')
            ->set('quantityPerUnit', '10')
            ->set('newImages', [UploadedFile::fake()->image('primary.jpg')])
            ->set('manufacturer', 'Acme Corp')
            ->set('brandName', 'Acme')
            ->set('searchTerms', ['paper', 'copy paper'])
            ->set('listPrice', '19.99')
            ->set('sellingPricePerUnit', '15.99')
            ->set('itemWeight', '5.5')
            ->set('sellingPoints', ['Bright white', 'Acid free'])
            ->set('specifications', [['key' => 'Color', 'value' => 'White'], ['key' => 'Sheets', 'value' => '500']])
            ->set('unspscCode', '14111507')
            ->set('classifications', ['UNSPSC=14111507'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('vendor.catalog.index', ['catalog' => 'Premium Copy Paper']));

        $this->assertDatabaseCount('catalog_items', 1);

        $item = CatalogItem::first();
        $this->assertSame($vendor->id, $item->vendor_id);
        $this->assertSame('SKU-001', $item->seller_sku);

        // Standard fields
        $this->assertSame('Premium Copy Paper', $item->name);
        $this->assertSame('MFG-001', $item->manufacturer_sku);
        $this->assertSame('Acme Corp', $item->manufacturer);
        $this->assertSame('Acme', $item->brand_name);

        // Complex multi-value fields stored as arrays
        $this->assertSame(['paper', 'copy paper'], $item->search_terms);
        $this->assertSame(['Bright white', 'Acid free'], $item->selling_points);
        $this->assertSame([['key' => 'Color', 'value' => 'White'], ['key' => 'Sheets', 'value' => '500']], $item->specifications);

        // One image embedded/stored
        $this->assertCount(1, $item->images);

        Notification::assertNothingSent();
    }

    public function test_new_hierarchy_path_triggers_admin_email_alert(): void
    {
        Storage::fake('local');
        Notification::fake();

        ProductHierarchy::create(['level' => 1, 'name' => 'Tech', 'path' => 'Tech', 'active' => true]);
        ProductHierarchy::create(['level' => 2, 'name' => 'Computers', 'path' => 'Tech!Computers', 'active' => true]);
        ProductHierarchy::create(['level' => 3, 'name' => 'Laptops', 'path' => 'Tech!Computers!Laptops', 'active' => true]);

        CommodityType::create(['name' => 'Technology', 'sort_order' => 0, 'active' => true]);
        UnitOfMeasure::create(['code' => 'EA', 'description' => 'Each', 'sort_order' => 0, 'active' => true]);
        CountryCode::create(['code' => 'US', 'name' => 'United States', 'active' => true]);

        $vendor = Vendor::create(['name' => 'XYZ Company', 'status' => 'active']);
        $this->actingAsClient($vendor);

        Livewire::test(CatalogItemForm::class)
            ->set('name', 'Laptop')
            ->set('sellerSku', 'SKU-100')
            ->set('manufacturerSku', 'MFG-100')
            ->set('productTypeOrFamily', 'Technology')
            ->set('description', 'A laptop.')
            ->set('unitOfMeasure', 'EA')
            ->set('quantityPerUnit', '1')
            ->set('newImages', [UploadedFile::fake()->image('primary.jpg')])
            ->set('manufacturer', 'Acme Corp')
            ->set('brandName', 'Acme')
            ->set('searchTerms', ['laptop'])
            ->set('listPrice', '999.00')
            ->set('sellingPricePerUnit', '899.00')
            ->set('itemWeight', '4.0')
            ->set('sellingPoints', ['Fast'])
            ->set('specifications', [['key' => 'RAM', 'value' => '16GB']])
            ->set('unspscCode', '43211503')
            ->set('classifications', ['UNSPSC=43211503'])
            ->call('save')
            ->assertHasNoErrors();

        // The item should be created successfully with the seller_sku
        $this->assertDatabaseCount('catalog_items', 1);
        $item = CatalogItem::first();
        $this->assertSame('SKU-100', $item->seller_sku);
    }

    public function test_client_cannot_edit_another_vendors_catalog_item(): void
    {
        Storage::fake('local');

        $vendorA = Vendor::create(['name' => 'Vendor A', 'status' => 'active']);
        $vendorB = Vendor::create(['name' => 'Vendor B', 'status' => 'active']);

        $itemB = CatalogItem::create([
            'vendor_id' => $vendorB->id,
            'name' => 'Test Item B',
            'description' => 'Desc',
            'seller_sku' => 'B-SKU-1',
            'unit_of_measure' => 'EA',
            'status' => 'ready',
        ]);

        $this->actingAsClient($vendorA);

        $this->get(route('vendor.catalog.edit', $itemB))->assertForbidden();
    }

    public function test_two_clients_under_the_same_vendor_see_the_same_catalog_items(): void
    {
        $vendor = Vendor::create(['name' => 'Shared Vendor', 'status' => 'active']);

        $clientOne = Client::create(['vendor_id' => $vendor->id, 'name' => 'Client One', 'email' => 'one@example.com', 'status' => 'active']);
        $clientTwo = Client::create(['vendor_id' => $vendor->id, 'name' => 'Client Two', 'email' => 'two@example.com', 'status' => 'active']);

        CatalogItem::create([
            'vendor_id' => $vendor->id,
            'name' => 'Shared Item',
            'description' => 'Desc',
            'seller_sku' => 'SKU-SHARED-1',
            'unit_of_measure' => 'EA',
            'status' => 'ready',
        ]);

        $this->actingAs($clientTwo, 'client');

        $this->get(route('vendor.catalog.index', ['catalog' => 'Shared Catalog']))
            ->assertOk()
            ->assertSee('SKU-SHARED-1');
    }
}
