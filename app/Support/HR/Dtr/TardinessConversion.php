<?php

namespace App\Support\HR\Dtr;

/**
 * CSC Table IV, "Conversion of Working Hours/Minutes into Fractions of a
 * Day" (CSC MC No. 14, s. 1999, which amended the Omnibus Rules on Leave,
 * CSC MC No. 41, s. 1998): how many days of leave credits an amount of
 * tardiness or undertime comes to. A working day is 8 hours, and the table
 * is applied to the total: whole hours first, then the minutes left over.
 * Eight hours or more carries over into whole days.
 *
 * The figures are the table as published (to three places), not minutes
 * divided by 480, so they differ from plain division by up to a thousandth.
 */
class TardinessConversion
{
    /** @var array<int, float> hours (1-7) => fraction of a day */
    private const HOURS = [1 => .125, 2 => .250, 3 => .375, 4 => .500, 5 => .625, 6 => .750, 7 => .875];

    /** @var array<int, float> minutes (1-59) => fraction of a day */
    private const MINUTES = [
        1 => .002, 2 => .004, 3 => .006, 4 => .008, 5 => .010, 6 => .012, 7 => .015, 8 => .017, 9 => .019, 10 => .021,
        11 => .023, 12 => .025, 13 => .027, 14 => .029, 15 => .031, 16 => .033, 17 => .035, 18 => .037, 19 => .040, 20 => .042,
        21 => .044, 22 => .046, 23 => .048, 24 => .050, 25 => .052, 26 => .054, 27 => .056, 28 => .058, 29 => .060, 30 => .062,
        31 => .065, 32 => .067, 33 => .069, 34 => .071, 35 => .073, 36 => .075, 37 => .077, 38 => .079, 39 => .081, 40 => .083,
        41 => .085, 42 => .087, 43 => .090, 44 => .092, 45 => .094, 46 => .096, 47 => .098, 48 => .100, 49 => .102, 50 => .104,
        51 => .106, 52 => .108, 53 => .110, 54 => .112, 55 => .115, 56 => .117, 57 => .119, 58 => .121, 59 => .123,
    ];

    /**
     * The table as it is applied, for showing HR: whole hours 1 to 8 and
     * minutes 1 to 60, each with its fraction of a day.
     *
     * @return array{hours: array<int, float>, minutes: array<int, float>}
     */
    public function table(): array
    {
        return [
            'hours' => collect(range(1, 8))->mapWithKeys(fn (int $hours) => [$hours => $this->days($hours * 60)])->all(),
            'minutes' => collect(range(1, 60))->mapWithKeys(fn (int $minutes) => [$minutes => $this->days($minutes)])->all(),
        ];
    }

    /**
     * Days of leave credits for a total number of minutes.
     */
    public function days(int $minutes): float
    {
        if ($minutes <= 0) {
            return 0.0;
        }

        $hours = intdiv($minutes, 60);

        return round(intdiv($hours, 8) + (self::HOURS[$hours % 8] ?? 0.0) + (self::MINUTES[$minutes % 60] ?? 0.0), 3);
    }
}
