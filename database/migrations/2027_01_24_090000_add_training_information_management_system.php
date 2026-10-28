<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the Training Information Management System (TIMS, the Laravel
 * replacement of the legacy OTRS) to the portal, as its own system beside
 * OTRS, with the five roles its sign-in accepts, and gives it the people who
 * have access to OTRS today.
 *
 * - The launch URL is left empty: set TIMS's real address in System
 *   management once it is live (and list its host[:port] in SSO_HUB_HOSTS).
 *   Until then nobody can launch it.
 * - Access is copied from OTRS, matching roles by code (else by name). Left
 *   out: accounts that are not active, and grants that exist only because of
 *   an office designation (`via_designation`). Run `php artisan
 *   imis:sync-office-admins` afterwards to give the designated people TIMS's
 *   own admin grant.
 * - Written straight to the tables, so these grants are not in the audit log
 *   and nothing is queued for the legacy bridge (TIMS is not a legacy system).
 * - Safe to run twice (it does nothing if TIMS is already there), and it does
 *   nothing without an OTRS entry: a new install has no systems, and gets them
 *   from System management.
 *
 * Not reversible: remove the system from System management if it isn't wanted.
 */
return new class extends Migration
{
    /** Role code => name, in display order; the codes are TIMS's `Role` values. */
    private const ROLES = [
        'admin' => 'Admin',
        'management' => 'Management',
        'field-office' => 'Field Office Staff',
        'collecting-officer' => 'Collecting Officer',
        'superadmin' => 'Super Administrator',
    ];

    /** OTRS role names (lowercase) that predate role codes. */
    private const NAME_TO_CODE = [
        'admin' => 'admin',
        'management' => 'management',
        'user' => 'field-office',
        'field office staff' => 'field-office',
        'collecting officer' => 'collecting-officer',
        'super administrator' => 'superadmin',
    ];

    public function up(): void
    {
        $otrs = DB::table('applications')->where('short_name', 'OTRS')->first();

        if ($otrs === null || DB::table('applications')->where('short_name', 'TIMS')->exists()) {
            return;
        }

        DB::transaction(function () use ($otrs) {
            $timsId = DB::table('applications')->insertGetId([
                'name' => 'Training Information Management System',
                'short_name' => 'TIMS',
                'description' => 'Training registration and management for CSC employees and participants (the Laravel replacement of the legacy OTRS)',
                'icon' => $otrs->icon ?? 'user',
                'url' => null,
                'category' => $otrs->category ?? 'records',
                'external' => true,
                'featured' => false,
                'status' => 'active',
                'visibility' => 'authenticated',
                'requires_access' => true,
                'sort_order' => (int) DB::table('applications')->max('sort_order') + 1,
                'organizational_unit_id' => $otrs->organizational_unit_id ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $position = 0;
            foreach (self::ROLES as $code => $name) {
                $roleIds[$code] = DB::table('application_roles')->insertGetId([
                    'application_id' => $timsId,
                    'name' => $name,
                    'code' => $code,
                    'status' => 'active',
                    'sort_order' => $position++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $grants = DB::table('application_access')
                ->join('users', 'users.id', '=', 'application_access.user_id')
                ->join('application_roles', 'application_roles.id', '=', 'application_access.application_role_id')
                ->where('application_access.application_id', $otrs->id)
                ->where('application_access.via_designation', false)
                ->where('users.status', 'active')
                ->get(['application_access.user_id', 'application_roles.code', 'application_roles.name']);

            foreach ($grants as $grant) {
                $code = $grant->code ?? self::NAME_TO_CODE[mb_strtolower($grant->name)] ?? null;

                if (! isset($roleIds[$code])) {
                    continue;
                }

                DB::table('application_access')->insert([
                    'application_id' => $timsId,
                    'user_id' => $grant->user_id,
                    'application_role_id' => $roleIds[$code],
                    'via_designation' => false,
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
