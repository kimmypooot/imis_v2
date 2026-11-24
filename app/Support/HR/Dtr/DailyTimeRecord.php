<?php

namespace App\Support\HR\Dtr;

use App\Enums\LeaveApplicationStatus;
use App\Models\DtrAdjustment;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\Holiday;
use App\Models\LeaveApplication;
use App\Models\WorkSchedule;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * DTR: the daily time record for an employee over a period, one row per
 * day plus the totals the CS Form No. 48 prints. Everything is loaded once
 * for the period, and every amount is in whole minutes.
 *
 * Per day, in this order:
 * 1. An HRD adjustment: `credit` and `travel` credit a full day; `times`
 *    replaces the punches.
 * 2. A rest day (weekend, or the schedule's rest weekday): nothing is
 *    required or credited; a punch is shown and flagged `worked_day_off`.
 * 3. No schedule assigned: the punches are shown and nothing is calculated.
 * 4. A holiday that is a day off: credits a full day.
 * 5. Approved leave: a full day credits a full day (leave without pay,
 *    nothing); half-day leave credits half and the punches are judged for
 *    the other half.
 * 6. A working day, by Rule B (DayCalculator). A day that has not happened
 *    yet, or today before anyone has punched, is `upcoming`, not absent.
 */
class DailyTimeRecord
{
    public function __construct(
        private readonly EmployeePunches $punches = new EmployeePunches,
        private readonly DayCalculator $calculator = new DayCalculator,
    ) {}

    /**
     * @return array{days: Collection<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function build(Employee $employee, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        $punches = $this->punches->byDay($employee, $from, $to);
        $holidays = Holiday::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()
            ->keyBy(fn (Holiday $holiday) => $holiday->date->toDateString());
        $adjustments = DtrAdjustment::query()->where('employee_id', $employee->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()
            ->keyBy(fn (DtrAdjustment $adjustment) => $adjustment->date->toDateString());
        $leave = $this->leaveByDay($employee, $from, $to);
        $assignments = EmployeeWorkSchedule::query()->with('schedule')->where('employee_id', $employee->id)
            ->where('valid_from', '<=', $to->toDateString())
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $from->toDateString()))
            ->get();

        $days = collect(CarbonPeriod::create($from, $to))->map(fn (Carbon $day) => $this->day(
            $day,
            $punches->get($day->toDateString()),
            $this->scheduleOn($assignments, $day),
            $holidays->get($day->toDateString()),
            $leave[$day->toDateString()] ?? null,
            $adjustments->get($day->toDateString()),
        ))->values();

        return ['days' => $days, 'totals' => $this->totals($days, $holidays)];
    }

    /**
     * @param  array{in: Carbon, out: Carbon, count: int}|null  $punch
     * @param  array{fraction: float, name: string, without_pay: bool}|null  $leave
     * @return array<string, mixed>
     */
    private function day(Carbon $day, ?array $punch, ?WorkSchedule $schedule, ?Holiday $holiday, ?array $leave, ?DtrAdjustment $adjustment): array
    {
        $in = $punch['in'] ?? null;
        $out = $punch['out'] ?? null;
        $count = $punch['count'] ?? 0;
        $flags = [];

        if ($adjustment?->kind === 'times') {
            // Whichever time HRD leaves empty stays as punched.
            $rawOut = $count > 1 ? $out : null;
            $lone = $count === 1 ? $in : null;
            $in = $adjustment->time_in ? $day->copy()->setTimeFromTimeString($adjustment->time_in) : $in;
            // A single punch is the time out when HRD supplies the missing time in.
            $out = $adjustment->time_out
                ? $day->copy()->setTimeFromTimeString($adjustment->time_out)
                : ($rawOut ?? ($adjustment->time_in && $lone && $lone->gt($in) ? $lone : null));
            $count = $in && $out ? 2 : ($in ? 1 : 0);
        }
        if ($adjustment) {
            $flags[] = 'adjusted';
        }

        // Four punches mean the break was punched: the second and third are its out and in.
        $times = $adjustment ? [] : ($punch['times'] ?? []);
        $breakOut = count($times) === 4 ? $times[1]->format('H:i') : null;
        $breakIn = count($times) === 4 ? $times[2]->format('H:i') : null;

        $row = function (string $status, int $minutes = 0, string $remark = '', int $late = 0, int $undertime = 0, int $absent = 0) use ($day, &$in, &$out, &$count, &$flags, $breakOut, $breakIn, $adjustment): array {
            return [
                'date' => $day->toDateString(),
                'weekday' => $day->isoWeekday(),
                'in' => $in?->format('H:i'),
                'break_out' => $breakOut,
                'break_in' => $breakIn,
                'adjustment_id' => $adjustment?->id,
                'adjustment' => $adjustment ? [
                    'id' => $adjustment->id,
                    'kind' => $adjustment->kind,
                    'time_in' => $adjustment->time_in ? substr($adjustment->time_in, 0, 5) : null,
                    'time_out' => $adjustment->time_out ? substr($adjustment->time_out, 0, 5) : null,
                    'remark' => $adjustment->remark,
                    'by_employee' => (bool) $adjustment->entered_by_employee,
                    'verified' => $adjustment->verified_at !== null,
                ] : null,
                'out' => $out && $count > 1 ? $out->format('H:i') : null,
                'minutes' => $minutes,
                'late_minutes' => $late,
                'undertime_minutes' => $undertime,
                // The unworked half of a half-day leave: an absence, not undertime.
                'absent_minutes' => $absent,
                'status' => $status,
                'remark' => $remark,
                'flags' => $flags,
            ];
        };

        $required = $schedule?->required_minutes ?? 0;

        if ($adjustment && in_array($adjustment->kind, ['credit', 'travel'], true)) {
            return $row($adjustment->kind, $required, $adjustment->remark);
        }

        $restDay = $day->isWeekend() || ($schedule?->isRestWeekday($day) ?? false);
        if ($restDay) {
            if ($punch) {
                $flags[] = 'worked_day_off';
            }

            return $row($day->isWeekend() ? 'weekend' : 'rest_day', 0, $day->isWeekend() ? 'Weekend' : $this->restLabel($schedule));
        }

        if ($schedule === null) {
            return $row('no_schedule', 0, $holiday?->name ?? '');
        }

        if ($holiday?->type->isDayOff() && ! $adjustment) {
            return $row('holiday', $required, $holiday->name);
        }

        if ($leave && $leave['fraction'] >= 1 && ! $adjustment) {
            return $row('leave', $leave['without_pay'] ? 0 : $required, $leave['name']);
        }

        if ($day->gt(today()) || ($day->isToday() && ! $punch && ! $adjustment)) {
            return $row('upcoming');
        }

        $result = $this->calculator->forWorkingDay($schedule, $day, $in, $out, $count, $leave && ! $adjustment ? $leave['fraction'] : 0.0);

        if ($leave && ! $adjustment) {
            $credit = $leave['without_pay'] ? 0 : (int) round($required * $leave['fraction']);

            // Nothing (or one punch) for the other half is an absence, which is without pay; only a short half is undertime.
            $missing = in_array($result['status'], ['absent', 'incomplete'], true) ? $result['undertime_minutes'] : 0;

            return $row('leave', $result['minutes'] + $credit, 'Half-day '.$leave['name'], 0, $result['undertime_minutes'] - $missing, $missing);
        }

        // HRD's reason shows instead of the usual remark, so a correction is never mistaken for a punch.
        $remark = $adjustment?->kind === 'times'
            ? ($adjustment->entered_by_employee ? 'Employee-entered: ' : '').$adjustment->remark
            : $this->remark($result['status']);

        return $row($result['status'], $result['minutes'], $remark, $result['late_minutes'], $result['undertime_minutes']);
    }

    private function remark(string $status): string
    {
        return match ($status) {
            'present' => 'OK',
            'late' => 'Late',
            'undertime' => 'Undertime',
            'incomplete' => 'Incomplete punches',
            'absent' => 'Absent',
            default => '',
        };
    }

    private function restLabel(?WorkSchedule $schedule): string
    {
        return $schedule && str_contains(strtolower($schedule->name), 'compressed') ? 'Compressed Work Week' : 'Rest day';
    }

    /**
     * @param  Collection<int, EmployeeWorkSchedule>  $assignments
     */
    private function scheduleOn(Collection $assignments, Carbon $day): ?WorkSchedule
    {
        return $assignments->first(fn (EmployeeWorkSchedule $assignment) => $assignment->valid_from->lte($day)
            && ($assignment->valid_to === null || $assignment->valid_to->gte($day)))?->schedule;
    }

    /**
     * Approved leave by date: a full day, or half a day when the leave is
     * a single half day.
     *
     * @return array<string, array{fraction: float, name: string, without_pay: bool}>
     */
    private function leaveByDay(Employee $employee, Carbon $from, Carbon $to): array
    {
        $byDay = [];

        LeaveApplication::query()
            ->with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('status', LeaveApplicationStatus::Approved)
            ->whereDate('date_from', '<=', $to)->whereDate('date_to', '>=', $from)
            ->orderBy('date_from')->get()
            ->each(function (LeaveApplication $application) use (&$byDay, $from, $to) {
                foreach ($application->coveredDates() as $date) {
                    $day = Carbon::parse($date);
                    if ($day->lt($from) || $day->gt($to) || isset($byDay[$day->toDateString()])) {
                        continue;
                    }

                    $byDay[$day->toDateString()] = [
                        'fraction' => $application->is_half_day && $application->date_from->equalTo($application->date_to) ? 0.5 : 1.0,
                        'name' => $application->leaveType->name,
                        'without_pay' => $application->leaveType->code === 'LWOP',
                    ];
                }
            });

        return $byDay;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $days
     * @param  Collection<string, Holiday>  $holidays
     * @return array<string, mixed>
     */
    private function totals(Collection $days, Collection $holidays): array
    {
        $weekdays = $days->filter(fn (array $day) => $day['weekday'] < 6);

        return [
            'minutes' => $days->sum('minutes'),
            'late_minutes' => $days->sum('late_minutes'),
            'undertime_minutes' => $days->sum('undertime_minutes'),
            'absences' => $days->where('status', 'absent')->count(),
            'late_count' => $days->where('late_minutes', '>', 0)->count(),
            'undertime_count' => $days->where('undertime_minutes', '>', 0)->count(),
            'half_day_absences' => $days->where('absent_minutes', '>', 0)->count(),
            'leave_days' => (float) $days->filter(fn (array $day) => $day['status'] === 'leave')
                ->sum(fn (array $day) => str_starts_with($day['remark'], 'Half-day') ? 0.5 : 1.0),
            'travel_days' => $days->where('status', 'travel')->count(),
            'regular_days' => $weekdays->count(),
            'weekend_days' => $days->count() - $weekdays->count(),
            'holiday_days' => $weekdays->filter(fn (array $day) => $holidays->get($day['date'])?->type->isDayOff() ?? false)->count(),
        ];
    }
}
