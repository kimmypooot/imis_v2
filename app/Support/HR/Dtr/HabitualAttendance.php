<?php

namespace App\Support\HR\Dtr;

use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * DTR: habitual tardiness and habitual absenteeism, to flag for HR.
 *
 * - Tardiness (CSC MC No. 23, s. 1998): late 10 times or more a month for
 *   at least two months in a semester, or two consecutive months in a year.
 * - Absenteeism (same circular): unauthorized absences beyond the 2.5 days
 *   of leave credits earned a month, for at least three months in a semester
 *   or three consecutive months in a year.
 *
 * Counted from the punches and from entered days that someone verified;
 * a day the employee entered that nobody verified yet is left out.
 * A flag is a prompt for HR to look, never a finding.
 */
class HabitualAttendance
{
    public const LATES_A_MONTH = 10;

    public const ABSENCE_ALLOWANCE = 2.5;

    /** @var array<string, array{lates: int, absences: float, unverified: int}> */
    private array $months = [];

    public function __construct(private readonly DailyTimeRecord $record = new DailyTimeRecord) {}

    /**
     * @param  Collection<int, array<string, mixed>>  $days  a month's DTR rows
     * @return array{lates: int, absences: float, unverified: int}
     */
    public function figures(Collection $days): array
    {
        $counted = $days->reject(fn (array $day) => ($day['adjustment']['by_employee'] ?? false) && ! ($day['adjustment']['verified'] ?? false));

        return [
            'lates' => $counted->where('late_minutes', '>', 0)->count(),
            'absences' => $counted->where('status', 'absent')->count() + 0.5 * $counted->where('absent_minutes', '>', 0)->count(),
            'unverified' => $days->count() - $counted->count(),
        ];
    }

    /**
     * @param  ?Collection<int, array<string, mixed>>  $currentDays  the month's rows, when the caller already built them
     * @return array{tardiness: ?string, absenteeism: ?string, unverified: int}
     */
    public function evaluate(Employee $employee, Carbon $month, ?Collection $currentDays = null): array
    {
        $month = $month->copy()->startOfMonth();
        $current = $this->figures($currentDays ?? $this->record->build($employee, $month, $month->copy()->endOfMonth()->startOfDay())['days']);
        $this->months[$this->key($employee, $month)] = $current;

        $late = fn (array $figures) => $figures['lates'] >= self::LATES_A_MONTH;
        $absent = fn (array $figures) => $figures['absences'] > self::ABSENCE_ALLOWANCE;

        return [
            'tardiness' => $late($current) ? $this->flag($employee, $month, 2, $late, 'Late '.self::LATES_A_MONTH.' times or more in') : null,
            'absenteeism' => $absent($current) ? $this->flag($employee, $month, 3, $absent, 'Unauthorized absences beyond 2.5 days in') : null,
            'unverified' => $current['unverified'],
        ];
    }

    /**
     * The months of the semester that qualify, or the run of consecutive
     * months in the year ending at this one, when there are enough of them.
     *
     * @param  callable(array{lates: int, absences: float, unverified: int}): bool  $qualifies
     */
    private function flag(Employee $employee, Carbon $month, int $needed, callable $qualifies, string $phrase): ?string
    {
        $semesterStart = Carbon::create($month->year, $month->month <= 6 ? 1 : 7, 1);

        $inSemester = [];
        for ($cursor = $semesterStart->copy(); $cursor->lte($month); $cursor->addMonth()) {
            if ($qualifies($this->figuresOf($employee, $cursor))) {
                $inSemester[] = $cursor->format('F');
            }
        }
        if (count($inSemester) >= $needed) {
            return $phrase.' '.implode(', ', $inSemester).' (in a semester)';
        }

        $run = [$month->format('F')];
        for ($cursor = $month->copy()->subMonthNoOverflow(); $cursor->year === $month->year && count($run) < $needed; $cursor->subMonthNoOverflow()) {
            if (! $qualifies($this->figuresOf($employee, $cursor))) {
                break;
            }
            array_unshift($run, $cursor->format('F'));
        }

        return count($run) >= $needed ? $phrase.' '.implode(', ', $run).' (consecutive months)' : null;
    }

    /** @return array{lates: int, absences: float, unverified: int} */
    private function figuresOf(Employee $employee, Carbon $month): array
    {
        return $this->months[$this->key($employee, $month)] ??= $this->figures(
            $this->record->build($employee, $month->copy()->startOfMonth(), $month->copy()->endOfMonth()->startOfDay())['days'],
        );
    }

    private function key(Employee $employee, Carbon $month): string
    {
        return $employee->id.'|'.$month->format('Y-m');
    }
}
