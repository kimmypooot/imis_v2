<?php

namespace App\Support\HR\Leave;

use App\Enums\HolidayType;
use App\Models\Employee;
use App\Models\FwaArrangement;
use App\Models\Holiday;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

/**
 * HR-6: counts leave days as the legacy WorkingDayService did — weekdays
 * between two dates (inclusive), skipping regular and special non-working
 * holidays; a half day on a single working day counts 0.5. Leave counted
 * in calendar days (maternity) counts every day.
 *
 * Given the employee, an approved compressed work week's rest days are
 * skipped too (HR-6c).
 */
class WorkingDays
{
    public function count(Carbon|string $from, Carbon|string $to, bool $halfDay = false, bool $calendarDays = false, ?Employee $employee = null): float
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($from->gt($to)) {
            return 0.0;
        }

        if ($calendarDays) {
            return (float) ($from->diffInDays($to) + 1);
        }

        $daysOff = $this->holidaysBetween($from, $to);
        $restDays = $employee ? $this->restDays($employee, $from, $to) : [];
        $working = collect(CarbonPeriod::create($from, $to))
            ->filter(fn (Carbon $day) => ! $day->isWeekend() && ! isset($daysOff[$day->toDateString()]) && ! in_array($day->isoWeekday(), $restDays, true))
            ->count();

        if ($halfDay) {
            return $from->equalTo($to) && $working === 1 ? 0.5 : 0.0;
        }

        return (float) $working;
    }

    public function isWorkingDay(Carbon|string $date): bool
    {
        return $this->count($date, $date) === 1.0;
    }

    /**
     * Weekdays in a month, holidays not subtracted — the denominator the
     * legacy LMS used to pro-rate accrual for leave without pay.
     */
    public function weekdaysInMonth(int $year, int $month): int
    {
        $start = Carbon::create($year, $month, 1);

        return collect(CarbonPeriod::create($start, $start->copy()->endOfMonth()))
            ->reject(fn (Carbon $day) => $day->isWeekend())
            ->count();
    }

    /**
     * HR-6c: the rest days of an approved compressed work week running in
     * the period (the latest one), as the legacy WorkingDayService did.
     *
     * @return array<int, int> ISO weekdays
     */
    private function restDays(Employee $employee, Carbon $from, Carbon $to): array
    {
        return FwaArrangement::query()->where('employee_id', $employee->id)->where('type', 'cww')
            ->inEffect($from, $to)->latest('effective_from')->first()?->restDays() ?? [];
    }

    /**
     * @return array<string, true> days off (date => true)
     */
    private function holidaysBetween(Carbon $from, Carbon $to): array
    {
        return Holiday::query()
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->whereIn('type', collect(HolidayType::cases())->filter(fn (HolidayType $type) => $type->isDayOff())->map(fn (HolidayType $type) => $type->value)->values())
            ->pluck('date')
            ->mapWithKeys(fn ($date) => [Carbon::parse($date)->toDateString() => true])
            ->all();
    }
}
