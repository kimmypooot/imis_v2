<?php

namespace App\Support\HR\Dtr;

use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\WorkSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DTR: which work schedule an employee follows, for a date range. For one
 * employee the ranges never overlap, so a day has at most one schedule. A
 * missing end means "still". Changes lock the employee row so two
 * simultaneous requests can't both pass the overlap check.
 */
class WorkSchedules
{
    public function on(Employee $employee, Carbon $day): ?WorkSchedule
    {
        return EmployeeWorkSchedule::query()
            ->with('schedule')
            ->where('employee_id', $employee->id)
            ->coveringDate($day)
            ->first()?->schedule;
    }

    public function assign(Employee $employee, WorkSchedule $schedule, Carbon $from, ?Carbon $to): EmployeeWorkSchedule
    {
        $this->assertRange($from, $to);

        return $this->locked($employee, function () use ($employee, $schedule, $from, $to) {
            $this->assertNoOverlap($employee, $from, $to);

            return EmployeeWorkSchedule::query()->create([
                'employee_id' => $employee->id,
                'work_schedule_id' => $schedule->id,
                'valid_from' => $from->toDateString(),
                'valid_to' => $to?->toDateString(),
            ]);
        });
    }

    /**
     * Gives the same schedule to many employees at once. With `$replace`, an
     * assignment that began before `$from` is ended the day before it (so
     * this is an update, not an overlap error); anyone it can't be applied
     * to cleanly is skipped with the reason, and the rest still go through.
     *
     * @param  iterable<int, Employee>  $employees
     * @return array{assigned: int, replaced: int, skipped: array<int, array{name: string, reason: string}>}
     */
    public function assignMany(iterable $employees, WorkSchedule $schedule, Carbon $from, ?Carbon $to, bool $replace = true): array
    {
        $this->assertRange($from, $to);
        $result = ['assigned' => 0, 'replaced' => 0, 'skipped' => []];

        foreach ($employees as $employee) {
            $outcome = $this->locked($employee, function () use ($employee, $schedule, $from, $to, $replace) {
                $overlapping = EmployeeWorkSchedule::query()->where('employee_id', $employee->id)->get()
                    ->filter(fn (EmployeeWorkSchedule $existing) => ($to === null || $existing->valid_from->lte($to))
                        && ($existing->valid_to === null || $existing->valid_to->gte($from)));

                // Already on this schedule for the whole stretch: nothing to do.
                if ($overlapping->contains(fn (EmployeeWorkSchedule $existing) => $existing->work_schedule_id === $schedule->id
                    && $existing->valid_from->lte($from) && ($existing->valid_to === null || ($to !== null && $existing->valid_to->gte($to))))) {
                    return ['skip' => "already follows {$schedule->name} for those dates"];
                }

                if ($overlapping->isNotEmpty()) {
                    if (! $replace) {
                        return ['skip' => 'already has a schedule for part of those dates'];
                    }
                    foreach ($overlapping as $existing) {
                        if ($existing->valid_from->gte($from)) {
                            return ['skip' => 'has a schedule starting '.$existing->valid_from->format('M j, Y').', inside those dates'];
                        }
                        if ($to !== null && ($existing->valid_to === null || $existing->valid_to->gt($to))) {
                            return ['skip' => 'has a schedule that runs past the end date you chose'];
                        }
                    }
                    foreach ($overlapping as $existing) {
                        $existing->update(['valid_to' => $from->copy()->subDay()->toDateString()]);
                    }
                }

                EmployeeWorkSchedule::query()->create([
                    'employee_id' => $employee->id,
                    'work_schedule_id' => $schedule->id,
                    'valid_from' => $from->toDateString(),
                    'valid_to' => $to?->toDateString(),
                ]);

                return ['replaced' => $overlapping->count()];
            });

            if (isset($outcome['skip'])) {
                $result['skipped'][] = ['name' => $employee->user->name, 'reason' => $outcome['skip']];

                continue;
            }
            $result['assigned']++;
            $result['replaced'] += $outcome['replaced'];
        }

        return $result;
    }

    public function end(EmployeeWorkSchedule $assignment, ?Carbon $to): EmployeeWorkSchedule
    {
        $this->assertRange($assignment->valid_from, $to);

        return $this->locked($assignment->employee, function () use ($assignment, $to) {
            $this->assertNoOverlap($assignment->employee, $assignment->valid_from, $to, $assignment->id);
            $assignment->update(['valid_to' => $to?->toDateString()]);

            return $assignment;
        });
    }

    public function remove(EmployeeWorkSchedule $assignment): void
    {
        $assignment->delete();
    }

    private function assertRange(Carbon $from, ?Carbon $to): void
    {
        if ($to && $to->lt($from)) {
            throw ValidationException::withMessages(['valid_to' => 'The end date can\'t be before the start date.']);
        }
    }

    private function locked(Employee $employee, callable $callback): mixed
    {
        return DB::transaction(function () use ($employee, $callback) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            return $callback();
        });
    }

    private function assertNoOverlap(Employee $employee, Carbon $from, ?Carbon $to, ?int $exceptId = null): void
    {
        $overlaps = EmployeeWorkSchedule::query()
            ->where('employee_id', $employee->id)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->get()
            ->contains(fn (EmployeeWorkSchedule $existing) => ($to === null || $existing->valid_from->lte($to))
                && ($existing->valid_to === null || $existing->valid_to->gte($from)));

        if ($overlaps) {
            throw ValidationException::withMessages(['valid_from' => 'This employee already has a work schedule for part of those dates.']);
        }
    }
}
