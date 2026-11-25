<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-8: a payroll covers the whole month and is released once, at the
     * end of it, as the General Payroll does, instead of in two halves on
     * the 15th and the 30th.
     */
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->date('release_on')->nullable()->after('status');
        });

        DB::table('payroll_runs')->update(['release_on' => DB::raw('second_release_on')]);

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn(['first_release_on', 'second_release_on']);
        });

        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropColumn(['first_half', 'second_half']);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->decimal('first_half', 12, 2)->default(0)->after('net_pay');
            $table->decimal('second_half', 12, 2)->default(0)->after('first_half');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->date('first_release_on')->nullable()->after('status');
            $table->date('second_release_on')->nullable()->after('first_release_on');
        });

        DB::table('payroll_runs')->update(['second_release_on' => DB::raw('release_on')]);

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('release_on');
        });
    }
};
