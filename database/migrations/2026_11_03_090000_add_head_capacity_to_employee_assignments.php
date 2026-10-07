<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * In what capacity a head-of-office designation heads the office:
     * Officer-in-Charge (`oic`) or Caretaker (`caretaker`). Set only when
     * `as_head`; existing head designations were OICs.
     */
    public function up(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table) {
            $table->string('head_capacity', 20)->nullable()->after('as_head');
        });

        DB::table('employee_assignments')->where('as_head', true)->update(['head_capacity' => 'oic']);
    }

    public function down(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table) {
            $table->dropColumn('head_capacity');
        });
    }
};
