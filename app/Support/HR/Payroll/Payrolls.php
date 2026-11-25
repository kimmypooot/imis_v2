<?php

namespace App\Support\HR\Payroll;

use App\Enums\EmploymentStatus;
use App\Enums\PayComponentKind;
use App\Enums\PayComputation;
use App\Enums\PayrollRunStatus;
use App\Enums\PayslipRequestStatus;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeePayItem;
use App\Models\PayComponent;
use App\Models\PayrollEntry;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\PayslipRequest;
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
 * amortization (or what's left of it). A payroll covers the whole month,
 * as the General Payroll does, and its net pay is released once, on the
 * last day of the month.
 *
 * Withholding tax is not generated: HRD prepares the payroll with every
 * other deduction (Draft), submits it to MSD where the Accountant III
 * reviews it and enters each person's tax (With Accounting), and gets it
 * back (Returned) to finalize. While it is with HRD, HR can change, add and
 * remove lines (marked "adjusted") or rebuild entries from the profile;
 * rebuilding keeps the tax Accounting entered. Finalizing locks it (loans
 * count its lines as paid, payslips can be requested); reopening returns
 * it to HRD. Nobody adjusts their own pay or enters their own tax.
 */
class Payrolls
{
    /** The General Appropriations Act's floor on monthly net take-home pay. */
    public const MINIMUM_NET_PAY = 5000;

    /** The pay component Accounting fills in: withholding tax. */
    public const TAX_CODE = 'WTAX';

    private ?int $taxComponentId = null;

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
                'release_on' => $start->copy()->endOfMonth()->startOfDay(),
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
        $this->ensureWithHrd($run);

        DB::transaction(function () use ($run) {
            $eligible = $this->eligible($run->month());
            $run->entries()->whereNotIn('employee_id', $eligible->keys())->delete();

            $cancelled = $run->entries()->whereNotNull('cancelled_at')->pluck('employee_id')->all();
            foreach ($eligible as $employeeId => $period) {
                if (! in_array($employeeId, $cancelled, true)) {
                    $this->build($run, $period);
                }
            }
        });
    }

    /** Rebuilds one entry from the pay profile, discarding its adjustments. */
    public function regenerateEntry(PayrollEntry $entry): void
    {
        $this->ensureWithHrd($entry->run);
        $this->ensureNotCancelled($entry);

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
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function addLine(PayrollEntry $entry, array $data, User $actor): PayrollLine
    {
        $this->ensureWithHrd($entry->run);
        $this->ensureNotCancelled($entry);
        $component = isset($data['pay_component_id']) ? PayComponent::query()->find($data['pay_component_id']) : null;

        return DB::transaction(function () use ($entry, $data, $component, $actor) {
            $line = $entry->lines()->create([
                'kind' => $component ? ($component->kind->isDeduction() ? 'deduction' : 'earning') : $data['kind'],
                'pay_component_id' => $component?->id,
                'label' => $data['label'] ?? $component->name,
                'amount' => round((float) $data['amount'], 2),
                'adjusted' => true,
                'adjusted_by' => $actor->id,
                'adjusted_at' => now(),
                'adjust_reason' => $data['reason'] ?? null,
                'sort_order' => ($entry->lines()->max('sort_order') ?? 0) + 1,
            ]);
            $this->total($entry);

            return $line;
        });
    }

    /**
     * Overrides a line's amount. The first override keeps the amount the
     * line was generated with (`original_amount`); who, when and why are
     * recorded each time. Putting it back to the original clears the override.
     */
    public function updateLine(PayrollLine $line, float $amount, User $actor, ?string $reason = null): void
    {
        $this->ensureWithHrd($line->entry->run);
        $this->ensureNotCancelled($line->entry);

        DB::transaction(function () use ($line, $amount, $actor, $reason) {
            $amount = round($amount, 2);
            $original = $line->original_amount ?? $line->amount;
            $restored = $line->original_amount !== null && (float) $line->original_amount === $amount;

            $line->forceFill([
                'amount' => $amount,
                'original_amount' => $restored ? null : $original,
                'adjusted' => $restored ? $line->adjusted : true,
                'adjusted_by' => $actor->id,
                'adjusted_at' => now(),
                'adjust_reason' => $reason,
            ])->save();
            $this->total($line->entry);
        });
    }

    public function removeLine(PayrollLine $line): void
    {
        $this->ensureWithHrd($line->entry->run);
        $this->ensureNotCancelled($line->entry);

        DB::transaction(function () use ($line) {
            $entry = $line->entry;
            $line->delete();
            $this->total($entry);
        });
    }

    /** HRD sends the payroll to MSD, for the Accountant III to review and enter withholding tax. */
    public function submitToAccounting(PayrollRun $run, User $actor): void
    {
        $this->ensureWithHrd($run);
        if (! $run->entries()->exists()) {
            throw ValidationException::withMessages(['run' => 'The payroll has no one on it.']);
        }

        $run->forceFill(['status' => PayrollRunStatus::WithAccounting, 'submitted_at' => now(), 'submitted_by' => $actor->id])->save();
    }

    /**
     * Accounting enters withholding tax, by entry id; a blank or zero
     * removes it. Cancelled payslips and the accountant's own pay are left
     * alone. Returns how many entries changed.
     *
     * @param  array<int|string, float|int|string|null>  $taxes
     */
    public function enterWithholdingTax(PayrollRun $run, array $taxes, User $actor): int
    {
        $this->ensureWithAccounting($run);
        $component = PayComponent::query()->where('code', self::TAX_CODE)->first();
        if (! $component) {
            throw ValidationException::withMessages(['taxes' => 'There is no withholding tax pay component (code '.self::TAX_CODE.'); add it under Pay Components.']);
        }

        return DB::transaction(function () use ($run, $taxes, $actor, $component) {
            $changed = 0;
            $entries = $run->entries()->with(['employee', 'lines'])->whereNull('cancelled_at')->whereIn('id', array_keys($taxes))->get();
            foreach ($entries as $entry) {
                if ($entry->employee->user_id === $actor->id) {
                    continue;
                }

                $amount = round((float) ($taxes[$entry->id] ?? 0), 2);
                $current = $entry->lines->where('pay_component_id', $component->id);
                if ($current->count() <= 1 && round((float) $current->sum('amount'), 2) === $amount) {
                    continue;
                }

                $entry->lines()->where('pay_component_id', $component->id)->delete();
                if ($amount > 0) {
                    $entry->lines()->create([
                        'kind' => 'deduction',
                        'pay_component_id' => $component->id,
                        'label' => $component->name,
                        'amount' => $amount,
                        'adjusted_by' => $actor->id,
                        'adjusted_at' => now(),
                        'sort_order' => ($entry->lines->max('sort_order') ?? 0) + 1,
                    ]);
                }
                $this->total($entry);
                $changed++;
            }

            return $changed;
        });
    }

    /**
     * The withholding tax on the latest finalized payroll before this one,
     * by employee id: a guide for Accounting, never copied in.
     *
     * @return array<int, float>
     */
    public function previousWithholdingTax(PayrollRun $run): array
    {
        $previous = PayrollRun::query()->where('status', PayrollRunStatus::Finalized)
            ->where(fn ($query) => $query->where('year', '<', $run->year)->orWhere(fn ($query) => $query->where('year', $run->year)->where('month', '<', $run->month)))
            ->orderByDesc('year')->orderByDesc('month')->first();
        if (! $previous || ! $this->taxComponentId()) {
            return [];
        }

        return PayrollLine::query()->join('payroll_entries', 'payroll_entries.id', '=', 'payroll_lines.payroll_entry_id')
            ->where('payroll_entries.payroll_run_id', $previous->id)->whereNull('payroll_entries.cancelled_at')
            ->where('payroll_lines.pay_component_id', $this->taxComponentId())
            ->groupBy('payroll_entries.employee_id')
            ->selectRaw('payroll_entries.employee_id, SUM(payroll_lines.amount) as tax')
            ->pluck('tax', 'employee_id')->map(fn ($tax) => round((float) $tax, 2))->all();
    }

    /** Accounting sends the payroll back to HRD, with an optional note. */
    public function returnToHrd(PayrollRun $run, User $actor, ?string $note = null): void
    {
        $this->ensureWithAccounting($run);

        $run->forceFill(['status' => PayrollRunStatus::Returned, 'returned_at' => now(), 'returned_by' => $actor->id, 'accounting_note' => $note])->save();
    }

    public function finalize(PayrollRun $run, User $actor): void
    {
        if ($run->status !== PayrollRunStatus::Returned) {
            throw ValidationException::withMessages(['run' => match ($run->status) {
                PayrollRunStatus::Draft => 'Submit the payroll to Accounting for withholding tax first.',
                PayrollRunStatus::WithAccounting => 'The payroll is still with Accounting.',
                default => "The payroll for {$run->period} is already finalized.",
            }]);
        }
        if (! $run->entries()->exists()) {
            throw ValidationException::withMessages(['run' => 'The payroll has no one on it.']);
        }

        $run->forceFill(['status' => PayrollRunStatus::Finalized, 'finalized_at' => now(), 'finalized_by' => $actor->id])->save();
    }

    /**
     * Correcting a finalized payroll starts here, so it always has a reason.
     * It goes back to HRD with Accounting's tax kept; HRD sends it to
     * Accounting again if the tax should change.
     */
    public function reopen(PayrollRun $run, ?string $reason = null): void
    {
        if (! $run->isFinalized()) {
            throw ValidationException::withMessages(['run' => 'The payroll is not finalized.']);
        }

        $run->forceFill(['status' => PayrollRunStatus::Returned, 'finalized_at' => null, 'finalized_by' => null, 'reopen_reason' => $reason])->save();
    }

    /**
     * Cancels one person's payslip: it stays on the payroll for the audit
     * trail but counts toward no total, loan balance or payslip request.
     */
    public function cancelEntry(PayrollEntry $entry, string $reason, User $actor): void
    {
        $this->ensureNotCancelled($entry);

        DB::transaction(function () use ($entry, $reason, $actor) {
            $entry->forceFill(['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => $reason])->save();
            PayslipRequest::query()->where('payroll_entry_id', $entry->id)
                ->whereIn('status', [PayslipRequestStatus::Pending, PayslipRequestStatus::Approved])
                ->update(['status' => PayslipRequestStatus::Cancelled]);
        });
    }

    public function delete(PayrollRun $run): void
    {
        if ($run->status !== PayrollRunStatus::Draft) {
            throw ValidationException::withMessages(['run' => 'Only a draft payroll that was never submitted can be deleted.']);
        }
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
        $entries = $run->entries()->whereNull('cancelled_at');

        return [
            'people' => (clone $entries)->count(),
            'basic' => round((float) (clone $entries)->sum('basic_salary'), 2),
            'gross' => round((float) (clone $entries)->sum('gross_pay'), 2),
            'deductions' => round((float) (clone $entries)->sum('total_deductions'), 2),
            'net' => round((float) (clone $entries)->sum('net_pay'), 2),        ];
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
            'release_on' => $run->release_on?->toDateString(),
            'created_by' => $run->creator?->name,
            'submitted_at' => $run->submitted_at?->toIso8601String(),
            'submitted_by' => $run->submitter?->name,
            'returned_at' => $run->returned_at?->toIso8601String(),
            'returned_by' => $run->returner?->name,
            'accounting_note' => $run->accounting_note,
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
            'withholding_tax' => round((float) ($entry->relationLoaded('lines') ? $entry->lines : $entry->lines()->get())
                ->where('pay_component_id', $this->taxComponentId())->sum('amount'), 2),
            'below_minimum' => $net < self::MINIMUM_NET_PAY,
            'cancelled' => $entry->isCancelled(),
            'cancel_reason' => $entry->cancel_reason,
            'cancelled_at' => $entry->cancelled_at?->toIso8601String(),
            'adjusted' => $entry->relationLoaded('lines') ? $entry->lines->contains('adjusted', true) : $entry->lines()->where('adjusted', true)->exists(),
            'lines' => $withLines ? $entry->lines->map(fn (PayrollLine $line) => [
                'id' => $line->id,
                'kind' => $line->kind,
                'label' => $line->label,
                'amount' => (float) $line->amount,
                'adjusted' => $line->adjusted,
                'original_amount' => $line->original_amount !== null ? (float) $line->original_amount : null,
                'adjust_reason' => $line->adjust_reason,
                'adjusted_by' => $line->adjuster?->name,
                'adjusted_at' => $line->adjusted_at?->toIso8601String(),
                'loan' => $line->employee_loan_id !== null,
                'remitted_on' => $line->remitted_on?->toDateString(),
            ])->values()->all() : [],
        ];
    }

    /**
     * Who is on the payroll for the month, with the period that pays them
     * (the latest one in force that month) and the basic salary across
     * every period in force. A promotion or step increase part-way through
     * the month pays each period for its own days, so the new grade starts
     * on the date of the promotion; a past month keeps the grade held then.
     *
     * @return Collection<int, PlantillaAppointment> keyed by employee id; each carries `basic_for_month`
     */
    private function eligible(Carbon $month): Collection
    {
        $staff = $this->inService($month)->keyBy('id');
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $periods = PlantillaAppointment::query()->complete()->with('item.organizationalUnit')
            ->where('status', EmploymentStatus::Permanent->value)
            ->whereIn('employee_id', $staff->keys())
            ->whereDate('date_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('date_to')->orWhereDate('date_to', '>=', $start))
            ->orderBy('date_from')->orderBy('id')->get()
            ->groupBy('employee_id');

        return $periods->map(function (Collection $held) use ($staff, $start, $end, $month) {
            $priced = $held->filter(fn (PlantillaAppointment $period) => $this->schedules->salaryOf($period, $month) !== null);
            if ($priced->isEmpty()) {
                return null;
            }

            $days = 0;
            $basic = 0.0;
            foreach ($priced as $period) {
                $from = $period->date_from->gt($start) ? $period->date_from->copy() : $start->copy();
                $to = $period->date_to && $period->date_to->lt($end) ? $period->date_to->copy() : $end->copy();
                $share = min($from->diffInDays($to) + 1, $month->daysInMonth - $days);
                $days += $share;
                $basic += (float) $this->schedules->salaryOf($period, $month) * $share / $month->daysInMonth;
            }

            $current = $priced->last();
            $current->setRelation('employee', $staff[$current->employee_id]);
            $current->setAttribute('basic_for_month', round($basic, 2));

            return $current;
        })->filter();
    }

    /**
     * @return Collection<int, Employee>
     */
    private function inService(Carbon $month): Collection
    {
        return Employee::query()->with('user')
            ->whereHas('plantillaAppointments', fn ($query) => $query->where(fn ($query) => $query->where('status', EmploymentStatus::Permanent->value)->orWhereNull('status'))
                ->where(fn ($query) => $query->whereNull('date_from')->orWhereDate('date_from', '<=', $month->copy()->endOfMonth()))
                ->where(fn ($query) => $query->whereNull('date_to')->orWhereDate('date_to', '>=', $month->copy()->startOfMonth())))
            ->where(fn ($query) => $query->whereNull('separation_date')->orWhereDate('separation_date', '>=', $month->copy()->startOfMonth()))
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->get();
    }

    private function build(PayrollRun $run, PlantillaAppointment $period): void
    {
        $employee = $period->employee;
        $month = $run->month();
        $basic = (float) $period->getAttribute('basic_for_month');

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

        // Accounting's withholding tax stays; everything else is rebuilt.
        $entry->lines()->where(fn ($query) => $query->whereNull('pay_component_id')->orWhere('pay_component_id', '!=', $this->taxComponentId() ?? 0))->delete();
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
            ->where('code', '!=', self::TAX_CODE) // entered by Accounting
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
            // A premium that splits to a half-centavo gives the employee the lower half (PhilHealth's rule).
            PayComputation::Percent => round(min(max($basic, (float) ($component->base_min ?? 0)), $component->base_max !== null ? (float) $component->base_max : PHP_FLOAT_MAX)
                * (float) $component->rate / 100, 2, PHP_ROUND_HALF_DOWN),
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

        $entry->forceFill([
            'gross_pay' => $gross,
            'total_deductions' => $deductions,
            'net_pay' => $net,
        ])->save();
    }

    private function ensureNotCancelled(PayrollEntry $entry): void
    {
        if ($entry->isCancelled()) {
            throw ValidationException::withMessages(['entry' => "The payslip of {$entry->name} was cancelled."]);
        }
    }

    private function ensureWithHrd(PayrollRun $run): void
    {
        if (! $run->status->isWithHrd()) {
            throw ValidationException::withMessages(['run' => $run->isFinalized()
                ? "The payroll for {$run->period} is finalized; reopen it to make changes."
                : "The payroll for {$run->period} is with Accounting; it can be changed once it is returned."]);
        }
    }

    private function ensureWithAccounting(PayrollRun $run): void
    {
        if ($run->status !== PayrollRunStatus::WithAccounting) {
            throw ValidationException::withMessages(['run' => "The payroll for {$run->period} is not with Accounting."]);
        }
    }

    private function taxComponentId(): ?int
    {
        return $this->taxComponentId ??= PayComponent::query()->where('code', self::TAX_CODE)->value('id');
    }
}
