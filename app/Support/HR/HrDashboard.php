<?php

namespace App\Support\HR;

use App\Enums\EmploymentStatus;
use App\Enums\LdApprovalStatus;
use App\Enums\LeaveApplicationStatus;
use App\Enums\PdsSubmissionStatus;
use App\Enums\PermissionName;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\Pds\PdsSubmission;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The analytics on the Human Resources dashboard: who is in service and
 * how they break down (sex, age, employment status, length of service),
 * birthdays coming up, who reaches retirement age, loyalty-award
 * milestones, leave, what's waiting on HR, and gaps in the records.
 *
 * "In service" means an employee record that isn't separated, on an
 * active account — as on the Staffing page and `imis:link-plantilla`.
 * Each section needs the permission behind it; one the viewer can't see
 * is null, never zero. The whole part needs `employees.view` (HR and top
 * management), since it names people and shows their birthdays.
 *
 * Retirement follows the GSIS rules (RA 8291): optional from 60,
 * compulsory at 65. Loyalty awards follow the CSC: 10 years of service,
 * then every 5. Length of service counts from the original appointment
 * date, else the earliest complete plantilla period; loyalty awards count
 * CSC service only, from the date someone joined the CSC when they came
 * from another agency (LoyaltyAwards).
 */
class HrDashboard
{
    public const BIRTHDAY_DAYS = 30;

    public const RETIREMENT_MONTHS = 12;

    public const OPTIONAL_RETIREMENT_AGE = 60;

    public const COMPULSORY_RETIREMENT_AGE = 65;

    public const UPCOMING_LEAVE_DAYS = 14;

    private const AGE_BANDS = [[null, 29, 'Under 30'], [30, 39, '30–39'], [40, 49, '40–49'], [50, 59, '50–59'], [60, 64, '60–64'], [65, null, '65 and over']];

    private const SERVICE_BANDS = [[null, 4, 'Under 5 years'], [5, 9, '5–9 years'], [10, 14, '10–14 years'], [15, 19, '15–19 years'], [20, 24, '20–24 years'], [25, 29, '25–29 years'], [30, null, '30 years and over']];

    private Carbon $today;

