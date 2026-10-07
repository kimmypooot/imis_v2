<?php

namespace App\Support\HR;

use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Length of service in the CSC, for loyalty awards: 10 years, then every 5
 * (CSC). Only service in the CSC counts, so it runs from the date someone
 * joined the CSC — set for those who came from another agency — else from
 * their original appointment date (government service began here).
 */
class LoyaltyAwards
{
    public const FIRST_AWARD_YEARS = 10;

    public const AWARD_EVERY_YEARS = 5;

    /** When their CSC service began, if known. */
    public function start(Employee $employee): ?Carbon
    {
        return $employee->csc_entry_date ?? $employee->original_appointment_date;
    }

    /**
     * Years and months in the CSC (to separation, for those who left), the
     * last award earned and the next one due.
     *
     * @return ?array{since: string, from_other_agency: bool, years: int, months: int, label: string, last_award: ?int, next_award: ?int, next_award_date: ?string, award_this_year: ?int}
     */
    public function summary(Employee $employee, ?Carbon $today = null): ?array
    {
        $start = $this->start($employee);
        if ($start === null) {
            return null;
        }

        $today ??= today();
        $separated = $employee->separation_date !== null && $employee->separation_date->lte($today);
        $until = $separated ? $employee->separation_date : $today;
        $months = $start->lte($until) ? (int) $start->diffInMonths($until) : 0;
        $years = intdiv($months, 12);

        $last = $years >= self::FIRST_AWARD_YEARS
            ? self::FIRST_AWARD_YEARS + intdiv($years - self::FIRST_AWARD_YEARS, self::AWARD_EVERY_YEARS) * self::AWARD_EVERY_YEARS
            : null;
        $next = $separated ? null : ($last === null ? self::FIRST_AWARD_YEARS : $last + self::AWARD_EVERY_YEARS);
        $completesThisYear = $today->year - $start->year;

        return [
            'since' => $start->toDateString(),
            'from_other_agency' => $employee->csc_entry_date !== null && $employee->original_appointment_date !== null
                && $employee->csc_entry_date->gt($employee->original_appointment_date),
            'years' => $years,
            'months' => $months % 12,
            'label' => $this->label($years, $months % 12),
            'last_award' => $last,
            'next_award' => $next,
            'next_award_date' => $next !== null ? $start->copy()->addYearsNoOverflow($next)->toDateString() : null,
            // A milestone completed in this calendar year (the dashboard's list).
            'award_this_year' => ! $separated && $this->isMilestone($completesThisYear) ? $completesThisYear : null,
        ];
    }

    public function isMilestone(int $years): bool
    {
        return $years >= self::FIRST_AWARD_YEARS && ($years - self::FIRST_AWARD_YEARS) % self::AWARD_EVERY_YEARS === 0;
    }

    private function label(int $years, int $months): string
    {
        $parts = array_filter([
            $years ? $years.' '.($years === 1 ? 'yr' : 'yrs') : null,
            $months ? $months.' '.($months === 1 ? 'mo' : 'mos') : null,
        ]);

        return $parts ? implode(' ', $parts) : 'Under a month';
    }
}
