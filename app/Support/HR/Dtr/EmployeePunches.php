<?php

namespace App\Support\HR\Dtr;

use App\Models\BiometricPunch;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * DTR: an employee's punches by day, found by the biometric ID on their
 * record (`employees.biometric_id`). A day runs from 04:00 to 04:00, so a
 * punch just after midnight closes the night before. Punches within two
 * minutes of the previous one are double taps and count once. The first
 * punch of the day is the time in and the last the time out; the ones
 * between are shown (a punched break) but not used to calculate.
 */
class EmployeePunches
{
    /** Hour of the morning before which a punch belongs to the previous day. */
    public const DAY_STARTS_AT = 4;

    /** Punches closer together than this many minutes count once. */
    public const DOUBLE_TAP_MINUTES = 2;

    /**
     * @return Collection<string, array{in: Carbon, out: Carbon, count: int, times: list<Carbon>}> keyed by Y-m-d
     */
    public function byDay(Employee $employee, Carbon $from, Carbon $to): Collection
    {
        if (blank($employee->biometric_id)) {
            return collect();
        }

        $windowStart = $from->copy()->startOfDay()->addHours(self::DAY_STARTS_AT);
        $windowEnd = $to->copy()->startOfDay()->addDay()->addHours(self::DAY_STARTS_AT);

        $punches = BiometricPunch::query()
            ->where('biometric_id', $employee->biometric_id)
            ->whereNull('voided_at')
            ->where('punched_at', '>=', $windowStart->format('Y-m-d H:i:s'))
            ->where('punched_at', '<', $windowEnd->format('Y-m-d H:i:s'))
            ->orderBy('punched_at')
            ->pluck('punched_at');

        $kept = [];
        foreach ($punches as $punch) {
            $previous = end($kept);
            if ($previous && $previous->diffInSeconds($punch) < self::DOUBLE_TAP_MINUTES * 60) {
                continue;
            }
            $kept[] = $punch;
        }

        return collect($kept)
            ->groupBy(fn (Carbon $punch) => $punch->copy()->subHours(self::DAY_STARTS_AT)->toDateString())
            ->map(fn (Collection $day) => ['in' => $day->first(), 'out' => $day->last(), 'count' => $day->count(), 'times' => $day->values()->all()])
            ->sortKeys();
    }
}
