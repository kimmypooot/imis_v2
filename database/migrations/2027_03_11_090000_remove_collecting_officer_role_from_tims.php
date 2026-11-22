<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes TIMS's "Collecting Officer" role.
 *
 * TIMS no longer has it: money there is a matter of role alone (Field Office
 * within its office, Admin and Super Administrator across the region), so the
 * code is no longer one its sign-in accepts, and a grant left on it would be
 * refused at the door with "you have not been given a role".
 *
 * Anyone still granted it is moved first, by the office they work in — the
 * same rule TIMS uses to pre-identify a new account's role:
 * - a field or satellite office → Field Office Staff;
 * - the Human Resource Division → Admin;
 * - the Office of the Regional Director → Management;
 * - anywhere else → the grant is removed, because TIMS staff come only from
 *   those offices.
 * A designation grant that would fall back to it falls back by the same rule
 * (or to nothing).
 *
 * Written straight to the tables, like the migration that added TIMS. Safe to
 * run twice, and does nothing where TIMS or the role does not exist. Not
 * reversible: who held the role is not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tims = DB::table('applications')->where('short_name', 'TIMS')->first();

        $retired = $tims ? DB::table('application_roles')
            ->where('application_id', $tims->id)
            ->where('code', 'collecting-officer')
            ->first() : null;

        if ($retired === null) {
            return;
        }

        $roleIds = DB::table('application_roles')
            ->where('application_id', $tims->id)
            ->whereIn('code', ['field-office', 'admin', 'management'])
            ->pluck('id', 'code');

        $units = DB::table('organizational_units')->get(['id', 'parent_id', 'code', 'unit_type'])->keyBy('id');

        $replacement = function (?int $userId) use ($units, $roleIds): ?int {
            $unitId = DB::table('users')->where('id', $userId)->value('organizational_unit_id');

            for ($unit = $units->get($unitId); $unit !== null; $unit = $units->get($unit->parent_id)) {
                if ($unit->unit_type === 'other') {
                    continue;
                }

                return match (true) {
                    in_array($unit->unit_type, ['field_office', 'satellite_office'], true) => $roleIds['field-office'] ?? null,
                    $unit->code === 'HRD' => $roleIds['admin'] ?? null,
                    $unit->code === 'ORD' => $roleIds['management'] ?? null,
                    default => null,
                };
            }

            return null;
        };

        DB::transaction(function () use ($retired, $replacement) {
            foreach (DB::table('application_access')->where('application_role_id', $retired->id)->get() as $access) {
                $role = $replacement($access->user_id);

                $role === null
                    ? DB::table('application_access')->where('id', $access->id)->delete()
                    : DB::table('application_access')->where('id', $access->id)->update(['application_role_id' => $role, 'updated_at' => now()]);
            }

            foreach (DB::table('application_access')->where('designation_previous_role_id', $retired->id)->get() as $access) {
                DB::table('application_access')->where('id', $access->id)->update([
                    'designation_previous_role_id' => $replacement($access->user_id),
                    'updated_at' => now(),
                ]);
            }

            DB::table('application_roles')->where('id', $retired->id)->delete();
        });
    }

    public function down(): void
    {
        //
    }
};
