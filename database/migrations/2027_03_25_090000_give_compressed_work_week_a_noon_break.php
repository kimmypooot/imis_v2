<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Compressed Work Week's 7:00 to 6:00 span is 11 hours, and its 10
     * working hours exclude the 12:00 to 1:00 noon break on the DTR. "No
     * noon break" only applies to continuous frontline service, which is a
     * schedule of its own. Only the stock schedule is touched, and only if
     * it still has no break.
     */
    public function up(): void
    {
        DB::table('work_schedules')
            ->where('name', 'Compressed Work Week (7:00 to 6:00, core time 8:00 to 7:00, no noon break)')
            ->whereNull('break_start')
            ->update([
                'name' => 'Compressed Work Week (7:00 to 6:00, core time 8:00 to 7:00, noon break)',
                'break_start' => '12:00:00',
                'break_minutes' => 60,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('work_schedules')
            ->where('name', 'Compressed Work Week (7:00 to 6:00, core time 8:00 to 7:00, noon break)')
            ->update([
                'name' => 'Compressed Work Week (7:00 to 6:00, core time 8:00 to 7:00, no noon break)',
                'break_start' => null,
                'break_minutes' => 0,
                'updated_at' => now(),
            ]);
    }
};
