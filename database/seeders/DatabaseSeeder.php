<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        // Default Onboarding Manager (admin) account — email + password auth.
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.test'],
            [
                'name' => 'Onboarding Manager',
                'password' => bcrypt('password'),
            ]
        );

        $this->call([
            ReferenceDataSeeder::class,
            VendorSeeder::class,
        ]);
    }
}
