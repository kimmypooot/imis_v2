<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\RoleStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCache;
use Illuminate\Database\Seeder;

/**
 * Deterministic development/test data for the authorization foundation:
 * the super-admin role and the small permission set that demonstrates the
 * architecture. Contains no real CSC employees or credentials.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = collect(PermissionName::cases())->map(
            fn (PermissionName $permission) => Permission::query()->firstOrCreate(
                ['name' => $permission->value],
                [
                    'display_name' => str($permission->value)->replace('.', ' ')->headline(),
                    'description' => "Permission to {$permission->value}.",
                ],
            ),
        );

        PermissionCache::flush();

        $superAdmin = Role::query()->firstOrCreate(
            ['name' => RoleName::SuperAdmin->value],
            [
                'display_name' => 'Super Administrator',
                'description' => 'System-level role with unrestricted access, bypassing normal permission checks.',
                'status' => RoleStatus::Active,
            ],
        );

        // Super Administrator bypasses checks via its role identity (see
        // AppServiceProvider::configureAuthorization), so it does not need
        // every permission attached — but attaching the demo set keeps the
        // authorization demo page's checks meaningful for this role too.
        $superAdmin->permissions()->sync($permissions->pluck('id'));
    }
}
