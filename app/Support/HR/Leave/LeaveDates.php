<?php

namespace App\Support\HR\Leave;

use App\Models\LeaveApplication;
use Illuminate\Support\Carbon;

/**
 * The dates of a leave written the way they're filled in on CS Form No. 6
 * (6.C, "Inclusive dates"): a range as "April 28-29, 2021", and chosen
 * dates grouped by month with runs of days joined, "June 2-4, 9 & 12,
 * 2026" or "June 29-30 & July 2, 2026".
 */
class LeaveDates
{
    /**
     * Sorted, distinct Y-m-d dates.
     *
     * @param  array<int, mixed>  $dates
     * @return array<int, string>
     */
    public static function normalize(array $dates): array
    {
        $clean = collect($dates)->filter(fn ($date) => filled($date))
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()->sort()->values()->all();

        return $clean;
    }

    public static function describe(LeaveApplication $application): string
    {
        return $application->hasChosenDates()
            ? self::list($application->dates)
            : self::range($application->date_from, $application->date_to);
    }

    /**
     * As CSC's leave system prints 6.C: "11/3/26", "11/3/26 - 11/7/26",
     * and chosen dates with days that follow one another joined,
     * "11/3/26 - 11/5/26, 11/10/26".
     */
    public static function numeric(LeaveApplication $application): string
    {
        $short = fn (Carbon $date) => $date->format('n/j/y');

        if (! $application->hasChosenDates()) {
            return $application->date_from->isSameDay($application->date_to)
                ? $short($application->date_from)
                : $short($application->date_from).' - '.$short($application->date_to);
        }

        $runs = [];
        foreach (self::normalize($application->dates) as $value) {
            $date = Carbon::parse($value);
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last][1]->copy()->addDay()->isSameDay($date)) {
                $runs[$last][1] = $date;
            } else {
                $runs[] = [$date, $date];
            }
        }

        return implode(', ', array_map(fn (array $run) => $run[0]->isSameDay($run[1]) ? $short($run[0]) : $short($run[0]).' - '.$short($run[1]), $runs));
    }

    /** "April 28, 2021", "April 28-29, 2021", "April 30 - May 4, 2021", across years in full. */
    public static function range(Carbon $from, Carbon $to): string
    {
        return match (true) {
            $from->isSameDay($to) => $from->format('F j, Y'),
            $from->isSameMonth($to) => $from->format('F j').'-'.$to->format('j, Y'),
            $from->isSameYear($to) => $from->format('F j').' - '.$to->format('F j, Y'),
            default => $from->format('F j, Y').' - '.$to->format('F j, Y'),
        };
    }

    /**
     * Chosen dates: by year, then month, with days that follow one another
     * joined ("2-4"); the last two parts joined with "&".
     *
     * @param  array<int, string>  $dates
     */
    public static function list(array $dates, bool $short = false): string
    {
        $years = collect(self::normalize($dates))->map(fn (string $date) => Carbon::parse($date))
            ->groupBy(fn (Carbon $date) => $date->year);

        return $years->map(function ($inYear, int $year) use ($short, $years) {
            $months = $inYear->groupBy(fn (Carbon $date) => $date->month)
                ->map(fn ($inMonth) => $inMonth->first()->format($short ? 'M' : 'F').' '.self::days($inMonth->all()))
                ->values()->all();

            return self::join($months).($short && $years->count() === 1 ? '' : ", {$year}");
        })->values()->implode('; ');
    }

    /**
     * "2-4, 9 & 12".
     *
     * @param  array<int, Carbon>  $dates  one month, sorted
     */
    private static function days(array $dates): string
    {
        $runs = [];
        foreach ($dates as $date) {
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last][1]->copy()->addDay()->isSameDay($date)) {
                $runs[$last][1] = $date;
            } else {
                $runs[] = [$date, $date];
            }
        }

        return self::join(array_map(fn (array $run) => $run[0]->isSameDay($run[1]) ? (string) $run[0]->day : "{$run[0]->day}-{$run[1]->day}", $runs));
    }

    /**
     * @param  array<int, string>  $parts
     */
    private static function join(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts).' & '.$last;
    }
}
