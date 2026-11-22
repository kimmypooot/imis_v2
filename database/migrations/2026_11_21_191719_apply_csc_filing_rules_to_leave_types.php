<?php

use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies the CSC filing rules (LeaveTypeSeeder::cscFilingRules) to the
 * leave types already in the database. On a fresh install the seeder
 * applies them as it creates the types. HR can change them afterwards
 * in Admin → Leave Types.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (LeaveTypeSeeder::cscFilingRules() as $code => $rules) {
            $excess = $rules['excess_charged_to'] ?? null;
            unset($rules['excess_charged_to']);

            if (isset($rules['eligible_statuses'])) {
                $rules['eligible_statuses'] = json_encode($rules['eligible_statuses']);
            }
            if ($excess) {
                $rules['excess_charged_to_id'] = DB::table('leave_types')->where('code', $excess)->value('id');
            }

            DB::table('leave_types')->where('code', $code)->update([...$rules, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // The rules stay; the columns go with the schema migration's down().
    }
};