    public function __construct(private readonly User $viewer)
    {
        $this->today = today();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(): ?array
    {
        if (! $this->can(PermissionName::EmployeesView)) {
            return null;
        }

        $staff = $this->inService();
        $started = $this->serviceStarts($staff);

        return [
            'asOf' => $this->today->toDateString(),
            'headcount' => $staff->count(),
            'sex' => $this->bySex($staff),
            'age' => $this->byAge($staff),
            'employmentStatus' => $this->byEmploymentStatus($staff),
            'service' => $this->byLengthOfService($staff, $started),
            'birthdays' => $this->upcomingBirthdays($staff),
            'retirement' => $this->retirement($staff),
            'loyalty' => $this->loyaltyMilestones($staff, $started),
            'plantilla' => $this->can(PermissionName::PlantillaView) ? $this->plantilla() : null,
            'leave' => $this->can(PermissionName::LeaveView) ? $this->leave() : null,
            'learning' => $this->can(PermissionName::LdView) ? $this->learning($staff) : null,
            'pending' => $this->pending(),
            'gaps' => $this->gaps($staff),
        ];
    }

    private function can(PermissionName $permission): bool
    {
        return $this->viewer->hasPermission($permission->value);
    }

    /**
     * @return Collection<int, Employee>
     */
    private function inService(): Collection
    {
        return Employee::query()->with('user.organizationalUnit')->get()
            ->reject(fn (Employee $employee) => $employee->isSeparated() || ! $employee->user->isActive())
            ->values();
    }

    /**
     * @param  Collection<int, Employee>  $staff
     * @return array<string, mixed>
     */
    private function bySex(Collection $staff): array
    {
        $counts = $staff->countBy(fn (Employee $employee) => $employee->user->sex ?: 'not_recorded');

        return [
            'female' => $counts['female'] ?? 0,
            'male' => $counts['male'] ?? 0,
            'not_recorded' => $counts['not_recorded'] ?? 0,
        ];
    }

    /**
     * @param  Collection<int, Employee>  $staff
     * @return array<string, mixed>
     */
    private function byAge(Collection $staff): array
    {
        $ages = $staff->map(fn (Employee $employee) => $employee->user->birthday?->age)->filter(fn ($age) => $age !== null);

        return [
            'labels' => array_column(self::AGE_BANDS, 2),
            'values' => array_map(fn (array $band) => $ages->filter(fn (int $age) => $this->inBand($age, $band))->count(), self::AGE_BANDS),
            'average' => $ages->isEmpty() ? null : round($ages->avg(), 1),
            'youngest' => $ages->min(),
            'oldest' => $ages->max(),
            'unknown' => $staff->count() - $ages->count(),
        ];
    }

    /**
     * @param  Collection<int, Employee>  $staff
     * @return array<string, mixed>
     */
    private function byEmploymentStatus(Collection $staff): array
    {
        $counts = $staff->countBy(fn (Employee $employee) => $employee->employment_status?->value ?? 'not_set');
        $rows = collect(EmploymentStatus::cases())
            ->map(fn (EmploymentStatus $status) => [$status->label(), $counts[$status->value] ?? 0])
            ->push(['Not Set', $counts['not_set'] ?? 0])
            ->filter(fn (array $row) => $row[1] > 0)
            ->sortByDesc(1)->values();

        return ['labels' => $rows->pluck(0)->all(), 'values' => $rows->pluck(1)->all()];
    }

    /**
     * When each person's government service began here: the original
     * appointment date, else their earliest complete plantilla period.
     *
     * @param  Collection<int, Employee>  $staff
     * @return Collection<int, Carbon> keyed by employee id
     */
    private function serviceStarts(Collection $staff): Collection
    {
        $firstPeriods = PlantillaAppointment::query()->complete()
            ->whereIn('employee_id', $staff->modelKeys())
            ->selectRaw('employee_id, MIN(date_from) as first_day')
            ->groupBy('employee_id')
            ->pluck('first_day', 'employee_id');

        return $staff->mapWithKeys(function (Employee $employee) use ($firstPeriods) {
            $start = $employee->original_appointment_date ?? (isset($firstPeriods[$employee->id]) ? Carbon::parse($firstPeriods[$employee->id]) : null);

            return $start ? [$employee->id => $start] : [];
        });
    }

    /**
     * @param  Collection<int, Employee>  $staff
     * @param  Collection<int, Carbon>  $started
     * @return array<string, mixed>
     */
    private function byLengthOfService(Collection $staff, Collection $started): array
    {
        $years = $started->map(fn (Carbon $start) => (int) $start->diffInYears($this->today));

        return [
            'labels' => array_column(self::SERVICE_BANDS, 2),
            'values' => array_map(fn (array $band) => $years->filter(fn (int $year) => $this->inBand($year, $band))->count(), self::SERVICE_BANDS),
            'average' => $years->isEmpty() ? null : round($years->avg(), 1),
            'unknown' => $staff->count() - $years->count(),
        ];
    }

    /**
     * Birthdays from today through the next BIRTHDAY_DAYS days. A 29
     * February birthday falls on 28 February in other years.
     *
     * @param  Collection<int, Employee>  $staff
     * @return array<int, array<string, mixed>>
     */
    private function upcomingBirthdays(Collection $staff): array
    {
        return $staff
            ->filter(fn (Employee $employee) => $employee->user->birthday !== null)
            ->map(function (Employee $employee) {
                $next = $this->nextAnniversary($employee->user->birthday);

                return [
                    'employee_id' => $employee->id,
                    'name' => $employee->user->name,
                    'office' => $this->office($employee),
                    'date' => $next->toDateString(),
                    'in_days' => (int) $this->today->diffInDays($next),
                    'turning' => $next->year - $employee->user->birthday->year,
                ];
            })
            ->filter(fn (array $row) => $row['in_days'] <= self::BIRTHDAY_DAYS)
            ->sortBy([['in_days', 'asc'], ['name', 'asc']])
            ->values()->all();
    }

    /**
     * Who reaches 60 (optional) or 65 (compulsory) within the next
     * RETIREMENT_MONTHS, anyone already past 65 still in service, and
     * those already 60–64: they may retire at any time, so HR plans for
     * them too (listed last, dated at their 65th birthday).
     *
     * @param  Collection<int, Employee>  $staff
     * @return array<string, mixed>
     */
    private function retirement(Collection $staff): array
    {
        $until = $this->today->copy()->addMonths(self::RETIREMENT_MONTHS);
        $rows = collect();

        foreach ($staff as $employee) {
            $birthday = $employee->user->birthday;
            if (! $birthday) {
                continue;
            }

            $compulsory = $this->anniversaryAt($birthday, self::COMPULSORY_RETIREMENT_AGE);
            $optional = $this->anniversaryAt($birthday, self::OPTIONAL_RETIREMENT_AGE);

            [$kind, $date] = match (true) {
                $compulsory->lte($this->today) => ['past_compulsory', $compulsory],
                $compulsory->lte($until) => ['compulsory', $compulsory],
                $optional->gt($this->today) && $optional->lte($until) => ['optional', $optional],
                $optional->lte($this->today) => ['eligible', $compulsory],
                default => [null, null],
            };

            if ($kind) {
                $rows->push([
                    'employee_id' => $employee->id,
                    'name' => $employee->user->name,
                    'position' => $employee->user->position,
                    'office' => $this->office($employee),
                    'kind' => $kind,
                    'age' => $birthday->age,
                    'date' => $date->toDateString(),
                ]);
            }
        }

        $order = ['past_compulsory' => 0, 'compulsory' => 1, 'optional' => 2, 'eligible' => 3];

        return [
            'people' => $rows->sortBy([fn ($a, $b) => $order[$a['kind']] <=> $order[$b['kind']], ['date', 'asc']])->values()->all(),
            'compulsory' => $rows->whereIn('kind', ['past_compulsory', 'compulsory'])->count(),
            'optional' => $rows->where('kind', 'optional')->count(),
            // Eligible for optional retirement now (60–64).
            'eligibleNow' => $staff->filter(fn (Employee $employee) => ($age = $employee->user->birthday?->age) !== null
                && $age >= self::OPTIONAL_RETIREMENT_AGE && $age < self::COMPULSORY_RETIREMENT_AGE)->count(),
        ];
    }

    /**
     * People who complete 10, 15, 20, … years of service this calendar year.
     *
     * @param  Collection<int, Employee>  $staff
     * @param  Collection<int, Carbon>  $started
     * @return array<string, mixed>
     */
    private function loyaltyMilestones(Collection $staff, Collection $started): array
    {
        $year = $this->today->year;
        $loyalty = app(LoyaltyAwards::class);
        // CSC service only: the date they joined the CSC, for those who came from another agency.
        $inCsc = $staff->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->csc_entry_date ?? $started[$employee->id] ?? null])->filter();

