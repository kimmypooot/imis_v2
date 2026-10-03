<?php

namespace App\Support\HR;

use App\Enums\LeaveApplicationStatus;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\PlantillaAppointment;
use App\Models\SalarySchedule;
use App\Support\HR\Plantilla\SalarySchedules;
use App\Support\Settings\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * HR-5: an employee's Service Record, in the standard CSC columns —
 * inclusive dates; designation, status and annual salary; station and
 * branch; leave without pay; separation date and cause.
 *
 * Nothing is stored: the lines are built from the plantilla periods
 * (HR-4). A period is split into separate lines where the station changes
 * (a reassignment, HR-1b) or a new salary schedule takes effect, as a
 * service record shows them. Leave without pay comes from approved leave
 * applications (HR-6b).
 */
class ServiceRecords
{
    public function __construct(
        private readonly EmployeeAssignments $assignments,
        private readonly SalarySchedules $schedules,
    ) {}

    /**
     * @return array{person: array<string, ?string>, lines: Collection<int, array<string, mixed>>}
     */
    public function build(Employee $employee): array
    {
        $employee->loadMissing(['user.organizationalUnit', 'pdsProfile']);

        // An incomplete link (no grade, step, status or start date yet) isn't a line until HR completes it.
        $periods = $employee->plantillaAppointments()->complete()->with('item')->orderBy('date_from')->orderBy('id')->get();
        $scheduleDates = SalarySchedule::query()->orderBy('effective_date')->pluck('effective_date');
        $moveDates = $this->assignments->reassignmentDates($employee);
        $branch = (string) Settings::get('hr_service_record_branch');
        // HR-6b: approved leave with days without pay, by its first day.
        $withoutPay = LeaveApplication::query()->where('employee_id', $employee->id)
            ->where('status', LeaveApplicationStatus::Approved)->where('days_without_pay', '>', 0)
            ->get(['date_from', 'days_without_pay']);

        $lines = $periods->flatMap(function (PlantillaAppointment $period) use ($employee, $scheduleDates, $moveDates, $branch, $withoutPay) {
            $until = $period->date_to ?? today();
            $breaks = $scheduleDates->concat($moveDates)
                ->filter(fn (Carbon $date) => $date->gt($period->date_from) && $date->lte($until))
                ->map(fn (Carbon $date) => $date->toDateString())
                ->unique()->sort()->values();

            $starts = collect([$period->date_from->toDateString()])->concat($breaks);

            return $starts->map(function (string $start, int $index) use ($starts, $period, $employee, $branch, $withoutPay) {
                $from = Carbon::parse($start);
                $last = $index === $starts->count() - 1;
                $to = $last ? $period->date_to : Carbon::parse($starts[$index + 1])->subDay();
                $monthly = $this->schedules->monthlySalary($period->item->salary_grade, $period->step, $from);
                $lwop = (float) $withoutPay
                    ->filter(fn (LeaveApplication $leave) => $leave->date_from->gte($from) && $leave->date_from->lte($to ?? today()))
                    ->sum('days_without_pay');

                return [
                    'date_from' => $from->toDateString(),
                    'date_to' => $to?->toDateString(),
                    'designation' => $period->item->position_title,
                    'item_number' => $period->item->item_number,
                    'salary_grade' => $period->item->salary_grade,
                    'step' => $period->step,
                    'status' => $period->status->label(),
                    'annual_salary' => $monthly !== null ? round($monthly * 12, 2) : null,
                    'station' => $this->assignments->officeOn($employee, $from)?->name,
                    'branch' => $branch,
                    // Days of approved leave without pay that began in this line.
                    'leave_without_pay' => $lwop > 0 ? round($lwop, 3) : null,
                    'separation_date' => $last && $period->date_to ? $period->date_to->toDateString() : null,
                    'separation_cause' => $last && $period->date_to ? $period->end_reason : null,
                ];
            });
        })->values();

        $user = $employee->user;

        return [
            'person' => [
                'surname' => $user->last_name ?? $user->name,
                'first_name' => $user->first_name,
                'middle_name' => $user->middle_name,
                'date_of_birth' => $user->birthday?->toDateString(),
                'place_of_birth' => $employee->pdsProfile?->place_of_birth,
                'employee_number' => $employee->employee_number,
            ],
            'lines' => $lines,
        ];
    }

    /**
     * Who certifies the printout (System Settings → Human Resources).
     *
     * @return array{name: ?string, position: ?string, organization: ?string}
     */
    public function certification(): array
    {
        return [
            'name' => Settings::get('hr_certifying_officer_name'),
            'position' => Settings::get('hr_certifying_officer_position'),
            'organization' => Settings::get('organization_name'),
        ];
    }
}
