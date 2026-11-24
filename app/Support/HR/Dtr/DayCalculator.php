<?php

namespace App\Support\HR\Dtr;

use App\Models\WorkSchedule;
use Illuminate\Support\Carbon;

/**
 * DTR: Rule B for one working day.
 *
 * - Time before the schedule's start never counts; the day is counted from
 *   the later of the arrival and the start to the departure, less any part
 *   of the break that was worked through.
 * - Late minutes are those after the latest arrival, with no grace.
 * - A full day is `required_minutes`; lateness is docked from it and is not
 *   made up by staying later. Credited minutes are what was on site, up to
 *   that. Undertime is what is still short, so late and undertime are never
 *   counted twice.
 * - One punch (or none) leaves the day incomplete (or absent) with nothing
 *   credited, for HRD to adjust.
 * - With half-day leave the other half is judged by time on site only, with
 *   no lateness and no break; the leave half is added by the caller.
 *
 * Seconds are ignored. All amounts are whole minutes.
 */
class DayCalculator
{
    /**
     * @return array{minutes: int, late_minutes: int, undertime_minutes: int, status: string}
     */
    public function forWorkingDay(WorkSchedule $schedule, Carbon $day, ?Carbon $in, ?Carbon $out, int $punches, float $leaveFraction = 0.0): array
    {
        if ($in === null) {
            return $this->result(0, 0, $this->halfDayRemaining($schedule, $leaveFraction), 'absent');
        }

        $in = $in->copy()->startOfMinute();
        $out = $out?->copy()->startOfMinute();

        if ($punches < 2 || $out === null || $out->lte($in)) {
            return $this->result(0, 0, $this->halfDayRemaining($schedule, $leaveFraction), 'incomplete');
        }

        $counted = $in->max($schedule->startsAt($day));

        if ($leaveFraction > 0) {
            $required = (int) round($schedule->required_minutes * (1 - $leaveFraction));
            $minutes = min($required, max(0, $this->minutesBetween($counted, $out)));

            return $this->result($minutes, 0, $required - $minutes, $minutes < $required ? 'undertime' : 'present');
        }

        $latestArrival = $schedule->latestArrivalAt($day);
        $late = max(0, $this->minutesBetween($latestArrival, $in) - $this->breakWorked($schedule, $day, $latestArrival, $in));
        $span = max(0, $this->minutesBetween($counted, $out) - $this->breakWorked($schedule, $day, $counted, $out));
        $allowed = max(0, $schedule->required_minutes - $late);
        $minutes = min($allowed, $span);
        $undertime = $allowed - $minutes;

        return $this->result($minutes, $late, $undertime, $late > 0 ? 'late' : ($undertime > 0 ? 'undertime' : 'present'));
    }

    /**
     * With half-day leave, the half still to be worked is undertime when
     * nothing (or only one punch) was recorded for it.
     */
    private function halfDayRemaining(WorkSchedule $schedule, float $leaveFraction): int
    {
        return $leaveFraction > 0 ? (int) round($schedule->required_minutes * (1 - $leaveFraction)) : 0;
    }

    private function breakWorked(WorkSchedule $schedule, Carbon $day, Carbon $from, Carbon $to): int
    {
        $window = $schedule->breakWindow($day);
        if ($window === null) {
            return 0;
        }

        return max(0, $this->minutesBetween($window[0]->max($from), $window[1]->min($to)));
    }

    private function minutesBetween(Carbon $from, Carbon $to): int
    {
        return intdiv($to->timestamp - $from->timestamp, 60);
    }

    /**
     * @return array{minutes: int, late_minutes: int, undertime_minutes: int, status: string}
     */
    private function result(int $minutes, int $late, int $undertime, string $status): array
    {
        return ['minutes' => $minutes, 'late_minutes' => $late, 'undertime_minutes' => $undertime, 'status' => $status];
    }
}
