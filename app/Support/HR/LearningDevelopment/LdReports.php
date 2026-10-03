<?php

namespace App\Support\HR\LearningDevelopment;

use App\Enums\LdType;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * HR-3c: HRD's L&D figures — ldms's dashboard (hrd/fetch_hrd_dashboard.php)
 * and training report (hrd/training_report.php). Both count **approved**
 * records only; pending and rejected entries aren't reportable yet.
 *
 * Year and month filters use whereYear/whereMonth, which work on MySQL
 * and SQLite alike.
 */
class LdReports
{
    /** Recent trainings shown on the dashboard, as in ldms. */
    public const RECENT = 8;

    /** Top employees by hours, as in ldms. */
    public const TOP = 5;

    /**
     * Years with any training, plus this year and the one asked for —
     * newest first. Shared by every HRD L&D page's year picker.
     *
     * @return BaseCollection<int, int>
     */
    public function years(int $selected): BaseCollection
    {
        return PdsLearningDevelopment::query()->pluck('date_from')
            ->map(fn ($date) => (int) substr((string) $date, 0, 4))
            ->push((int) now()->year)->push($selected)
            ->unique()->sortDesc()->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(int $year): array
    {
        $records = $this->approved($year)->with('employee.user.organizationalUnit')->get();
        $active = Employee::query()->with('user')->get()->reject(fn (Employee $employee) => $employee->isSeparated());

        $typed = $records->whereNotNull('ld_type');

        return [
            'stats' => [
                'employees' => $active->count(),
                'trainings' => $records->count(),
                'trainings_previous' => $this->approved($year - 1)->count(),
                'hours' => (float) $records->sum('hours'),
                'offices' => $active->pluck('user.organizational_unit_id')->filter()->unique()->count(),
                'trained' => $records->pluck('employee_id')->unique()->count(),
            ],
            'types' => collect(LdType::cases())
                ->map(fn (LdType $type) => [
                    'type' => $type->value,
                    'label' => $type->label(),
                    'count' => $count = $typed->where('ld_type', $type)->count(),
                    'percent' => $typed->isEmpty() ? 0 : (int) round($count / $typed->count() * 100),
                ])
                ->values(),
            'top' => $records->groupBy('employee_id')
                ->map(fn (Collection $own) => [
                    'id' => $own->first()->employee_id,
                    'name' => $this->listName($own->first()->employee->user),
                    'office' => $this->office($own->first()->employee->user),
                    'trainings' => $own->count(),
                    'hours' => (float) $own->sum('hours'),
                ])
                ->sortByDesc('hours')->take(self::TOP)->values(),
            'monthly' => collect(range(1, 12))
                ->map(fn (int $month) => $records->filter(fn (PdsLearningDevelopment $record) => $record->date_from->month === $month)->count()),
            'recent' => $records->sortByDesc('id')->take(self::RECENT)
                ->map(fn (PdsLearningDevelopment $record) => [
                    'id' => $record->id,
                    'employee_id' => $record->employee_id,
                    'name' => $this->listName($record->employee->user),
                    'office' => $this->office($record->employee->user),
                    'title' => $record->title,
                    'sponsor' => $record->sponsor,
                    'type_label' => $record->typeLabel() ?? 'Not recorded',
                    'hours' => (float) $record->hours,
                    'date_from' => $record->date_from?->toDateString(),
                    'date_to' => $record->date_to?->toDateString(),
                ])
                ->values(),
        ];
    }

    /**
     * The training report: every approved training of the year (optionally
     * one month, one office), by surname then most recent first.
     *
     * @return BaseCollection<int, array<string, mixed>>
     */
    public function report(int $year, ?int $month = null, ?int $officeId = null): BaseCollection
    {
        return $this->approved($year)
            ->when($month, fn (Builder $query) => $query->whereMonth('date_from', $month))
            ->when($officeId, fn (Builder $query) => $query->whereHas('employee.user', fn (Builder $user) => $user->where('organizational_unit_id', $officeId)))
            ->with('employee.user.organizationalUnit')
            ->get()
            ->sortBy([
                fn (PdsLearningDevelopment $a, PdsLearningDevelopment $b) => strnatcasecmp($this->listName($a->employee->user), $this->listName($b->employee->user)),
                fn (PdsLearningDevelopment $a, PdsLearningDevelopment $b) => strcmp((string) $b->date_from, (string) $a->date_from),
            ])
            ->map(fn (PdsLearningDevelopment $record) => [
                'id' => $record->id,
                'employee_id' => $record->employee_id,
                'name' => $this->listName($record->employee->user),
                'sex' => $record->employee->user->sex ? ucfirst($record->employee->user->sex) : null,
                'position' => $record->employee->user->position,
                'office' => $this->office($record->employee->user),
                'title' => $record->title,
                'sponsor' => $record->sponsor,
                'type_label' => $record->typeLabel() ?? 'Not recorded',
                'modality_label' => $record->modality?->label() ?? 'Not recorded',
                'hours' => (float) $record->hours,
                'venue' => $record->venue,
                'resource_speaker' => $record->resource_speaker,
                'date_from' => $record->date_from?->toDateString(),
                'date_to' => $record->date_to?->toDateString(),
            ])
            ->values();
    }

    /**
     * Offices with at least one employee record, for the report's filter.
     *
     * @return BaseCollection<int, array{value: int, label: string}>
     */
    public function officeOptions(): BaseCollection
    {
        return OrganizationalUnit::query()
            ->whereIn('id', User::query()->whereHas('employee')->whereNotNull('organizational_unit_id')->select('organizational_unit_id'))
            ->orderBy('name')
            ->get()
            ->map(fn (OrganizationalUnit $unit) => ['value' => $unit->id, 'label' => $unit->name]);
    }

    /**
     * @return Builder<PdsLearningDevelopment>
     */
    private function approved(int $year): Builder
    {
        return PdsLearningDevelopment::query()->approved()->whereYear('date_from', $year);
    }

    /** "Cruz, Juan D." — ldms listed people by surname. */
    private function listName(User $user): string
    {
        if (! $user->last_name) {
            return $user->name;
        }

        $initial = $user->middle_name ? ' '.mb_strtoupper(mb_substr(trim($user->middle_name), 0, 1)).'.' : '';

        return trim("{$user->last_name}, {$user->first_name}{$initial}");
    }

    private function office(User $user): ?string
    {
        return $user->organizationalUnit?->short_name ?: $user->organizationalUnit?->name;
    }
}
