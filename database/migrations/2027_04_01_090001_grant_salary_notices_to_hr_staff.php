<?php

use App\Support\PermissionCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds `salary_notices.manage` and gives it to HR Staff (`hr-officer`),
 * who already hold the plantilla and 201 File permissions it works with.
 * HrRoleSeeder only creates roles that don't exist yet, so an existing
 * install gets the grant here. Super Administrators pass every check
 * anyway (and RolePermissionSeeder gives them every permission).
 */
return new class extends Migration
{
    private const PERMISSION = 'salary_notices.manage';

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => self::PERMISSION,
                'display_name' => 'Salary Notices Manage',
                'description' => 'Permission to '.self::PERMISSION.'.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $roleId = DB::table('roles')->where('name', 'hr-officer')->value('id');

        if ($roleId !== null && ! DB::table('permission_role')->where(['permission_id' => $permissionId, 'role_id' => $roleId])->exists()) {
            DB::table('permission_role')->insert(['permission_id' => $permissionId, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
        }

        PermissionCache::flush();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        if ($permissionId !== null) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        PermissionCache::flush();
    }
};
