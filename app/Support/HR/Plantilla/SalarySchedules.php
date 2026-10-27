<?php

namespace App\Support\HR\Plantilla;

use App\Models\PlantillaAppointment;
use App\Models\SalarySchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * HR-4: the only writer of salary schedules. A schedule is the grade ×
 * step table of monthly salaries as issued (e.g. the 3rd tranche, 2026,
 * under NBC No. 601), typed or pasted in by HR from the issuance — the
 * amounts are never assumed here. The one in force on a date prices a
 * plantilla item's grade and its holder's step.
 */
class SalarySchedules
{
    public const GRADES = 33;

    public const STEPS = 8;

    /** @var array<string, ?SalarySchedule> schedules in force, by date */
    private array $inForce = [];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(?SalarySchedule $ignore = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'effective_date' => ['required', 'date', 'after:1900-01-01', Rule::unique('salary_schedules', 'effective_date')->ignore($ignore)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'rates' => ['nullable', 'array', 'max:'.self::GRADES],
            'rates.*' => ['nullable', 'array', 'max:'.self::STEPS],
            'rates.*.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'copy_from' => ['nullable', 'integer', 'exists:salary_schedules,id'],
        ];
    }

    /**
     * Creates or updates a schedule and replaces its table. A new one can
     * start as a copy of another schedule's table.
     *
     * @param  array<string, mixed>  $data  validated
     * @return array{schedule: SalarySchedule, cells: int}
     */
    public function save(?SalarySchedule $schedule, array $data): array
    {
        return DB::transaction(function () use ($schedule, $data) {
            $schedule ??= new SalarySchedule;
            $schedule->fill(collect($data)->only(['name', 'reference', 'effective_date', 'remarks'])->all())->save();

            $rates = $data['rates'] ?? null;
            if ($rates === null && ! empty($data['copy_from'])) {
                $rates = $this->grid(SalarySchedule::query()->findOrFail($data['copy_from']));
            }

            if ($rates !== null) {
                $schedule->rates()->delete();
                $rows = [];

                foreach ($rates as $grade => $steps) {
                    foreach ((array) $steps as $step => $amount) {
                        if ($amount === null || $amount === '' || (int) $grade < 1 || (int) $grade > self::GRADES || (int) $step < 1 || (int) $step > self::STEPS) {
                            continue;
                        }

                        $rows[] = ['salary_schedule_id' => $schedule->id, 'salary_grade' => (int) $grade, 'step' => (int) $step, 'monthly_salary' => round((float) $amount, 2)];
                    }
                }

                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table('salary_rates')->insert($chunk);
                }
            }

            $this->inForce = [];

            return ['schedule' => $schedule, 'cells' => $schedule->rates()->count()];
        });
    }

    /**
     * The table as grade => step => amount (1-based; missing cells null).
     *
     * @return array<int, array<int, ?float>>
     */
    public function grid(SalarySchedule $schedule): array
    {
        $grid = [];
        for ($grade = 1; $grade <= self::GRADES; $grade++) {
            $grid[$grade] = array_fill(1, self::STEPS, null);
        }

        foreach ($schedule->rates()->get() as $rate) {
            if (isset($grid[$rate->salary_grade])) {
                $grid[$rate->salary_grade][$rate->step] = (float) $rate->monthly_salary;
            }
        }

        return $grid;
    }

    public function inForceOn(?Carbon $date = null): ?SalarySchedule
    {
        $date ??= today();
        $key = $date->toDateString();

        return $this->inForce[$key] ??= SalarySchedule::query()->inForceOn($date)->with('rates')->first();
    }

    /**
     * What a period pays a month: its salary override when HR set one,
     * else the schedule's amount for its grade and step on $date.
     */
    public function salaryOf(PlantillaAppointment $appointment, ?Carbon $date = null): ?float
    {
        if ($appointment->salary_override !== null) {
            return (float) $appointment->salary_override;
        }

        $grade = $appointment->loadMissing('item')->item?->salary_grade;

        return $grade !== null && $appointment->step !== null ? $this->monthlySalary($grade, $appointment->step, $date) : null;
    }

    /**
     * The monthly salary for a grade and step on a date, or null when no
     * schedule is in force or it has no amount for that cell.
     */
    public function monthlySalary(int $grade, int $step, ?Carbon $date = null): ?float
    {
        $rate = $this->inForceOn($date)?->rates->first(fn ($rate) => $rate->salary_grade === $grade && $rate->step === $step);

        return $rate ? (float) $rate->monthly_salary : null;
    }
}
