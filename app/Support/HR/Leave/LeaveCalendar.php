<?php

namespace App\Support\HR\Leave;

use App\Enums\LeaveApplicationStatus;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveApplication;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everyone's leave for a month, for HR's Leave Calendar: who is out on
 * which day. Each application lists the days it covers in the month, so
 * weekends and day-off holidays are skipped (leave counted in calendar
 * days, like maternity, keeps every day). Approved leave, plus leave
 * still under review or returned when asked for.
 */
class LeaveCalendar
{
    /**
     * @param  array{office?: int|null, type?: int|null, pending?: bool}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function leave(Carbon $month, array $filters = []): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $daysOff = $this->holidays($start, $end)->where('day_off', true)->pluck('date')->flip();

        $statuses = ($filters['pending'] ?? false)
            ? [LeaveApplicationStatus::Approved, LeaveApplicationStatus::ForReview, LeaveApplicationStatus::Returned]
            : [LeaveApplicationStatus::Approved];

        return LeaveApplication::query()
            ->whereIn('status', $statuses)
            ->whereDate('date_from', '<=', $end)->whereDate('date_to', '>=', $start)
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('leave_type_id', $type))
            ->when($filters['office'] ?? null, fn ($query, $office) => $query->whereHas('employee.user', fn ($user) => $user->where('organizational_unit_id', $office)))
            ->with(['leaveType', 'employee.user.organizationalUnit'])
            ->orderBy('date_from')->get()
            ->map(function (LeaveApplication $application) use ($start, $end, $daysOff) {
                $from = $application->date_from->max($start);
                $to = $application->date_to->min($end);
                $dates = collect(CarbonPeriod::create($from, $to))
                    ->filter(fn (Carbon $day) => $application->leaveType->counts_calendar_days || (! $day->isWeekend() && ! $daysOff->has($day->toDateString())))
                    ->map(fn (Carbon $day) => $day->toDateString())
                    ->values();
                $unit = $application->employee->user->organizationalUnit;

                return [
                    'id' => $application->id,
                    'employee_id' => $application->employee_id,
                    'reference_number' => $application->reference_number,
                    'name' => $application->employee->user->name,
                    'surname' => $application->employee->user->last_name ?: $application->employee->user->name,
                    'office' => $unit?->short_name ?: $unit?->name,
                    'type_code' => $application->leaveType->code,
                    'type_name' => $application->leaveType->name,
                    'date_from' => $application->date_from->toDateString(),
                    'date_to' => $application->date_to->toDateString(),
                    'is_half_day' => $application->is_half_day,
                    'half_day_period' => $application->half_day_period,
                    'days' => (float) $application->days,
                    'status' => $application->status->value,
                    'status_label' => $application->status->label(),
                    'dates' => $dates,
                ];
            })
            ->filter(fn (array $entry) => $entry['dates']->isNotEmpty())
            ->values();
    }

    /**
     * Co-workers in the employee's division (organizational unit) who have
     * filed leave this month, for the portal calendar: who is out and
     * whether it's approved yet. The type of leave and the application
     * stay private (RA 10173). With `$everyone` (see CalendarAudience), every
     * office's, each with its office.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function colleagues(Employee $employee, Carbon $month, bool $everyone = false): Collection
    {
        $office = $employee->user->organizational_unit_id;

        if (! $everyone && ! $office) {
            return collect();
        }

        return $this->leave($month, ['office' => $everyone ? null : $office, 'pending' => true])
            ->reject(fn (array $entry) => $entry['employee_id'] === $employee->id)
            ->map(fn (array $entry) => collect($entry)->only(['id', 'name', 'surname', 'office', 'date_from', 'date_to', 'is_half_day', 'half_day_period', 'days', 'status', 'status_label', 'dates'])->all())
            ->values();
    }

    /**
     * @return Collection<int, array{date: string, name: string, day_off: bool}>
     */
    public function holidays(Carbon $start, Carbon $end): Collection
    {
        return Holiday::query()->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)->orderBy('date')->get()
            ->map(fn (Holiday $holiday) => ['date' => $holiday->date->toDateString(), 'name' => $holiday->name, 'day_off' => $holiday->type->isDayOff()]);
    }
}