        $people = $staff
            ->filter(fn (Employee $employee) => isset($inCsc[$employee->id]) && $loyalty->isMilestone($year - $inCsc[$employee->id]->year))
            ->map(fn (Employee $employee) => [
                'employee_id' => $employee->id,
                'name' => $employee->user->name,
                'office' => $this->office($employee),
                'years' => $year - $inCsc[$employee->id]->year,
                'date' => $inCsc[$employee->id]->copy()->year($year)->toDateString(),
            ])
            ->sortBy([['date', 'asc'], ['name', 'asc']])
            ->values();

        return ['year' => $year, 'people' => $people->all(), 'withDates' => $inCsc->count()];
    }

    /**
     * @return array<string, int>
     */
    private function plantilla(): array
    {
        $items = PlantillaItem::query()->active()->with('incumbency')->get();
        $filled = $items->filter(fn (PlantillaItem $item) => $item->incumbency !== null)->count();

        return [
            'items' => $items->count(),
            'filled' => $filled,
            'vacant' => $items->count() - $filled,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leave(): array
    {
        $present = fn (LeaveApplication $application) => [
            'id' => $application->id,
            'employee_id' => $application->employee_id,
            'name' => $application->employee->user->name,
            'type' => $application->leaveType?->name,
            'date_from' => $application->date_from->toDateString(),
            'date_to' => $application->date_to->toDateString(),
            'days' => (float) $application->days,
            'status' => $application->status->value,
            'status_label' => $application->status->label(),
        ];
        $with = ['employee.user', 'leaveType'];

        return [
            'onLeaveToday' => LeaveApplication::query()->with($with)
                ->where('status', LeaveApplicationStatus::Approved)
                ->whereDate('date_from', '<=', $this->today)->whereDate('date_to', '>=', $this->today)
                ->orderBy('date_to')->get()->map($present)->all(),
            'upcoming' => LeaveApplication::query()->with($with)
                ->where('status', LeaveApplicationStatus::Approved)
                ->whereDate('date_from', '>', $this->today)
                ->whereDate('date_from', '<=', $this->today->copy()->addDays(self::UPCOMING_LEAVE_DAYS))
                ->orderBy('date_from')->get()->map($present)->all(),
            'recent' => LeaveApplication::query()->with($with)
                ->whereNotNull('submitted_at')
                ->latest('submitted_at')->limit(8)->get()->map($present)->all(),
            'inReview' => LeaveApplication::query()->where('status', LeaveApplicationStatus::ForReview)->count(),
        ];
    }

    /**
     * @param  Collection<int, Employee>  $staff
     * @return array<string, mixed>
     */
    private function learning(Collection $staff): array
    {
        $thisYear = PdsLearningDevelopment::query()
            ->where('approval_status', LdApprovalStatus::Approved)
            ->whereYear('date_from', $this->today->year)
            ->whereIn('employee_id', $staff->modelKeys());

        return [
            'year' => $this->today->year,
            'trainings' => (clone $thisYear)->count(),
            'hours' => (float) (clone $thisYear)->sum('hours'),
            'trained' => (clone $thisYear)->distinct()->count('employee_id'),
            'headcount' => $staff->count(),
        ];
    }

    /**
     * What's waiting on HR, each only for whoever can act on or see it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pending(): array
    {
        return array_values(array_filter([
            $this->can(PermissionName::PdsVerify) ? [
                'label' => 'PDS submissions to verify',
                'count' => PdsSubmission::query()->where('status', PdsSubmissionStatus::Submitted)->count(),
                'href' => '/admin/hr/pds-verifications',
            ] : null,
            $this->can(PermissionName::LeaveView) ? [
                'label' => 'Leave applications in review',
                'count' => LeaveApplication::query()->where('status', LeaveApplicationStatus::ForReview)->count(),
                'href' => '/admin/hr/leave/applications',
            ] : null,
            $this->can(PermissionName::LdApprove) ? [
                'label' => 'L&D entries to review',
                'count' => PdsLearningDevelopment::query()->where('approval_status', LdApprovalStatus::Pending)->count(),
                'href' => '/admin/hr/learning-development/reviews',
            ] : null,
            $this->can(PermissionName::PlantillaView) ? [
                'label' => 'Incomplete plantilla links',
                'count' => PlantillaAppointment::query()->whereNull('date_to')->get()->reject->isComplete()->count(),
                'href' => '/admin/hr/plantilla',
            ] : null,
        ]));
    }

    /**
     * Gaps in the records that make the figures above incomplete.
     *
     * @param  Collection<int, Employee>  $staff
     * @return array<string, int>
     */
    private function gaps(Collection $staff): array
    {
        return [
            'birthday' => $staff->filter(fn (Employee $employee) => $employee->user->birthday === null)->count(),
            'sex' => $staff->filter(fn (Employee $employee) => blank($employee->user->sex))->count(),
            'employmentStatus' => $staff->filter(fn (Employee $employee) => $employee->employment_status === null)->count(),
        ];
    }

    /**
     * @param  array{0: ?int, 1: ?int, 2: string}  $band
     */
    private function inBand(int $value, array $band): bool
    {
        return ($band[0] === null || $value >= $band[0]) && ($band[1] === null || $value <= $band[1]);
    }

    /** The next time (from today) the date comes round; 29 Feb → 28 Feb in common years. */
    private function nextAnniversary(Carbon $date): Carbon
    {
        $next = $this->anniversaryIn($date, $this->today->year);

        return $next->lt($this->today) ? $this->anniversaryIn($date, $this->today->year + 1) : $next;
    }

    private function anniversaryAt(Carbon $birthday, int $age): Carbon
    {
        return $this->anniversaryIn($birthday, $birthday->year + $age);
    }

    private function anniversaryIn(Carbon $date, int $year): Carbon
    {
        $day = min($date->day, Carbon::create($year, $date->month)->daysInMonth);

        return Carbon::create($year, $date->month, $day)->startOfDay();
    }

    private function office(Employee $employee): ?string
    {
        $unit = $employee->user->organizationalUnit;

        return $unit ? ($unit->short_name ?: $unit->name) : null;
    }
}
