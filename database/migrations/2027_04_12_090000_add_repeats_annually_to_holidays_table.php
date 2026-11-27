<?php

use App\Models\Holiday;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A holiday can repeat every year on the same month and day (New Year's
 * Day, Independence Day, Christmas Day…). It is entered once, kept under
 * the placeholder year Holiday::REPEATING_YEAR, and counted in every year.
 * Movable ones (Holy Week, National Heroes Day, Chinese New Year, the Eid
 * days) are entered for each year.
 *
 * The fixed-date holidays of Proclamation No. 1427, s. 2026 become
 * repeating holidays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->boolean('repeats_annually')->default(false)->after('type');
        });

        DB::table('holidays')
            ->where('proclamation', 'Proclamation No. 1427, s. 2026')
            ->whereNotIn('name', ['Maundy Thursday', 'Good Friday', 'Black Saturday', 'National Heroes Day', 'Chinese New Year'])
            ->orderBy('id')
            ->get(['id', 'date'])
            ->each(fn (object $holiday) => DB::table('holidays')->where('id', $holiday->id)->update([
                'repeats_annually' => true,
                'date' => Holiday::REPEATING_YEAR.substr((string) $holiday->date, 4, 6),
            ]));
    }

    public function down(): void
    {
        DB::table('holidays')->where('repeats_annually', true)->whereYear('date', Holiday::REPEATING_YEAR)
            ->where('proclamation', 'Proclamation No. 1427, s. 2026')
            ->update(['date' => DB::raw("CONCAT('2027', SUBSTRING(date, 5, 6))")]);

        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn('repeats_annually');
        });
    }
};
