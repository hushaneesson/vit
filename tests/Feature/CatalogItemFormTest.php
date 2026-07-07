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
            'level' => 3, 'name' => 'Copy Paper',
            'path' => 'Office Supplies!Paper Products!Copy Paper',
            'parent_id' => $level2->id, 'hierarchy_number' => '10001', 'active' => true,
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

        $hierarchy = $this->seedReferenceData();
        $vendor = Vendor::create(['name' => 'XYZ Company', 'status' => 'active']);
        $this->actingAsClient($vendor);

        Livewire::test(CatalogItemForm::class)
            ->set('catalogName', 'XYZ Company-General Catalog')
            ->set('vendorPartNumber', 'SKU-001')
            ->set('manufacturerPartNumber', 'MFG-001')
            ->set('categoryLevel1Id', $hierarchy['level1']->id)
            ->set('categoryLevel2Id', $hierarchy['level2']->id)
            ->set('categoryLevel3Id', $hierarchy['level3']->id)
            ->set('commodityType', 'Use the name of Category Level 2')
            ->set('shortDescription', 'Premium Copy Paper')
            ->set('longDescription', 'A long description of copy paper.')
            ->set('unitOfMeasure', 'RM')
            ->set('quantity', '10')
            ->set('quantityUnitType', 'Reams')
            ->set('newImages', [UploadedFile::fake()->image('primary.jpg')])
            ->set('manufacturerName', 'Acme Corp')
            ->set('brandName', 'Acme')
            ->set('searchTerms', ['paper', 'copy paper'])
            ->set('listPrice', '19.99')
            ->set('sellingPrice', '15.99')
            ->set('itemWeight', '5.5')
            ->set('sellingPoints', ['Bright white', 'Acid free'])
            ->set('specifications', [['key' => 'Color', 'value' => 'White'], ['key' => 'Sheets', 'value' => '500']])
            ->set('unspscCode', '14111507')
            ->set('countryOfOrigin', 'US')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('vendor.catalog.index', ['catalog' => 'XYZ Company-General Catalog']));

        $this->assertDatabaseCount('catalog_items', 1);

        $item = CatalogItem::first();
        $this->assertSame($vendor->id, $item->vendor_id);
        $this->assertSame('SKU-001', $item->vendor_part_number);

        // Standard fields
        $this->assertSame('XYZ Company', $item->field_values['vendor_name']);
        $this->assertSame('SKU-001', $item->field_values['customer_sku']);
        $this->assertSame('ELINK', $item->field_values['type']);

        // Hierarchy resolution (Phase 6) — path found, hierarchy_number populated
        $this->assertSame('Office Supplies!Paper Products!Copy Paper', $item->field_values['hierarchy_path']);
        $this->assertSame('10001', $item->field_values['hierarchy_number']);

        // "Use the name of Category Level 2" auto-fill rule
        $this->assertSame('Paper Products', $item->field_values['commodity_type']);

        // Quantity/UOM appended to short description
        $this->assertSame('Premium Copy Paper, 10/RM', $item->field_values['short_description']);

        // Complex multi-value fields (Phase 5) joined correctly
        $this->assertSame('paper,copy paper', $item->field_values['search_terms']);
        $this->assertSame('Bright white|Acid free', $item->field_values['selling_points']);
        $this->assertSame('Color=White|Sheets=500', $item->field_values['specifications']);

        // Classifications engine (Phase 6) — always-required keys present
        $this->assertSame('UNSPSC=14111507|Country of Origin=US', $item->field_values['classifications']);

        // One image embedded/stored
        $this->assertCount(1, $item->images);

        Notification::assertNothingSent();
    }

    public function test_new_hierarchy_path_triggers_admin_email_alert(): void
    {
        Storage::fake('local');
        Notification::fake();

        $level1 = ProductHierarchy::create(['level' => 1, 'name' => 'Tech', 'path' => 'Tech', 'active' => true]);
        $level2 = ProductHierarchy::create(['level' => 2, 'name' => 'Computers', 'path' => 'Tech!Computers', 'parent_id' => $level1->id, 'active' => true]);
        // Note: no level-3 leaf created, so the resolved path won't exist yet.
        $level3 = ProductHierarchy::create(['level' => 3, 'name' => 'Laptops', 'path' => 'Tech!Computers!Laptops', 'parent_id' => $level2->id, 'active' => true]);

        CommodityType::create(['name' => 'Technology', 'sort_order' => 0, 'active' => true]);
        UnitOfMeasure::create(['code' => 'EA', 'description' => 'Each', 'sort_order' => 0, 'active' => true]);
        CountryCode::create(['code' => 'US', 'name' => 'United States', 'active' => true]);

        $vendor = Vendor::create(['name' => 'XYZ Company', 'status' => 'active']);
        $this->actingAsClient($vendor);

        Livewire::test(CatalogItemForm::class)
            ->set('catalogName', 'XYZ Company-Tech Catalog')
            ->set('vendorPartNumber', 'SKU-100')
            ->set('manufacturerPartNumber', 'MFG-100')
            ->set('categoryLevel1Id', $level1->id)
            ->set('categoryLevel2Id', $level2->id)
            ->set('categoryLevel3Id', $level3->id)
            ->set('commodityType', 'Technology')
            ->set('shortDescription', 'Laptop')
            ->set('longDescription', 'A laptop.')
            ->set('unitOfMeasure', 'EA')
            ->set('quantity', '1')
            ->set('newImages', [UploadedFile::fake()->image('primary.jpg')])
            ->set('manufacturerName', 'Acme Corp')
            ->set('brandName', 'Acme')
            ->set('searchTerms', ['laptop'])
            ->set('listPrice', '999.00')
            ->set('sellingPrice', '899.00')
            ->set('itemWeight', '4.0')
            ->set('sellingPoints', ['Fast'])
            ->set('specifications', [['key' => 'RAM', 'value' => '16GB']])
            ->set('unspscCode', '43211503')
            ->set('countryOfOrigin', 'US')
            ->call('save')
            ->assertHasNoErrors();

        // hierarchy_number has no value since it's a leaf without one set —
        // resolver should have emailed the gateway address about the "new" path.
        Notification::assertSentOnDemand(NewHierarchyPathNotification::class);
    }

    public function test_client_cannot_edit_another_vendors_catalog_item(): void
    {
        Storage::fake('local');

        $vendorA = Vendor::create(['name' => 'Vendor A', 'status' => 'active']);
        $vendorB = Vendor::create(['name' => 'Vendor B', 'status' => 'active']);

        $itemB = CatalogItem::create([
            'vendor_id' => $vendorB->id,
            'client_id' => Client::create(['vendor_id' => $vendorB->id, 'name' => 'B User', 'email' => 'b@example.com', 'status' => 'active'])->id,
            'catalog_name' => 'Vendor B Catalog',
            'vendor_part_number' => 'B-SKU-1',
            'field_values' => [],
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
            'client_id' => $clientOne->id,
            'catalog_name' => 'Shared Catalog',
            'vendor_part_number' => 'SKU-SHARED-1',
            'field_values' => [],
            'status' => 'ready',
        ]);

        $this->actingAs($clientTwo, 'client');

        $this->get(route('vendor.catalog.index', ['catalog' => 'Shared Catalog']))
            ->assertOk()
            ->assertSee('SKU-SHARED-1');
    }
}
