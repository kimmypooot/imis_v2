<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the Examination Transaction Tracking System (ETTS) to the portal as a
 * hub system owned by the Examination Services Division, with the two roles
 * its sign-in accepts. Before this it existed only in the local database.
 *
 * - The launch URL is left empty: set ETTS's real address in System
 *   management once it is live (and list its host[:port] in SSO_HUB_HOSTS).
 *   Until then nobody can launch it.
 * - No access is granted; assign people from System management.
 * - Safe to run twice (it does nothing if ETTS is already there).
 *
 * Not reversible: remove the system from System management if it isn't wanted.
 */
return new class extends Migration
{
    /** Role code => [name, description], in display order; the codes are ETTS's role values. */
    private const ROLES = [
        'admin' => ['Administrator', 'ESD staff: every office, all transactions'],
        'field_office' => ['Field Office', 'Field and satellite office staff: their own office only'],
    ];

    public function up(): void
    {
        if (DB::table('applications')->where('short_name', 'ETTS')->exists()) {
            return;
        }

        DB::transaction(function () {
            $id = DB::table('applications')->insertGetId([
                'name' => 'Examination Transaction Tracking System',
                'short_name' => 'ETTS',
                'description' => 'Track certifications, authentications and special eligibility applications',
                'icon' => 'clipboard',
                'url' => null,
                'category' => null,
                'external' => true,
                'featured' => false,
                'status' => 'active',
                'visibility' => 'authenticated',
                'requires_access' => true,
                'sort_order' => (int) DB::table('applications')->max('sort_order') + 1,
                'organizational_unit_id' => DB::table('organizational_units')->where('code', 'ESD')->value('id'),
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
