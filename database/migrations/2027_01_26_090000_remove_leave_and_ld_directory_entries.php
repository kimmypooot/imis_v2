<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Leave and Learning & Development are built into IMIS now (HR-3 and HR-6),
 * so the Human Resource Division no longer lists the legacy Leave
 * Application and Management System or Learning and Development Database
 * as separate systems.
 *
 * Deleting a system takes its roles, access grants, listings, legacy-bridge
 * rows and SSO sessions with it; access-log rows are kept with no system
 * attached. Matches only the exact names under the Human Resource Division,
 * so a system an admin renamed or moved is left alone.
 *
 * Not reversible: re-add a system from System management if it is needed.
 */
return new class extends Migration
{
    private const NAMES = [
        'Leave Application and Management System',
        'Learning and Development Database',
    ];

    public function up(): void
    {
        $unitIds = DB::table('organizational_units')
            ->where('name', 'Human Resource Division')
            ->pluck('id');

        DB::table('applications')
            ->whereIn('name', self::NAMES)
            ->whereIn('organizational_unit_id', $unitIds)
            ->delete();
    }

    public function down(): void
    {
        //
    }
};
