<?php

namespace App\Support\HR;

use App\Models\Employee;
use App\Models\PlantillaAppointment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everyone in service with their age, length of service and the dates
 * that follow from them: next birthday, optional (60) and compulsory (65)
 * retirement under the GSIS rules (RA 8291), and the next CSC loyalty
 * award (LoyaltyAwards). The HR dashboard shows short windows of the same
 * figures; this is the whole roster (Human Resources → Age & Service).
 *
 * Two lengths of service: in government, from the original appointment
 * date, else the earliest complete plantilla period; and in the CSC, from
 * the date someone joined the CSC when they came from another agency.
 */
class AgeAndService
{
    public const OPTIONAL_RETIREMENT_AGE = 60;

    public const COMPULSORY_RETIREMENT_AGE = 65;

    /** The views HR can narrow the roster to (the dashboard's "View all" links). */
    public const VIEWS = ['all', 'birthdays_month', 'birthdays_30', 'retiring_12', 'retirement_age', 'loyalty_year'];

    private Carbon $today;

    public function __construct(?Carbon $today = null)
    {
        $this->today = ($today ?? today())->copy()->startOfDay();
    }

    /**
     * @param  Collection<int, Employee>  $staff  in service, with user.organizationalUnit loaded
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Collection $staff): Collection
    {
        $governmentStarts = $this->serviceStarts($staff);
        $loyalty = app(LoyaltyAwards::class);

        return $staff->map(function (Employee $employee) use ($governmentStarts, $loyalty) {
            $birthday = $employee->user->birthday;
            $next = $birthday ? $this->nextAnniversary($birthday) : null;
            $optional = $birthday ? $this->anniversaryAt($birthday, self::OPTIONAL_RETIREMENT_AGE) : null;
            $compulsory = $birthday ? $this->anniversaryAt($birthday, self::COMPULSORY_RETIREMENT_AGE) : null;
            $government = $governmentStarts[$employee->id] ?? null;
            $governmentMonths = $government && $government->lte($this->today) ? (int) $government->diffInMonths($this->today) : null;
            $csc = $loyalty->summary($employee, $this->today);
            $unit = $employee->user->organizationalUnit;

            return [
                'employee_id' => $employee->id,
                'name' => $employee->user->name,
                'list_name' => $employee->user->list_name,
                'photo_url' => $employee->user->profile_photo_url,
                'position' => $employee->user->position,
                'office' => $unit ? ($unit->short_name ?: $unit->name) : null,
                'office_id' => $unit?->id,
                'birthday' => $birthday?->toDateString(),
                'age' => $birthday?->age,
                'next_birthday' => $next?->toDateString(),
                'birthday_in_days' => $next ? (int) $this->today->diffInDays($next) : null,
                'government_since' => $government?->toDateString(),
                'government_months' => $governmentMonths,
                'government_label' => $governmentMonths !== null ? $this->label($governmentMonths) : null,
                'csc_since' => $csc['since'] ?? null,
                'csc_months' => $csc ? $csc['years'] * 12 + $csc['months'] : null,
                'csc_label' => $csc['label'] ?? null,
                'next_award' => $csc['next_award'] ?? null,
                'next_award_date' => $csc['next_award_date'] ?? null,
                'award_this_year' => $csc['award_this_year'] ?? null,
                'optional_retirement' => $optional?->toDateString(),
                'compulsory_retirement' => $compulsory?->toDateString(),
                'retirement_status' => match (true) {
                    $compulsory === null => null,
                    $compulsory->lte($this->today) => 'past_compulsory',
                    $optional->lte($this->today) => 'eligible',
                    default => null,
                },
            ];
        });
    }

    /**
     * The rows one view keeps, in that view's order.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function view(Collection $rows, string $view): Collection
    {
        $soon = $this->today->copy()->addMonths(12)->toDateString();
        $today = $this->today->toDateString();
        $byName = fn (Collection $rows) => $rows->sortBy('list_name', SORT_NATURAL | SORT_FLAG_CASE);

        $kept = match ($view) {
            'birthdays_month' => $rows->filter(fn (array $row) => $row['birthday'] && (int) substr($row['birthday'], 5, 2) === $this->today->month)
                ->sortBy([fn ($a, $b) => substr($a['birthday'], 8) <=> substr($b['birthday'], 8), ['list_name', 'asc']]),
            'birthdays_30' => $rows->filter(fn (array $row) => $row['birthday_in_days'] !== null && $row['birthday_in_days'] <= 30)
                ->sortBy([['birthday_in_days', 'asc'], ['list_name', 'asc']]),
            // Reaching 60 or 65 within 12 months, or already past 65.
            'retiring_12' => $rows->filter(fn (array $row) => $row['compulsory_retirement'] && (
                $row['compulsory_retirement'] <= $soon || ($row['optional_retirement'] > $today && $row['optional_retirement'] <= $soon)
            ))->sortBy('compulsory_retirement'),
            'retirement_age' => $rows->filter(fn (array $row) => $row['age'] !== null && $row['age'] >= self::OPTIONAL_RETIREMENT_AGE)
                ->sortBy([['age', 'desc'], ['list_name', 'asc']]),
            'loyalty_year' => $byName($rows->filter(fn (array $row) => $row['award_this_year'] !== null)),
            default => $byName($rows),
        };

        return $kept->values();
    }

    /**
     * When each person's government service began: the original
     * appointment date, else their earliest complete plantilla period.
     *
     * @param  Collection<int, Employee>  $staff
     * @return Collection<int, Carbon> keyed by employee id
     */
    public function serviceStarts(Collection $staff): Collection
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

    /** The next time (from today) the date comes round; 29 Feb → 28 Feb in common years. */
    public function nextAnniversary(Carbon $date): Carbon
    {
        $next = $this->anniversaryIn($date, $this->today->year);

        return $next->lt($this->today) ? $this->anniversaryIn($date, $this->today->year + 1) : $next;
    }

    public function anniversaryAt(Carbon $birthday, int $age): Carbon
    {
        return $this->anniversaryIn($birthday, $birthday->year + $age);
    }

    private function anniversaryIn(Carbon $date, int $year): Carbon
    {
        $day = min($date->day, Carbon::create($year, $date->month)->daysInMonth);

        return Carbon::create($year, $date->month, $day)->startOfDay();
    }

    private function label(int $months): string
    {
        $years = intdiv($months, 12);
        $months %= 12;
        $parts = array_filter([
            $years ? $years.' '.($years === 1 ? 'yr' : 'yrs') : null,
            $months ? $months.' '.($months === 1 ? 'mo' : 'mos') : null,
        ]);

        return $parts ? implode(' ', $parts) : 'Under a month';
    }
}
