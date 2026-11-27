<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\WorkSchedule;
use App\Support\HR\Dtr\WorkSchedules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DTR: HRD maintains the work schedules (hours, late and break rules, rest
 * days) and assigns one to each employee for a date range.
 */
class WorkScheduleController extends Controller
{
    public function show(Request $request): Response
    {
        $this->authorize('viewAny', BiometricPunch::class);

        return Inertia::render('Admin/HR/Dtr/Schedules', [
            'schedules' => WorkSchedule::query()->withCount('assignments')->orderBy('name')->get()->map(fn (WorkSchedule $schedule) => [
                'id' => $schedule->id,
                'name' => $schedule->name,
                'start_time' => substr($schedule->start_time, 0, 5),
                'latest_arrival' => substr($schedule->latest_arrival, 0, 5),
                'required_minutes' => $schedule->required_minutes,
                'break_start' => $schedule->break_start ? substr($schedule->break_start, 0, 5) : null,
                'break_minutes' => $schedule->break_minutes,
                'rest_weekdays' => $schedule->rest_weekdays ?? [],
                'assigned_count' => $schedule->assignments_count,
            ])->values(),
            'assignments' => EmployeeWorkSchedule::query()->with(['employee.user.organizationalUnit', 'schedule:id,name'])
                ->orderByDesc('valid_from')->get()->map(fn (EmployeeWorkSchedule $assignment) => [
                    'id' => $assignment->id,
                    'employee' => $assignment->employee->user->name,
                    'employee_id' => $assignment->employee_id,
                    'photo_url' => $assignment->employee->user->profile_photo_url,
                    'office' => $assignment->employee->user->organizationalUnit?->short_name ?: $assignment->employee->user->organizationalUnit?->name,
                    'schedule' => $assignment->schedule->name,
                    'valid_from' => $assignment->valid_from->toDateString(),
                    'valid_to' => $assignment->valid_to?->toDateString(),
                ])->values(),
            // Everyone in service, with the office and the schedule they follow today (for picking many at once).
            'employees' => $this->employeesWithSchedule(),
            'canManage' => $request->user()->can('manage', BiometricPunch::class),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage', BiometricPunch::class);

        $schedule = WorkSchedule::query()->create($this->validated($request));
        $this->audit()->record(AuditEvent::WorkScheduleSaved, "Added the work schedule {$schedule->name}", null, ['schedule' => $schedule->name]);

        return back()->with('status', "Added {$schedule->name}.");
    }

    public function update(Request $request, WorkSchedule $schedule): RedirectResponse
    {
        $this->authorize('manage', BiometricPunch::class);

        $schedule->update($this->validated($request, $schedule));
        $this->audit()->recordChanges(AuditEvent::WorkScheduleSaved, "Changed the work schedule {$schedule->name}", $schedule, ['schedule' => $schedule->name]);

        return back()->with('status', "Saved {$schedule->name}.");
    }

    public function assign(Request $request, WorkSchedules $schedules): RedirectResponse
    {
        $this->authorize('manage', BiometricPunch::class);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'work_schedule_id' => ['required', 'integer', 'exists:work_schedules,id'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date'],
        ]);

        $employee = Employee::query()->with('user')->findOrFail($data['employee_id']);
        $schedule = WorkSchedule::query()->findOrFail($data['work_schedule_id']);
        $assignment = $schedules->assign($employee, $schedule, Carbon::parse($data['valid_from']), $this->date($data['valid_to'] ?? null));

        $this->audit()->record(AuditEvent::WorkScheduleAssigned, "Assigned {$schedule->name} to {$employee->user->name}", $employee, [
            'schedule' => $schedule->name,
            'valid_from' => $assignment->valid_from->toDateString(),
            'valid_to' => $assignment->valid_to?->toDateString(),
        ]);

        return back()->with('status', "{$employee->user->name} now follows {$schedule->name}.");
    }

    /** Applies one schedule to many employees at once (an update, not a pile of overlap errors). */
    public function assignMany(Request $request, WorkSchedules $schedules): RedirectResponse
    {
        $this->authorize('manage', BiometricPunch::class);

        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
            'work_schedule_id' => ['required', 'integer', 'exists:work_schedules,id'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date'],
            'replace' => ['nullable', 'boolean'],
        ], ['employee_ids.required' => 'Choose at least one employee.', 'employee_ids.min' => 'Choose at least one employee.']);

        $schedule = WorkSchedule::query()->findOrFail($data['work_schedule_id']);
        $employees = Employee::query()->with('user:id,name')->whereKey($data['employee_ids'])->get();
        $result = $schedules->assignMany($employees, $schedule, Carbon::parse($data['valid_from']), $this->date($data['valid_to'] ?? null), (bool) ($data['replace'] ?? true));

        $this->audit()->record(AuditEvent::WorkScheduleAssigned, "Assigned {$schedule->name} to {$result['assigned']} employee(s) at once", null, [
            'schedule' => $schedule->name,
            'valid_from' => Carbon::parse($data['valid_from'])->toDateString(),
            'valid_to' => $data['valid_to'] ?? null,
            'assigned' => $result['assigned'],
            'replaced' => $result['replaced'],
            'skipped' => count($result['skipped']),
            'employees' => $employees->map(fn (Employee $employee) => $employee->user->name)->values()->all(),
        ]);

        $message = "{$schedule->name} now applies to {$result['assigned']} ".($result['assigned'] === 1 ? 'employee' : 'employees')
            .($result['replaced'] > 0 ? " ({$result['replaced']} earlier assignment(s) ended the day before)" : '').'.';

        if ($result['skipped'] === []) {
            return back()->with('status', $message);
        }

        $names = collect($result['skipped'])->take(5)->map(fn (array $row) => "{$row['name']} {$row['reason']}")->implode('; ');
        $more = count($result['skipped']) > 5 ? ' and '.(count($result['skipped']) - 5).' more' : '';
        $skipped = 'Skipped '.count($result['skipped']).": {$names}{$more}.";

        return $result['assigned'] > 0 ? back()->with('status', $message)->with('error', $skipped) : back()->with('error', $skipped);
    }

