<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ensure the super_admin role exists (Filament Shield's default
        // super-admin role name) and grant it every existing permission.
        $superAdminRole = Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web']
        );

        // Default Onboarding Manager (admin) account — email + password auth.
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.test'],
            [
                'name' => 'Onboarding Manager',
                'password' => bcrypt('password'),
            ]
        );

        if (! $admin->hasRole('super_admin')) {
            $admin->assignRole($superAdminRole);
        }

        $this->call([
            ReferenceDataSeeder::class,
            CatalogFieldDefinitionSeeder::class,
            VendorClientSeeder::class,
        ]);
    }
}
