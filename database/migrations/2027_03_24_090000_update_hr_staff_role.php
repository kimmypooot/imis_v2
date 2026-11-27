<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCache;
use Database\Seeders\HrRoleSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The starter HR role is now called "HR Staff", and existing installs get
 * the permissions added since it was seeded (DTR, payroll, data
 * corrections). Only adds permissions and only renames the untouched
 * default label — anything an admin customized stays as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A fresh database gets its roles and permissions from db:seed; this only updates a seeded install.
        if (! Role::query()->where('name', 'super-admin')->exists()) {
            return;
        }

        // Creates any permission rows not in the database yet.
        (new RolePermissionSeeder)->run();

        $role = Role::query()->where('name', 'hr-officer')->first();

        if ($role === null) {
            return;
        }

        $definition = HrRoleSeeder::roles()['hr-officer'];

        if ($role->display_name === 'HR Officer') {
            $role->update(['display_name' => $definition['display_name']]);
        }

        $role->permissions()->syncWithoutDetaching(Permission::query()
            ->whereIn('name', array_map(fn ($permission) => $permission->value, $definition['permissions']))
            ->pluck('id'));

        PermissionCache::flush();
    }

    public function down(): void
    {
        //
    }
};