    public function end(Request $request, EmployeeWorkSchedule $assignment, WorkSchedules $schedules): RedirectResponse
    {
        $this->authorize('manage', BiometricPunch::class);

        $data = $request->validate(['valid_to' => ['nullable', 'date']]);
        $assignment->load(['employee.user', 'schedule']);
        $schedules->end($assignment, $this->date($data['valid_to'] ?? null));

        $this->audit()->record(AuditEvent::WorkScheduleAssigned, "Changed the end date of {$assignment->employee->user->name}'s work schedule", $assignment->employee, [
            'schedule' => $assignment->schedule->name,
            'valid_from' => $assignment->valid_from->toDateString(),
            'valid_to' => $assignment->valid_to?->toDateString(),
        ]);

        return back()->with('status', 'Saved.');
    }

    public function destroy(EmployeeWorkSchedule $assignment, WorkSchedules $schedules): RedirectResponse
    {
        $this->authorize('manage', BiometricPunch::class);

        $assignment->load(['employee.user', 'schedule']);
        $schedules->remove($assignment);

        $this->audit()->record(AuditEvent::WorkScheduleAssigned, "Removed {$assignment->employee->user->name}'s work schedule assignment", $assignment->employee, [
            'schedule' => $assignment->schedule->name,
            'valid_from' => $assignment->valid_from->toDateString(),
            'valid_to' => $assignment->valid_to?->toDateString(),
            'removed' => true,
        ]);

        return back()->with('status', 'Removed.');
    }

