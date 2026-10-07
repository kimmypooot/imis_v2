<?php

namespace App\Support\HR\Payroll;

use App\Enums\PayComponentKind;
use App\Enums\PayComputation;
use App\Enums\PayrollRunStatus;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeePayItem;
use App\Models\PayComponent;
use App\Models\PayrollEntry;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\PlantillaAppointment;
use App\Models\User;
use App\Support\HR\Plantilla\SalarySchedules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-8: the single writer for monthly payrolls.
 *
 * Who is on a payroll: everyone in service (not separated before the
 * month, active account) who holds a complete plantilla period that began
 * by the end of the month. Their basic salary is the grade and step of
 * that period under the salary schedule in force on the 1st of the month.
 *
 * Their lines: every automatic component (PERA, GSIS, PhilHealth,
 * Pag-IBIG), every item on their pay profile in effect that month (an
 * amount there overrides the component's; 0 exempts them), and each loan's
 * amortization (or what's left of it). Net pay is released in two halves:
 * the 15th and the 30th (the last day in February); the first half is
 * rounded down to the centavo, the second takes the rest.
 *
 * While a payroll is a draft HR can change, add and remove lines (marked
 * "adjusted") or rebuild an entry from the profile; finalizing locks it
 * (loans count its lines as paid, payslips can be requested); reopening
 * unlocks it. Nobody adjusts their own pay.
 */
class Payrolls
{
    /** The General Appropriations Act's floor on monthly net take-home pay. */
    public const MINIMUM_NET_PAY = 5000;

    public function __construct(
        private readonly SalarySchedules $schedules,
        private readonly Loans $loans,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public function runRules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function create(string $month, ?string $remarks, User $actor): PayrollRun
    {
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();

        if (PayrollRun::query()->where('year', $start->year)->where('month', $start->month)->exists()) {
            throw ValidationException::withMessages(['month' => "There is already a payroll for {$start->format('F Y')}."]);
        }

        return DB::transaction(function () use ($start, $remarks, $actor) {
            $run = PayrollRun::query()->create([
                'year' => $start->year,
                'month' => $start->month,
                'status' => PayrollRunStatus::Draft,
                'first_release_on' => $start->copy()->day(15),
                'second_release_on' => $start->copy()->day(min(30, $start->daysInMonth)),
                'created_by' => $actor->id,
                'remarks' => $remarks,
            ]);

            $this->generate($run);

            return $run;
        });
    }

    /**
     * Builds every entry afresh from pay profiles, adding staff who became
     * eligible and dropping those who no longer are. HR's adjustments are
     * discarded (the page warns first).
     */
    public function generate(PayrollRun $run): void
    {
        $this->ensureDraft($run);

        DB::transaction(function () use ($run) {
            $eligible = $this->eligible($run->month());
            $run->entries()->whereNotIn('employee_id', $eligible->keys())->delete();

            foreach ($eligible as $employeeId => $period) {
                $this->build($run, $period);
            }
        });
    }

    /** Rebuilds one entry from the pay profile, discarding its adjustments. */
    public function regenerateEntry(PayrollEntry $entry): void
    {
        $this->ensureDraft($entry->run);

        $period = $this->eligible($entry->run->month())->get($entry->employee_id);
        if (! $period) {
            throw ValidationException::withMessages(['entry' => "{$entry->name} is no longer on this payroll's staff (separated, inactive, or no complete plantilla period); regenerate the whole payroll to drop them."]);
        }

        DB::transaction(fn () => $this->build($entry->run, $period));
    }

    /**
     * Staff in service that month who aren't on the payroll, and why.
     *
     * @return Collection<int, array{employee_id: int, name: string, reason: string}>
     */
    public function leftOut(PayrollRun $run): Collection
    {
        $month = $run->month();
        $on = $run->entries()->pluck('employee_id')->all();
        $eligible = $this->eligible($month);

        return $this->inService($month)->reject(fn (Employee $employee) => in_array($employee->id, $on, true))
            ->map(function (Employee $employee) use ($eligible, $month) {
                $period = PlantillaAppointment::query()->with('item')->where('employee_id', $employee->id)->whereNull('date_to')->latest('id')->first();

                $reason = match (true) {
                    $eligible->has($employee->id) => 'Not generated yet: regenerate the payroll.',
                    ! $period => 'Holds no plantilla item.',
                    ! $period->isComplete() => 'Plantilla period incomplete (missing '.implode(', ', $period->missingDetails()).').',
                    $period->date_from->gt($month->copy()->endOfMonth()) => 'Starts after this month.',
                    default => "No salary for SG {$period->item->salary_grade} step {$period->step} in the schedule in force.",
                };

                return ['employee_id' => $employee->id, 'name' => $employee->user->name, 'reason' => $reason];
            })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function lineRules(): array
    {
        return [
            'kind' => ['required', 'in:earning,deduction'],
            'pay_component_id' => ['nullable', 'integer', 'exists:pay_components,id'],
            'label' => ['required_without:pay_component_id', 'nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function addLine(PayrollEntry $entry, array $data): PayrollLine
    {
        $this->ensureDraft($entry->run);
        $component = isset($data['pay_component_id']) ? PayComponent::query()->find($data['pay_component_id']) : null;

        return DB::transaction(function () use ($entry, $data, $component) {
            $line = $entry->lines()->create([
                'kind' => $component ? ($component->kind->isDeduction() ? 'deduction' : 'earning') : $data['kind'],
                'pay_component_id' => $component?->id,
                'label' => $data['label'] ?? $component->name,
                'amount' => round((float) $data['amount'], 2),
                'adjusted' => true,
                'sort_order' => ($entry->lines()->max('sort_order') ?? 0) + 1,
            ]);
            $this->total($entry);

            return $line;
        });
    }

    public function updateLine(PayrollLine $line, float $amount): void
    {
        $this->ensureDraft($line->entry->run);

        DB::transaction(function () use ($line, $amount) {
            $line->forceFill(['amount' => round($amount, 2), 'adjusted' => true])->save();
            $this->total($line->entry);
        });
    }

    public function removeLine(PayrollLine $line): void
    {
        $this->ensureDraft($line->entry->run);

        DB::transaction(function () use ($line) {
            $entry = $line->entry;
            $line->delete();
            $this->total($entry);
        });
    }

    public function finalize(PayrollRun $run, User $actor): void
    {
        $this->ensureDraft($run);
        if (! $run->entries()->exists()) {
            throw ValidationException::withMessages(['run' => 'The payroll has no one on it.']);
        }

        $run->forceFill(['status' => PayrollRunStatus::Finalized, 'finalized_at' => now(), 'finalized_by' => $actor->id])->save();
    }

    public function reopen(PayrollRun $run): void
    {
        if (! $run->isFinalized()) {
            throw ValidationException::withMessages(['run' => 'The payroll is still a draft.']);
        }

        $run->forceFill(['status' => PayrollRunStatus::Draft, 'finalized_at' => null, 'finalized_by' => null])->save();
    }

    public function delete(PayrollRun $run): void
    {
        $this->ensureDraft($run);
        if (DB::table('payslip_requests')->whereIn('payroll_entry_id', $run->entries()->select('id'))->exists()) {
            throw ValidationException::withMessages(['run' => 'Payslips were requested from this payroll; correct it instead of deleting it.']);
        }

        $run->delete();
    }

    /**
     * Totals for the payroll page and register.
     *
     * @return array<string, float|int>
     */
    public function totals(PayrollRun $run): array
    {
        $entries = $run->entries();

        return [
            'people' => (clone $entries)->count(),
            'basic' => round((float) (clone $entries)->sum('basic_salary'), 2),
            'gross' => round((float) (clone $entries)->sum('gross_pay'), 2),
            'deductions' => round((float) (clone $entries)->sum('total_deductions'), 2),
            'net' => round((float) (clone $entries)->sum('net_pay'), 2),
            'first_half' => round((float) (clone $entries)->sum('first_half'), 2),
            'second_half' => round((float) (clone $entries)->sum('second_half'), 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentRun(PayrollRun $run): array
    {
        return [
            'id' => $run->id,
            'period' => $run->period,
            'month' => $run->month()->format('Y-m'),
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'first_release_on' => $run->first_release_on?->toDateString(),
            'second_release_on' => $run->second_release_on?->toDateString(),
            'created_by' => $run->creator?->name,
            'finalized_at' => $run->finalized_at?->toIso8601String(),
            'finalized_by' => $run->finalizer?->name,
            'remarks' => $run->remarks,
            'totals' => $this->totals($run),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentEntry(PayrollEntry $entry, bool $withLines = false): array
    {
        $net = (float) $entry->net_pay;

        return [
            'id' => $entry->id,
            'employee_id' => $entry->employee_id,
            'name' => $entry->name,
            'position' => $entry->position,
            'item_number' => $entry->item_number,
            'salary_grade' => $entry->salary_grade,
            'step' => $entry->step,
            'office' => $entry->office,
            'basic_salary' => (float) $entry->basic_salary,
            'gross_pay' => (float) $entry->gross_pay,
            'total_deductions' => (float) $entry->total_deductions,
            'net_pay' => $net,
            'first_half' => (float) $entry->first_half,
            'second_half' => (float) $entry->second_half,
            'below_minimum' => $net < self::MINIMUM_NET_PAY,
            'adjusted' => $entry->relationLoaded('lines') ? $entry->lines->contains('adjusted', true) : $entry->lines()->where('adjusted', true)->exists(),
            'lines' => $withLines ? $entry->lines->map(fn (PayrollLine $line) => [
                'id' => $line->id,
                'kind' => $line->kind,
                'label' => $line->label,
                'amount' => (float) $line->amount,
                'adjusted' => $line->adjusted,
                'loan' => $line->employee_loan_id !== null,
            ])->values()->all() : [],
        ];
    }

    /**
     * Who is on the payroll for the month, with the period that pays them.
     *
     * @return Collection<int, PlantillaAppointment> keyed by employee id
     */
    private function eligible(Carbon $month): Collection
    {
        $staff = $this->inService($month)->keyBy('id');

        return PlantillaAppointment::query()->complete()->with('item.organizationalUnit')
            ->whereIn('employee_id', $staff->keys())
            ->whereNull('date_to')
            ->whereDate('date_from', '<=', $month->copy()->endOfMonth())
            ->orderBy('id')->get()
            ->keyBy('employee_id')
            ->filter(fn (PlantillaAppointment $period) => $this->schedules->monthlySalary($period->item->salary_grade, $period->step, $month) !== null)
            ->each(fn (PlantillaAppointment $period) => $period->setRelation('employee', $staff[$period->employee_id]));
    }

    /**
     * @return Collection<int, Employee>
     */
    private function inService(Carbon $month): Collection
    {
        return Employee::query()->with('user')
            ->where(fn ($query) => $query->whereNull('separation_date')->orWhereDate('separation_date', '>=', $month->copy()->startOfMonth()))
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->get();
    }

    private function build(PayrollRun $run, PlantillaAppointment $period): void
    {
        $employee = $period->employee;
        $month = $run->month();
        $basic = (float) $this->schedules->monthlySalary($period->item->salary_grade, $period->step, $month);

        $entry = PayrollEntry::query()->firstOrNew(['payroll_run_id' => $run->id, 'employee_id' => $employee->id]);
        $entry->fill([
            'name' => $employee->user->name,
            'position' => $period->item->position_title,
            'item_number' => $period->item->item_number,
            'salary_grade' => $period->item->salary_grade,
            'step' => $period->step,
            'office' => $period->item->organizationalUnit?->short_name ?? $period->item->organizationalUnit?->name,
            'basic_salary' => $basic,
        ])->save();

        $entry->lines()->delete();
        foreach ($this->linesFor($employee, $basic, $month, $run->id) as $order => $line) {
            $entry->lines()->create([...$line, 'sort_order' => $order]);
        }

        $this->total($entry);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function linesFor(Employee $employee, float $basic, Carbon $month, int $runId): array
    {
        // The latest profile item per component, among those in effect this month.
        $items = EmployeePayItem::query()->with('component')->where('employee_id', $employee->id)
            ->inEffectDuring($month)->orderBy('effective_from')->get()
            ->keyBy('pay_component_id');

        $components = PayComponent::query()->active()->ordered()
            ->where('kind', '!=', PayComponentKind::Loan->value)
            ->where(fn ($query) => $query->where('automatic', true)->orWhereIn('id', $items->keys()))
            ->get();

        $lines = [];
        foreach ($components as $component) {
            $amount = $this->amount($component, $items->get($component->id), $basic);
            if ($amount > 0) {
                $lines[] = [
                    'kind' => $component->kind->isDeduction() ? 'deduction' : 'earning',
                    'pay_component_id' => $component->id,
                    'label' => $component->name,
                    'amount' => $amount,
                ];
            }
        }

        $loans = EmployeeLoan::query()->with('component')->where('employee_id', $employee->id)->orderBy('start_month')->get();
        $paid = $this->loans->paidThroughPayroll($loans->pluck('id')->all(), $runId);
        foreach ($loans as $loan) {
            $balance = $this->loans->balance($loan, (float) ($paid[$loan->id] ?? 0));
            $amount = $this->loans->amortizationFor($loan, $month, $balance);
            if ($amount > 0) {
                $lines[] = [
                    'kind' => 'deduction',
                    'pay_component_id' => $loan->pay_component_id,
                    'employee_loan_id' => $loan->id,
                    'label' => $loan->component->name.($loan->reference ? " ({$loan->reference})" : ''),
                    'amount' => $amount,
                ];
            }
        }

        // Earnings first, then deductions, each in component order.
        usort($lines, fn ($a, $b) => ($a['kind'] === 'earning' ? 0 : 1) <=> ($b['kind'] === 'earning' ? 0 : 1));

        return $lines;
    }

    private function amount(PayComponent $component, ?EmployeePayItem $item, float $basic): float
    {
        if ($item && $item->amount !== null) {
            return round((float) $item->amount, 2);
        }

        return match ($component->method) {
            PayComputation::Fixed => round((float) $component->amount, 2),
            PayComputation::Percent => round(min(max($basic, (float) ($component->base_min ?? 0)), $component->base_max !== null ? (float) $component->base_max : PHP_FLOAT_MAX)
                * (float) $component->rate / 100, 2),
            PayComputation::Manual => 0.0,
        };
    }

    private function total(PayrollEntry $entry): void
    {
        $lines = $entry->lines()->get();
        $earnings = round($lines->where('kind', 'earning')->sum(fn ($line) => (float) $line->amount), 2);
        $deductions = round($lines->where('kind', 'deduction')->sum(fn ($line) => (float) $line->amount), 2);
        $gross = round((float) $entry->basic_salary + $earnings, 2);
        $net = round($gross - $deductions, 2);
        // Split whole centavos, so floating point can't lose or add one.
        $first = intdiv((int) round($net * 100), 2) / 100;

        $entry->forceFill([
            'gross_pay' => $gross,
            'total_deductions' => $deductions,
            'net_pay' => $net,
            'first_half' => $first,
            'second_half' => round($net - $first, 2),
        ])->save();
    }

    private function ensureDraft(PayrollRun $run): void
    {
        if ($run->isFinalized()) {
            throw ValidationException::withMessages(['run' => "The payroll for {$run->period} is finalized; reopen it to make changes."]);
        }
    }
}
