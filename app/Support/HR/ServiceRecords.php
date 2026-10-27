<?php

namespace App\Support\HR;

use App\Enums\LeaveApplicationStatus;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\PlantillaAppointment;
use App\Models\SalarySchedule;
use App\Models\ServiceRecordEntry;
use App\Models\User;
use App\Support\HR\Plantilla\SalarySchedules;
use App\Support\Settings\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * HR-5: an employee's Service Record, in the standard CSC columns —
 * inclusive dates; designation, status and annual salary; station and
 * branch; leave without pay; separation date and cause.
 *
 * Nothing is stored: the lines are built from the plantilla periods
 * (HR-4). A period is split into separate lines where the station changes
 * (a reassignment, HR-1b) or a new salary schedule takes effect, as a
 * service record shows them. Leave without pay comes from approved leave
 * applications (HR-6b). Service in another agency, which no plantilla item
 * here holds, is entered by HR as lines of its own (ServiceRecordEntry);
 * this is their only writer.
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
                $monthly = $this->schedules->salaryOf($period, $from);
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
                    'source' => 'plantilla',
                ];
            });
        })
            // Service in other agencies, entered by HR, in date order with the rest.
            ->concat($employee->serviceRecordEntries()->get()->map(fn (ServiceRecordEntry $entry) => $this->presentEntry($entry)))
            ->sortBy('date_from')
            ->values();

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

    // ── Lines entered by hand (other agencies) ──────────────────────

    /**
     * @return array<string, array<int, mixed>>
     */
    public function entryRules(): array
    {
        return [
            'date_from' => ['required', 'date', 'after:1900-01-01'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from', 'before_or_equal:today'],
            'designation' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:50'],
            'salary_grade' => ['nullable', 'integer', 'between:1,33'],
            'step' => ['nullable', 'integer', 'between:1,'.SalarySchedules::STEPS],
            'annual_salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'agency' => ['required', 'string', 'max:255'],
            'station' => ['nullable', 'string', 'max:255'],
            'branch' => ['required', 'string', 'max:50'],
            'leave_without_pay' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'separation_date' => ['nullable', 'date', 'after_or_equal:date_from'],
            'separation_cause' => ['nullable', 'required_with:separation_date', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function entryAttributes(): array
    {
        return ['date_from' => 'start date', 'date_to' => 'end date', 'salary_grade' => 'salary grade', 'annual_salary' => 'annual salary',
            'leave_without_pay' => 'leave without pay', 'separation_date' => 'separation date', 'separation_cause' => 'cause of separation'];
    }

    /**
     * Adds a line, or corrects one. The dates may not overlap the person's
     * other service (plantilla periods or other lines).
     *
     * @param  array<string, mixed>  $data  validated
     */
    public function saveEntry(Employee $employee, array $data, User $actor, ?ServiceRecordEntry $entry = null): ServiceRecordEntry
    {
        $from = Carbon::parse($data['date_from']);
        $to = Carbon::parse($data['date_to']);

        if ($overlap = $this->overlapping($employee, $from, $to, $entry)) {
            throw ValidationException::withMessages(['date_from' => "These dates overlap {$overlap}."]);
        }

        $entry ??= new ServiceRecordEntry;
        $entry->fill(collect($data)->only($entry->getFillable())->all());
        $entry->forceFill(['employee_id' => $employee->id, 'recorded_by' => $entry->recorded_by ?? $actor->id])->save();

        return $entry;
    }

    public function removeEntry(ServiceRecordEntry $entry): void
    {
        $entry->delete();
    }

    /**
     * A line as the Service Record shows it.
     *
     * @return array<string, mixed>
     */
    public function presentEntry(ServiceRecordEntry $entry): array
    {
        return [
            'date_from' => $entry->date_from->toDateString(),
            'date_to' => $entry->date_to->toDateString(),
            'designation' => $entry->designation,
            'item_number' => null,
            'salary_grade' => $entry->salary_grade,
            'step' => $entry->step,
            'status' => $entry->status,
            'annual_salary' => $entry->annual_salary !== null ? (float) $entry->annual_salary : null,
            'station' => collect([$entry->agency, $entry->station])->filter()->implode(', '),
            'branch' => $entry->branch,
            'leave_without_pay' => $entry->leave_without_pay !== null ? (float) $entry->leave_without_pay : null,
            'separation_date' => $entry->separation_date?->toDateString(),
            'separation_cause' => $entry->separation_cause,
            'source' => 'entry',
            // For HR's edit form.
            'entry' => [
                'id' => $entry->id,
                'agency' => $entry->agency,
                'station' => $entry->station,
                'annual_salary' => $entry->annual_salary,
                'leave_without_pay' => $entry->leave_without_pay,
                'remarks' => $entry->remarks,
            ],
        ];
    }

    /** What the dates run into, if anything ("the period as …"). */
    private function overlapping(Employee $employee, Carbon $from, Carbon $to, ?ServiceRecordEntry $ignore): ?string
    {
        $period = $employee->plantillaAppointments()->with('item')->whereNotNull('date_from')
            ->whereDate('date_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('date_to')->orWhereDate('date_to', '>=', $from))
            ->first();
        if ($period) {
            return "their plantilla period as {$period->item->position_title} (from {$period->date_from->toDateString()})";
        }

        $line = $employee->serviceRecordEntries()
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->whereDate('date_from', '<=', $to)->whereDate('date_to', '>=', $from)
            ->first();

        return $line ? "their service as {$line->designation} at {$line->agency}" : null;
    }

    /**
     * Who certifies the printout (System Settings → Human Resources; the
     * name defaults to the selected position's holder).
     *
     * @return array{name: ?string, position: ?string, organization: ?string}
     */
    public function certification(): array
    {
        return [
            ...app(CertifyingOfficer::class)->current(),
            'organization' => Settings::get('organization_name'),
        ];
    }
}