    /** Sets one end date on several assignments at once; any it can't be applied to is skipped and named. */
    public function endMany(Request $request, WorkSchedules $schedules): RedirectResponse
    {
        $this->authorize('manage', BiometricPunch::class);

        $data = $request->validate([
            'assignment_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'assignment_ids.*' => ['integer', 'distinct'],
            'valid_to' => ['required', 'date'],
        ], ['assignment_ids.required' => 'Choose at least one assignment.', 'assignment_ids.min' => 'Choose at least one assignment.', 'valid_to.required' => 'Choose the end date.']);

        $to = Carbon::parse($data['valid_to']);
        $done = 0;
        $skipped = [];

        foreach (EmployeeWorkSchedule::query()->with(['employee.user:id,name', 'schedule:id,name'])->whereKey($data['assignment_ids'])->get() as $assignment) {
            try {
                $schedules->end($assignment, $to);
                $done++;
            } catch (ValidationException $exception) {
                $skipped[] = "{$assignment->employee->user->name} ".collect($exception->errors())->flatten()->first();
            }
        }

        $this->audit()->record(AuditEvent::WorkScheduleAssigned, "Set the end date of {$done} work schedule assignment(s) to {$to->toDateString()} at once", null, [
            'valid_to' => $to->toDateString(),
            'ended' => $done,
            'skipped' => count($skipped),
        ]);

        $message = "Ended {$done} ".($done === 1 ? 'assignment' : 'assignments')." on {$to->toFormattedDateString()}.";
        if ($skipped === []) {
            return back()->with('status', $message);
        }

        $note = 'Skipped '.count($skipped).': '.implode('; ', array_slice($skipped, 0, 5)).(count($skipped) > 5 ? ' and '.(count($skipped) - 5).' more' : '').'.';

        return $done > 0 ? back()->with('status', $message)->with('error', $note) : back()->with('error', $note);
    }

    /** Removes several assignments at once (the checked rows of "Who follows which"). */
    public function destroyMany(Request $request, WorkSchedules $schedules): RedirectResponse
    {
        $this->authorize('manage', BiometricPunch::class);

        $data = $request->validate([
            'assignment_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'assignment_ids.*' => ['integer', 'distinct'],
        ], ['assignment_ids.required' => 'Choose at least one assignment.', 'assignment_ids.min' => 'Choose at least one assignment.']);

        $assignments = EmployeeWorkSchedule::query()->with(['employee.user:id,name', 'schedule:id,name'])->whereKey($data['assignment_ids'])->get();
        foreach ($assignments as $assignment) {
            $schedules->remove($assignment);
        }

        $this->audit()->record(AuditEvent::WorkScheduleAssigned, "Removed {$assignments->count()} work schedule assignment(s) at once", null, [
            'removed' => true,
            'count' => $assignments->count(),
            'assignments' => $assignments->map(fn (EmployeeWorkSchedule $assignment) => "{$assignment->employee->user->name}: {$assignment->schedule->name} from {$assignment->valid_from->toDateString()}")->values()->all(),
        ]);

        return back()->with('status', "Removed {$assignments->count()} ".($assignments->count() === 1 ? 'assignment' : 'assignments').'.');
    }

    /**
     * @return Collection<int, array{id: int, name: string, office: ?string, schedule: ?string}>
     */
    private function employeesWithSchedule()
    {
        $current = EmployeeWorkSchedule::query()->with('schedule:id,name')->coveringDate(today())->get()->keyBy('employee_id');

        return Employee::query()->with('user.organizationalUnit')->whereNull('separation_date')->where('employment_status', EmploymentStatus::Permanent)->get()
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->user->name,
                'office' => $employee->user->organizationalUnit?->short_name ?: $employee->user->organizationalUnit?->name,
                'schedule' => $current->get($employee->id)?->schedule?->name,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?WorkSchedule $schedule = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('work_schedules', 'name')->ignore($schedule?->id)],
            'start_time' => ['required', 'date_format:H:i'],
            'latest_arrival' => ['required', 'date_format:H:i', 'after_or_equal:start_time'],
            'required_minutes' => ['required', 'integer', 'between:1,1440'],
            'break_start' => [Rule::requiredIf(fn () => (int) $request->input('break_minutes') > 0), 'nullable', 'date_format:H:i'],
            'break_minutes' => ['nullable', 'integer', 'between:0,600'],
            'rest_weekdays' => ['nullable', 'array'],
            'rest_weekdays.*' => ['integer', 'between:1,5'],
        ]);

        $data['break_minutes'] = (int) ($data['break_minutes'] ?? 0);
        $data['break_start'] = $data['break_minutes'] > 0 ? ($data['break_start'] ?? null) : null;
        $data['rest_weekdays'] = array_values(array_unique(array_map('intval', $data['rest_weekdays'] ?? [])));

        return $data;
    }

    private function date(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
}
