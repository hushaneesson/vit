<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class VendorClientSeeder extends Seeder
{
    public function run(): void
    {
        $vendors = [
            [
                'vendor' => [
                    'name' => 'Acme Office Supply Co.',
                    'legal_company_name' => 'Acme Office Supply Company LLC',
                    'address_line_1' => '1200 Commerce Blvd',
                    'city' => 'Richmond',
                    'state' => 'VA',
                    'postal_code' => '23219',
                    'country' => 'US',
                    'primary_contact_name' => 'Sandra McNeil',
                    'primary_contact_email' => 'sandra.mcneil@acmeoffice.test',
                    'primary_contact_phone' => '804-555-0101',
                    'status' => 'active',
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
                    'legal_company_name' => 'Blue Ridge Furniture Inc.',
                    'address_line_1' => '4550 Market Street',
                    'city' => 'Roanoke',
                    'state' => 'VA',
                    'postal_code' => '24011',
                    'country' => 'US',
                    'primary_contact_name' => 'Caleb Dawson',
                    'primary_contact_email' => 'caleb.dawson@blueridgefurniture.test',
                    'primary_contact_phone' => '540-555-0141',
                    'status' => 'active',
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
                    'legal_company_name' => 'Piedmont Technology Group LLC',
                    'address_line_1' => '8901 Innovation Way',
                    'city' => 'Arlington',
                    'state' => 'VA',
                    'postal_code' => '22203',
                    'country' => 'US',
                    'primary_contact_name' => 'Renee Alston',
                    'primary_contact_email' => 'renee.alston@piedmonttech.test',
                    'primary_contact_phone' => '703-555-0188',
                    'status' => 'pending',
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
        }
    }
}
