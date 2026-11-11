<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives CARES the two roles it signs people in as, so the portal shows
 * "Administrator" or "Field Office" instead of "Guest".
 *
 * CARES itself decides the role from the person's unit (PSED staff are
 * administrators; Field and Satellite Office staff work as their office), so
 * these roles mirror that: active PSED users get Administrator and active
 * Field/Satellite Office users get Field Office. The roles change nothing in
 * CARES's own access rules and add no launch URL.
 *
 * - Does nothing where CARES isn't in the portal, or already has roles.
 * - Existing grants are left alone; later hires are granted in System management.
 *
 * Not reversible: remove the roles in System management if unwanted.
 */
return new class extends Migration
{
    /** Role code => [name, description], in display order. */
    private const ROLES = [
        'admin' => ['Administrator', 'PSED staff: every Field Office, all appointments'],
        'field_office' => ['Field Office', 'Field and satellite office staff: their own office only'],
    ];

    public function up(): void
    {
        $application = DB::table('applications')->where('short_name', 'CARES')->first();

        if ($application === null || DB::table('application_roles')->where('application_id', $application->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($application) {
            $roleIds = [];
            $position = 0;

            foreach (self::ROLES as $code => [$name, $description]) {
                $roleIds[$code] = DB::table('application_roles')->insertGetId([
                    'application_id' => $application->id,
                    'name' => $name,
                    'code' => $code,
                    'description' => $description,
                    'status' => 'active',
                    'sort_order' => $position++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $psedId = DB::table('organizational_units')->where('code', 'PSED')->value('id');

            $users = DB::table('users')
                ->join('organizational_units', 'organizational_units.id', '=', 'users.organizational_unit_id')
                ->where('users.status', 'active')
                ->where(fn ($query) => $query
                    ->where('users.organizational_unit_id', $psedId)
                    ->orWhereIn('organizational_units.unit_type', ['field_office', 'satellite_office']))
                ->get(['users.id', 'users.organizational_unit_id']);

            foreach ($users as $user) {
                DB::table('application_access')->insertOrIgnore([
                    'application_id' => $application->id,
                    'user_id' => $user->id,
                    'application_role_id' => (int) $user->organizational_unit_id === (int) $psedId ? $roleIds['admin'] : $roleIds['field_office'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        //
    }
};
