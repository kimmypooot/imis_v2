<?php

namespace App\Support\HR\Payroll;

use App\Enums\PayrollRunStatus;
use App\Models\EmployeeLoan;
use App\Models\PayrollLine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * HR-8: what a loan still owes. Nothing is stored: the balance is the
 * total payable, less what was paid before IMIS, less the loan's lines on
 * finalized payrolls (cancelled payslips excluded) — so reopening and
 * correcting a payroll can never leave a balance wrong.
 *
 * A loan's installments follow one lifecycle, none of which means money
 * moved (IMIS is not the payroll system):
 * - scheduled: projected for a month with no payroll line yet;
 * - reflected: on a draft payroll's payslip;
 * - confirmed: on a finalized payroll;
 * - paid: HR marked it remitted (`remitted_on`);
 * - overdue: confirmed, but not marked paid within OVERDUE_AFTER_DAYS of
 *   the payroll's last release.
 */
class Loans
{
    /** Loans with this many amortizations or fewer left are "nearly paid". */
    public const NEARLY_PAID_MONTHS = 2;

    /** A confirmed deduction not marked paid this long after release is overdue. */
    public const OVERDUE_AFTER_DAYS = 30;

    /** Projected installments shown after the history, unless asked for more. */
    public const PROJECTED_MONTHS = 12;

    private const STATUS_LABELS = [
        'scheduled' => 'Scheduled',
        'reflected' => 'Reflected in Payslip',
        'confirmed' => 'Confirmed',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
    ];

    /**
     * Paid through finalized payrolls, per loan id (one query for many).
     *
     * @param  array<int, int>  $loanIds
     * @return Collection<int, float>
     */
    public function paidThroughPayroll(array $loanIds, ?int $exceptRunId = null): Collection
    {
        if ($loanIds === []) {
            return collect();
        }

        return PayrollLine::query()
            ->whereIn('employee_loan_id', $loanIds)
            ->whereHas('entry', fn ($query) => $query->whereNull('cancelled_at'))
            ->whereHas('entry.run', fn ($query) => $query->where('status', PayrollRunStatus::Finalized->value)
                ->when($exceptRunId, fn ($query) => $query->whereKeyNot($exceptRunId)))
            ->selectRaw('employee_loan_id, SUM(amount) as paid')
            ->groupBy('employee_loan_id')
            ->pluck('paid', 'employee_loan_id')
            ->map(fn ($paid) => (float) $paid);
    }

    public function balance(EmployeeLoan $loan, ?float $paidThroughPayroll = null, ?int $exceptRunId = null): float
    {
        $paid = $paidThroughPayroll ?? (float) ($this->paidThroughPayroll([$loan->id], $exceptRunId)[$loan->id] ?? 0);

        return max(0, round((float) $loan->total_payable - (float) $loan->paid_before - $paid, 2));
    }

    /**
     * What to deduct for the month that starts on $month: the amortization,
     * or what's left if less; nothing before it starts, after it was
     * stopped, or once it's paid. `$exceptRunId` leaves out the payroll
     * being (re)generated, so its own draft lines don't count as paid.
     */
    public function amortizationFor(EmployeeLoan $loan, Carbon $month, float $balance): float
    {
        if ($loan->start_month->gt($month->copy()->endOfMonth())
            || ($loan->stopped_on && $loan->stopped_on->lt($month->copy()->startOfMonth()))) {
            return 0.0;
        }

        return round(min((float) $loan->monthly_amortization, $balance), 2);
    }

