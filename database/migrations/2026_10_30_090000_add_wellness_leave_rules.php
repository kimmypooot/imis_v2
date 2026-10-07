<?php

use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wellness Leave (CSC MC No. 01, s. 2026) needs two rules the leave
     * types didn't have: filing on return in an emergency instead of in
     * advance (3.e), and a cap on days in a row across filings (3.a). Its
     * terms are filled in here, unless HR already entered their own.
     */
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('allows_emergency_filing')->default(false)->after('advance_filing_days');
            $table->unsignedSmallInteger('max_consecutive_days')->nullable()->after('max_days_per_filing');
        });

        Schema::table('leave_applications', function (Blueprint $table) {
            // Filed on return from an emergency, instead of in advance.
            $table->boolean('is_emergency')->default(false)->after('commutation_requested');
        });

        DB::table('leave_types')->where('code', 'WL')->whereNull('annual_grant')->update([
            ...LeaveTypeSeeder::wellnessLeave(),
            'eligible_statuses' => json_encode(LeaveTypeSeeder::wellnessLeave()['eligible_statuses']),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('leave_applications', fn (Blueprint $table) => $table->dropColumn('is_emergency'));
        Schema::table('leave_types', fn (Blueprint $table) => $table->dropColumn(['allows_emergency_filing', 'max_consecutive_days']));
    }
};
