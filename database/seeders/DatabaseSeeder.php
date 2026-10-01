<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Role;
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
        // User::factory(10)->create();

        $this->call(RolePermissionSeeder::class);
        $this->call(SettingSeeder::class);
        $this->call(RegionalOfficeSeeder::class);
        $this->call(PortalDemoSeeder::class);

        $testUser = User::factory()->create([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
        ]);

        // Development convenience only: grants the local test account
        // Super Administrator access so the authorization demo page has
        // something to show immediately after `php artisan migrate:fresh
        // --seed`. Not a real CSC employee or production credential.
        $testUser->roles()->sync(
            Role::where('name', RoleName::SuperAdmin->value)->pluck('id'),
        );
    }
}
