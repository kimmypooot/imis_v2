<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds BMIS, the Basic Management Information System (../new-bmis, replacing
 * the BMIS.xlsb workbook), to the portal as a hub system owned by the
 * Management Services Division, with the two roles its sign-in accepts.
 *
 * - The launch URL is left empty: set BMIS's real address in System
 *   management once it is live (and list its host[:port] in SSO_HUB_HOSTS).
 *   Until then it is listed but nobody can launch it.
 * - No access is granted; assign people from System management. BMIS takes
 *   the person's role from the one granted here.
 * - Safe to run twice (it does nothing if BMIS is already there).
 *
 * Not reversible: remove the system from System management if it isn't wanted.
 */
return new class extends Migration
{
    /** Role code => [name, description]; the codes are BMIS's role values (App\Enums\UserRole). */
    private const ROLES = [
        'admin' => ['BMIS Administrator', 'Everything a user does, plus managing the settings: reference data, signatories and employees'],
        'user' => ['BMIS User', 'Any employee: enter and print vouchers, collections and travel claims, search and run reports'],
    ];

    public function up(): void
    {
        if (DB::table('applications')->where('short_name', 'BMIS')->exists()) {
            return;
        }

        DB::transaction(function () {
            $id = DB::table('applications')->insertGetId([
                'name' => 'Basic Management Information System',
                'short_name' => 'BMIS',
                'description' => 'Disbursement vouchers, ORS/BURS, BIR tax certificates, collections and travel claims',
                'icon' => 'database',
                'url' => null,
                'category' => null,
                'external' => true,
                'featured' => false,
                'status' => 'active',
                'visibility' => 'authenticated',
                'requires_access' => true,
                'sort_order' => (int) DB::table('applications')->max('sort_order') + 1,
                'organizational_unit_id' => DB::table('organizational_units')->where('code', 'MSD')->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $position = 0;
            foreach (self::ROLES as $code => [$name, $description]) {
                DB::table('application_roles')->insert([
                    'application_id' => $id,
                    'name' => $name,
                    'code' => $code,
                    'description' => $description,
                    'status' => 'active',
                    'sort_order' => $position++,
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
