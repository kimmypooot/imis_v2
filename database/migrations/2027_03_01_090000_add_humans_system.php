<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds HUMANS, the Human Resource Mechanisms Assessment and Network
 * Documentation System, to the portal as a hub system owned by the Policies
 * and Systems Evaluation Division, with the four roles its sign-in accepts (agencies have no accounts: they use HUMANS's public page).
 *
 * - The launch URL is left empty: set HUMANS's real address in System
 *   management once it is live (and list its host[:port] in SSO_HUB_HOSTS).
 *   Until then it is listed but nobody can launch it.
 * - No access is granted; assign people from System management. HUMANS takes
 *   the person's role from the one granted here.
 * - Safe to run twice (it does nothing if HUMANS is already there).
 *
 * Not reversible: remove the system from System management if it isn't wanted.
 */
return new class extends Migration
{
    /** Role code => [name, description], in display order; the codes are HUMANS's role values. */
    private const ROLES = [
        'psed_staff' => ['PSED Staff', 'Route submitted HR mechanisms, and evaluate those routed to them as Action Officer'],
        'psed_chief' => ['PSED Chief', 'Everything PSED staff do, and review and approve what the Action Officers forward'],
        'director' => ['Director IV', 'Final approval of the printed and signed HR mechanisms'],
        'field_office' => ['Field Office', 'Read-only view of the HR mechanisms of the agencies of their office'],
    ];

    public function up(): void
    {
        if (DB::table('applications')->where('short_name', 'HUMANS')->exists()) {
            return;
        }

        DB::transaction(function () {
            $id = DB::table('applications')->insertGetId([
                'name' => 'Human Resource Mechanisms Assessment and Network Documentation System',
                'short_name' => 'HUMANS',
                'description' => 'Submission, evaluation, signature and approval of the HR mechanisms of client agencies',
                'icon' => 'clipboard',
                'url' => null,
                'category' => null,
                'external' => true,
                'featured' => false,
                'status' => 'active',
                'visibility' => 'authenticated',
                'requires_access' => true,
                'sort_order' => (int) DB::table('applications')->max('sort_order') + 1,
                'organizational_unit_id' => DB::table('organizational_units')->where('code', 'PSED')->value('id'),
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
