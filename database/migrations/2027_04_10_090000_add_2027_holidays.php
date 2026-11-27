<?php

use App\Enums\HolidayType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Loads the 2027 holidays declared by Proclamation No. 1427, s. 2026
 * (signed September 8, 2026). Eid'l Fitr and Eid'l Adha are proclaimed
 * separately once their dates are known, so HR adds them in Admin → HR →
 * Holidays. A holiday already entered (same date and name) is left as is.
 */
return new class extends Migration
{
    private const PROCLAMATION = 'Proclamation No. 1427, s. 2026';

    public function up(): void
    {
        $now = now();

        $rows = array_map(fn (array $holiday) => [
            'date' => $holiday[0],
            'name' => $holiday[1],
            'type' => $holiday[2]->value,
            'proclamation' => self::PROCLAMATION,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::holidays());

        DB::table('holidays')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('holidays')->where('proclamation', self::PROCLAMATION)->delete();
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: HolidayType}>
     */
    private static function holidays(): array
    {
        return [
            // Regular holidays
            ['2027-01-01', "New Year's Day", HolidayType::Regular],
            ['2027-03-25', 'Maundy Thursday', HolidayType::Regular],
            ['2027-03-26', 'Good Friday', HolidayType::Regular],
            ['2027-04-09', 'Araw ng Kagitingan', HolidayType::Regular],
            ['2027-05-01', 'Labor Day', HolidayType::Regular],
            ['2027-06-12', 'Independence Day', HolidayType::Regular],
            ['2027-08-30', 'National Heroes Day', HolidayType::Regular],
            ['2027-11-30', 'Bonifacio Day', HolidayType::Regular],
            ['2027-12-25', 'Christmas Day', HolidayType::Regular],
            ['2027-12-30', 'Rizal Day', HolidayType::Regular],

            // Special (non-working) days
            ['2027-02-06', 'Chinese New Year', HolidayType::SpecialNonWorking],
            ['2027-03-27', 'Black Saturday', HolidayType::SpecialNonWorking],
            ['2027-08-21', 'Ninoy Aquino Day', HolidayType::SpecialNonWorking],
            ['2027-11-01', "All Saints' Day", HolidayType::SpecialNonWorking],
            ['2027-11-02', "All Souls' Day", HolidayType::SpecialNonWorking],
            ['2027-12-08', 'Feast of the Immaculate Conception of Mary', HolidayType::SpecialNonWorking],
            ['2027-12-24', 'Christmas Eve', HolidayType::SpecialNonWorking],
            ['2027-12-31', 'Last Day of the Year', HolidayType::SpecialNonWorking],

            // Special (working) day
            ['2027-02-25', 'EDSA People Power Revolution Anniversary', HolidayType::SpecialWorking],
        ];
    }
};