    /** "active", "paid" (completed), "stopped" (suspended) or "cancelled". */
    public function status(EmployeeLoan $loan, float $balance): string
    {
        return match (true) {
            $balance <= 0 => 'paid',
            $loan->cancelled_on !== null => 'cancelled',
            $loan->stopped_on !== null => 'stopped',
            default => 'active',
        };
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Completed',
            'cancelled' => 'Cancelled',
            'stopped' => 'Suspended',
            default => 'Active',
        };
    }

    /** The lifecycle stage of one deduction taken on a payroll. */
    public function lineStatus(PayrollLine $line): string
    {
        $run = $line->entry->run;

        return match (true) {
            ! $run->isFinalized() => 'reflected',
            $line->remitted_on !== null => 'paid',
            $run->second_release_on && $run->second_release_on->copy()->addDays(self::OVERDUE_AFTER_DAYS)->lt(today()) => 'overdue',
            default => 'confirmed',
        };
    }

    /** HR confirms a deduction was actually remitted. */
    public function markRemitted(PayrollLine $line, User $by, ?string $date = null): void
    {
        $this->ensureConfirmable($line);

        $line->forceFill(['remitted_on' => $date ?? today()->toDateString(), 'remitted_by' => $by->id])->save();
    }

    public function unmarkRemitted(PayrollLine $line): void
    {
        $this->ensureConfirmable($line);

        $line->forceFill(['remitted_on' => null, 'remitted_by' => null])->save();
    }

    /**
     * The loan's installments: what each payroll took, then the months
     * still to come (not when it is suspended, cancelled or complete).
     *
     * @return array{rows: array<int, array<string, mixed>>, expected_end: ?string, more: int}
     */
    public function schedule(EmployeeLoan $loan, int $projected = self::PROJECTED_MONTHS): array
    {
        $lines = $loan->payrollLines()->with('entry.run')->get()
            ->reject(fn (PayrollLine $line) => $line->entry->isCancelled())
            ->sortBy(fn (PayrollLine $line) => $line->entry->run->year * 100 + $line->entry->run->month)
            ->values();

        $rows = $lines->map(fn (PayrollLine $line) => [
            'month' => $line->entry->run->month()->format('Y-m'),
            'amount' => (float) $line->amount,
            'status' => $status = $this->lineStatus($line),
            'status_label' => self::STATUS_LABELS[$status],
            'line_id' => $line->id,
            'remitted_on' => $line->remitted_on?->toDateString(),
            'finalized' => $line->entry->run->isFinalized(),
        ])->all();

        $balance = $this->balance($loan, (float) $lines->filter(fn (PayrollLine $line) => $line->entry->run->isFinalized())->sum('amount'));
        $remaining = round($balance - (float) $lines->reject(fn (PayrollLine $line) => $line->entry->run->isFinalized())->sum('amount'), 2);
        $amortization = (float) $loan->monthly_amortization;
        $last = $lines->last()?->entry->run->month();
        $next = $last
            ? $last->copy()->addMonthNoOverflow()
            : ($loan->start_month->gt(today()) ? $loan->start_month->copy()->startOfMonth() : today()->startOfMonth());
        $expectedEnd = $last?->format('Y-m');
        $more = 0;

        if ($remaining > 0 && $amortization > 0 && ! $loan->stopped_on) {
            $installments = (int) ceil($remaining / $amortization);
            $expectedEnd = $next->copy()->addMonthsNoOverflow($installments - 1)->format('Y-m');

            for ($i = 0; $i < min($installments, $projected); $i++) {
                $amount = round(min($amortization, $remaining), 2);
                $rows[] = [
                    'month' => $next->copy()->addMonthsNoOverflow($i)->format('Y-m'),
                    'amount' => $amount,
                    'status' => 'scheduled',
                    'status_label' => self::STATUS_LABELS['scheduled'],
                    'line_id' => null,
                    'remitted_on' => null,
                    'finalized' => false,
                ];
                $remaining = round($remaining - $amount, 2);
            }
            $more = max(0, $installments - $projected);
        }

        return ['rows' => $rows, 'expected_end' => $expectedEnd, 'more' => $more];
    }

    /**
     * @param  Collection<int, EmployeeLoan>  $loans
     * @return Collection<int, array<string, mixed>>
     */
    public function presentMany(Collection $loans, bool $withSchedule = false): Collection
    {
        $paid = $this->paidThroughPayroll($loans->pluck('id')->all());

        return $loans->map(fn (EmployeeLoan $loan) => $this->present($loan, (float) ($paid[$loan->id] ?? 0), $withSchedule));
    }

    /**
     * @return array<string, mixed>
     */
    public function present(EmployeeLoan $loan, ?float $paidThroughPayroll = null, bool $withSchedule = false): array
    {
        $paidThroughPayroll ??= (float) ($this->paidThroughPayroll([$loan->id])[$loan->id] ?? 0);
        $balance = $this->balance($loan, $paidThroughPayroll);
        $amortization = (float) $loan->monthly_amortization;
        $status = $this->status($loan, $balance);
        $schedule = $withSchedule ? $this->schedule($loan) : null;

        return [
            'id' => $loan->id,
            'employee_id' => $loan->employee_id,
            'employee' => $loan->employee?->user?->name,
            'pay_component_id' => $loan->pay_component_id,
            'type' => $loan->component?->name,
            'reference' => $loan->reference,
            'granted_on' => $loan->granted_on?->toDateString(),
            'principal' => (float) $loan->principal,
            'total_payable' => (float) $loan->total_payable,
            'monthly_amortization' => $amortization,
            'start_month' => $loan->start_month->format('Y-m'),
            'paid_before' => (float) $loan->paid_before,
            'paid' => round((float) $loan->paid_before + $paidThroughPayroll, 2),
            'balance' => $balance,
            'months_left' => $amortization > 0 ? (int) ceil($balance / $amortization) : null,
            'installments_paid' => $loan->payrollLines()
                ->whereHas('entry', fn ($query) => $query->whereNull('cancelled_at'))
                ->whereHas('entry.run', fn ($query) => $query->where('status', PayrollRunStatus::Finalized->value))->count(),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'stopped_on' => $loan->stopped_on?->toDateString(),
            'cancelled_on' => $loan->cancelled_on?->toDateString(),
            'remarks' => $loan->remarks,
            'deletable' => ! $loan->payrollLines()->exists(),
            'expected_end' => $schedule['expected_end'] ?? null,
            'schedule' => $schedule,
        ];
    }

    private function ensureConfirmable(PayrollLine $line): void
    {
        if ($line->employee_loan_id === null || ! $line->entry->run->isFinalized() || $line->entry->isCancelled()) {
            throw ValidationException::withMessages(['line' => 'Only a loan deduction on a finalized payroll can be marked paid.']);
        }
    }
}
