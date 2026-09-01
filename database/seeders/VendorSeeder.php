<?php

namespace Database\Seeders;

use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Client;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    public function run(): void
    {
        $vendors = [
            [
                'vendor' => [
                    'name' => 'Acme Office Supply Co.',
                    'primary_contact_name' => 'Sandra McNeil',
                    'primary_contact_email' => 'sandra.mcneil@acmeoffice.test',
                    'primary_contact_phone' => '804-555-0101',
                    'status' => 'active',
                    'tier' => 'tier_1',
                ],
                'clients' => [
                    [
                        'name' => 'Eli Barnes',
                        'email' => 'eli.barnes@acmeoffice.test',
                        'phone' => '804-555-0102',
                        'title' => 'Catalog Coordinator',
                        'status' => 'active',
                    ],
                    [
                        'name' => 'Nora Patel',
                        'email' => 'nora.patel@acmeoffice.test',
                        'phone' => '804-555-0103',
                        'title' => 'Sales Operations Specialist',
                        'status' => 'invited',
                    ],
                ],
            ],
            [
                'vendor' => [
                    'name' => 'Blue Ridge Furniture',
                    'primary_contact_name' => 'Caleb Dawson',
                    'primary_contact_email' => 'caleb.dawson@blueridgefurniture.test',
                    'primary_contact_phone' => '540-555-0141',
                    'status' => 'active',
                    'tier' => 'tier_2',
                ],
                'clients' => [
                    [
                        'name' => 'Iris Wang',
                        'email' => 'iris.wang@blueridgefurniture.test',
                        'phone' => '540-555-0142',
                        'title' => 'Bid Specialist',
                        'status' => 'active',
                    ],
                    [
                        'name' => 'Mason Reed',
                        'email' => 'mason.reed@blueridgefurniture.test',
                        'phone' => '540-555-0143',
                        'title' => 'Account Manager',
                        'status' => 'disabled',
                    ],
                ],
            ],
            [
                'vendor' => [
                    'name' => 'Piedmont Technology Group',
                    'primary_contact_name' => 'Renee Alston',
                    'primary_contact_email' => 'renee.alston@piedmonttech.test',
                    'primary_contact_phone' => '703-555-0188',
                    'status' => 'pending',
                    'tier' => 'tier_3',
                ],
                'clients' => [
                    [
                        'name' => 'Victor Hall',
                        'email' => 'victor.hall@piedmonttech.test',
                        'phone' => '703-555-0189',
                        'title' => 'Implementation Lead',
                        'status' => 'invited',
                    ],
                    [
                        'name' => 'Tina Mendez',
                        'email' => 'tina.mendez@piedmonttech.test',
                        'phone' => '703-555-0190',
                        'title' => 'Catalog Analyst',
                        'status' => 'active',
                    ],
                    [
                        'name' => 'Adam Clark',
                        'email' => 'adam.clark@piedmonttech.test',
                        'phone' => '703-555-0191',
                        'title' => 'Customer Success Manager',
                        'status' => 'active',
                    ],
                ],
            ],
        ];

        foreach ($vendors as $record) {
            $vendor = Vendor::firstOrCreate(
                ['name' => $record['vendor']['name']],
                $record['vendor']
            );

            foreach ($record['clients'] as $clientData) {
                $status = $clientData['status'];

                Client::firstOrCreate(
                    ['email' => $clientData['email']],
                    [
                        'vendor_id' => $vendor->id,
                        'name' => $clientData['name'],
                        'phone' => $clientData['phone'],
                        'title' => $clientData['title'],
                        'status' => $status,
                        'invited_at' => in_array($status, ['invited', 'active', 'disabled'], true) ? now() : null,
                        'activated_at' => in_array($status, ['active', 'disabled'], true) ? now() : null,
                    ]
                );
            }

            // create general catalog for vendor
            $catalog = Catalog::create(['name' => 'General Catalog', 'vendor_id' => $vendor->id]);

            // catalog items for this vendor
            CatalogItem::factory(30)->create(['vendor_id' => $vendor->id, 'catalog_id' => $catalog->id]);
        }
    }
}
