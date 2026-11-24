<?php

namespace App\Support\HR\Dtr;

use App\Enums\EmploymentStatus;
use App\Enums\UnitType;
use App\Models\BiometricPunch;
use App\Models\DtrAdjustment;
use App\Models\DtrLeaveDeduction;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\OrganizationalUnit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * DTR management for HRD: everyone in service for a month, with what needs
 * attention. The attention counts and filters come from plain queries over
 * all employees; the DTR totals are worked out only for the page shown.
 * The Regional Support Units are one office, the Regional Office, as on the
 * biometric logs page.
 */
class DtrOverview
{
    public const REGIONAL = UploadMonitor::REGIONAL;

    /** Filters HRD can apply from the attention counts. */
    public const FILTERS = ['no_id', 'no_schedule', 'no_punches', 'unverified', 'adjusted'];

    public function __construct(private readonly DailyTimeRecord $record = new DailyTimeRecord) {}

    /**
     * @return list<array{value: string, label: string}>
     */
    public function offices(): array
    {
        $offices = OrganizationalUnit::query()->active()->workingOffices()->get(['id', 'unit_type', 'name'])
            ->reject(fn (OrganizationalUnit $unit) => $unit->unit_type === UnitType::RegionalSupportUnit)
            ->sortBy('name')
            ->map(fn (OrganizationalUnit $unit) => ['value' => (string) $unit->id, 'label' => $unit->name]);

        return $offices->prepend(['value' => self::REGIONAL, 'label' => 'Regional Office'])->values()->all();
    }

    /**
     * How many employees each attention filter would show (for the office and search chosen).
     *
     * @return array<string, int>
     */
    public function counts(Carbon $month, ?string $office, ?string $search): array
    {
        $counts = [];
        foreach (self::FILTERS as $filter) {
            $counts[$filter] = $this->query($month, $office, $search, $filter)->count();
        }

        return $counts;
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(Carbon $month, ?string $office, ?string $search, ?string $filter, int $perPage = 25): LengthAwarePaginator
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth()->startOfDay();

        $page = $this->query($month, $office, $search, $filter)
            ->with(['user:id,name,position,organizational_unit_id', 'user.organizationalUnit:id,name,short_name'])
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->orderBy('users.name')
            ->select('employees.*')
            ->paginate($perPage)
            ->withQueryString();

        $ids = $page->getCollection()->pluck('id');
        $schedules = EmployeeWorkSchedule::query()->with('schedule:id,name')->whereIn('employee_id', $ids)
            ->where('valid_from', '<=', $to->toDateString())
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $from->toDateString()))
            ->orderByDesc('valid_from')->get()->groupBy('employee_id');
        $unverified = $this->unverified($from, $to)->whereIn('employee_id', $ids)->groupBy('employee_id')->selectRaw('employee_id, count(*) as total')->pluck('total', 'employee_id');
        $adjusted = DtrAdjustment::query()->whereIn('employee_id', $ids)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('employee_id')->selectRaw('employee_id, count(*) as total')->pluck('total', 'employee_id');
        $charged = DtrLeaveDeduction::query()->whereIn('employee_id', $ids)->where('period_year', $from->year)->where('period_month', $from->month)->get()
            ->filter(fn (DtrLeaveDeduction $deduction) => $deduction->isActive())->keyBy('employee_id');

        return $page->through(function (Employee $employee) use ($from, $to, $schedules, $unverified, $adjusted, $charged) {
            $totals = $this->record->build($employee, $from, $to)['totals'];

            return [
                'id' => $employee->id,
                'name' => $employee->user->name,
                'position' => $employee->user->position,
                'office' => $employee->user->organizationalUnit?->short_name ?: $employee->user->organizationalUnit?->name,
                'biometric_id' => $employee->biometric_id,
                'schedule' => $schedules->get($employee->id)?->first()?->schedule->name,
                'late_minutes' => (int) $totals['late_minutes'],
                'undertime_minutes' => (int) $totals['undertime_minutes'],
                'absences' => (int) $totals['absences'],
                'leave_days' => (float) $totals['leave_days'],
                'hours_minutes' => (int) $totals['minutes'],
                'adjusted_days' => (int) ($adjusted[$employee->id] ?? 0),
                'unverified_days' => (int) ($unverified[$employee->id] ?? 0),
                'charged' => $charged->has($employee->id),
            ];
        });
    }

    /**
     * Permanent employees still in service in the month, narrowed by office,
     * search and one attention filter.
     *
     * @return Builder<Employee>
     */
    private function query(Carbon $month, ?string $office, ?string $search, ?string $filter): Builder
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth()->startOfDay();

        return Employee::query()
            ->where('employment_status', EmploymentStatus::Permanent)
            ->where(fn (Builder $query) => $query->whereNull('separation_date')->orWhereDate('separation_date', '>=', $from->toDateString()))
            ->when($office, fn (Builder $query) => $this->inOffice($query, $office))
            ->when(filled($search), fn (Builder $query) => $query->whereHas('user', fn (Builder $user) => $user->where('name', 'like', '%'.addcslashes(trim((string) $search), '%_\\').'%')))
            ->when($filter === 'no_id', fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('biometric_id')->orWhere('biometric_id', '')))
            ->when($filter === 'no_schedule', fn (Builder $query) => $query->whereDoesntHave('workSchedules', fn (Builder $q) => $q
                ->where('valid_from', '<=', $to->toDateString())
                ->where(fn (Builder $inner) => $inner->whereNull('valid_to')->orWhere('valid_to', '>=', $from->toDateString()))))
            ->when($filter === 'no_punches', fn (Builder $query) => $query->whereNotNull('biometric_id')->where('biometric_id', '!=', '')
                ->whereNotIn('biometric_id', BiometricPunch::query()->whereNull('voided_at')
                    ->where('punched_at', '>=', $from->copy()->addHours(EmployeePunches::DAY_STARTS_AT)->format('Y-m-d H:i:s'))
                    ->where('punched_at', '<', $to->copy()->addDay()->addHours(EmployeePunches::DAY_STARTS_AT)->format('Y-m-d H:i:s'))
                    ->select('biometric_id')))
            ->when($filter === 'unverified', fn (Builder $query) => $query->whereIn('employees.id', $this->unverified($from, $to)->select('employee_id')))
            ->when($filter === 'adjusted', fn (Builder $query) => $query->whereIn('employees.id', DtrAdjustment::query()
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->select('employee_id')));
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    private function inOffice(Builder $query, string $office): Builder
    {
        if ($office === self::REGIONAL) {
            $unitIds = OrganizationalUnit::query()->where('unit_type', UnitType::RegionalSupportUnit)->pluck('id');

            return $query->whereHas('user', fn (Builder $user) => $user->whereIn('organizational_unit_id', $unitIds)->orWhereNull('organizational_unit_id'));
        }

        return $query->whereHas('user', fn (Builder $user) => $user->where('organizational_unit_id', (int) $office));
    }

    /**
     * Days the employee entered that nobody has verified yet.
     *
     * @return Builder<DtrAdjustment>
     */
    private function unverified(Carbon $from, Carbon $to): Builder
    {
        return DtrAdjustment::query()->where('entered_by_employee', true)->whereNull('verified_at')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * @return Collection<int, string>
     */
    public static function filterLabels(): Collection
    {
        return collect([
            'no_id' => 'No biometric ID',
            'no_schedule' => 'No work schedule',
            'no_punches' => 'No punches this month',
            'unverified' => 'Entries to verify',
            'adjusted' => 'HRD adjustments',
        ]);
    }
}
