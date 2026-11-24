<?php

namespace Tests\Feature\HR;

use App\Support\HR\Dtr\TardinessConversion;
use Tests\TestCase;

/**
 * CSC Table IV, "Conversion of Working Hours/Minutes into Fractions of a
 * Day" (CSC MC No. 14, s. 1999, amending the Omnibus Rules on Leave).
 */
class TardinessConversionTest extends TestCase
{
    /** The minutes column of Table IV exactly as published. */
    private const MINUTES = [
        1 => .002, 2 => .004, 3 => .006, 4 => .008, 5 => .010, 6 => .012, 7 => .015, 8 => .017, 9 => .019, 10 => .021,
        11 => .023, 12 => .025, 13 => .027, 14 => .029, 15 => .031, 16 => .033, 17 => .035, 18 => .037, 19 => .040, 20 => .042,
        21 => .044, 22 => .046, 23 => .048, 24 => .050, 25 => .052, 26 => .054, 27 => .056, 28 => .058, 29 => .060, 30 => .062,
        31 => .065, 32 => .067, 33 => .069, 34 => .071, 35 => .073, 36 => .075, 37 => .077, 38 => .079, 39 => .081, 40 => .083,
        41 => .085, 42 => .087, 43 => .090, 44 => .092, 45 => .094, 46 => .096, 47 => .098, 48 => .100, 49 => .102, 50 => .104,
        51 => .106, 52 => .108, 53 => .110, 54 => .112, 55 => .115, 56 => .117, 57 => .119, 58 => .121, 59 => .123, 60 => .125,
    ];

    public function test_every_minute_matches_the_published_table(): void
    {
        foreach (self::MINUTES as $minutes => $day) {
            $this->assertEqualsWithDelta($day, (new TardinessConversion)->days($minutes), 0.00001, "{$minutes} minutes");
        }
    }

    public function test_every_whole_hour_matches_the_published_table(): void
    {
        $hours = [1 => .125, 2 => .250, 3 => .375, 4 => .500, 5 => .625, 6 => .750, 7 => .875, 8 => 1.000];

        foreach ($hours as $hour => $day) {
            $this->assertEqualsWithDelta($day, (new TardinessConversion)->days($hour * 60), 0.00001, "{$hour} hours");
        }
    }

    public function test_hours_and_minutes_add_up(): void
    {
        $conversion = new TardinessConversion;

        $this->assertEqualsWithDelta(.135, $conversion->days(65), 0.00001);
        $this->assertEqualsWithDelta(.375 + .052, $conversion->days(3 * 60 + 25), 0.00001);
        $this->assertEqualsWithDelta(.875 + .123, $conversion->days(7 * 60 + 59), 0.00001);
    }

    public function test_a_full_day_or_more_carries_over(): void
    {
        $conversion = new TardinessConversion;

        $this->assertEqualsWithDelta(1.0, $conversion->days(480), 0.00001);
        $this->assertEqualsWithDelta(1.010, $conversion->days(485), 0.00001);
        $this->assertEqualsWithDelta(2.0 + .125, $conversion->days(17 * 60), 0.00001);
    }

    public function test_the_table_can_be_shown_for_reference_exactly_as_it_is_applied(): void
    {
        $table = (new TardinessConversion)->table();

        $this->assertSame(range(1, 8), array_keys($table['hours']));
        $this->assertSame(range(1, 60), array_keys($table['minutes']));
        $this->assertEqualsWithDelta(1.0, $table['hours'][8], 0.00001);
        $this->assertEqualsWithDelta(.125, $table['minutes'][60], 0.00001);

        foreach ($table['minutes'] as $minutes => $day) {
            $this->assertEqualsWithDelta((new TardinessConversion)->days($minutes), $day, 0.00001, "{$minutes} minutes");
        }
        foreach ($table['hours'] as $hours => $day) {
            $this->assertEqualsWithDelta((new TardinessConversion)->days($hours * 60), $day, 0.00001, "{$hours} hours");
        }
    }

    public function test_nothing_or_less_is_no_deduction(): void
    {
        $this->assertSame(0.0, (new TardinessConversion)->days(0));
        $this->assertSame(0.0, (new TardinessConversion)->days(-5));
    }
}
