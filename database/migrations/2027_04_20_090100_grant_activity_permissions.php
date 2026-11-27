<?php

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCache;
use Database\Seeders\ActivityRoleSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Calendar of Activities: adds the `activities.*` permissions, gives
 * `activities.view` to every staff account through the default `user`
 * role, and creates the PALD and office-poster roles if they don't exist
 * yet (nobody is assigned to them). ActivityRoleSeeder does the same on a
 * fresh install; this brings an existing install along.
 */
return new class extends Migration
{
    /** @var array<int, PermissionName> */
    private const PERMISSIONS = [
        PermissionName::ActivitiesView,
        PermissionName::ActivitiesPost,
        PermissionName::ActivitiesManage,
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(
                ['name' => $permission->value],
                [
                    'display_name' => str($permission->value)->replace('.', ' ')->headline(),
                    'description' => "Permission to {$permission->value}.",
                ],
            );
        }

        (new ActivityRoleSeeder)->run();
    }

    public function down(): void
    {
        Role::query()->whereIn('name', array_keys(ActivityRoleSeeder::roles()))->get()
            ->each(function (Role $role) {
                $role->permissions()->detach();
                $role->delete();
            });

        Permission::query()
            ->whereIn('name', array_map(fn (PermissionName $permission) => $permission->value, self::PERMISSIONS))
            ->get()
            ->each(function (Permission $permission) {
                $permission->roles()->detach();
                $permission->delete();
            });

        PermissionCache::flush();
    }
};
