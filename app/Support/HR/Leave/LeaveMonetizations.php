<?php

namespace App\Support\HR\Leave;

use App\Models\Employee;
use App\Models\LeaveMonetization;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\HR\Plantilla\Plantilla;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-6c: the only writer of leave monetization and terminal leave.
 * Ports the legacy MonetizationService rules (Omnibus Rules on Leave):
 *
 * - Partial monetization of Vacation Leave: at most 50 % of the VL
 *   balance; the employee keeps at least 15 days of VL and SL combined;
 *   once a calendar year (a pending, approved or released request counts).
 * - Terminal leave: all remaining VL and SL, on separation; HR files it.
 * - Amount: monthly salary ÷ 22 × days. The salary is the one in force for
 *   the item they hold now (HR-4's plantilla and salary schedule).
 * - HR approves (the credits come off the ledger), rejects with a reason,
 *   then marks an approved request released once paid.
 */
class LeaveMonetizations
{
    public const MAX_SHARE_OF_VL = 0.5;

    public const DAYS_TO_KEEP = 15.0;

    public const WORKING_DAYS_A_MONTH = 22.0;

    public function __construct(
        private readonly LeaveCredits $credits,
        private readonly Plantilla $plantilla,
    ) {}

    /**
     * What a person could monetize today, and why not.
     *
     * @return array{vl: float, sl: float, max_days: float, monthly_salary: ?float, daily_rate: ?float, issues: array<int, string>}
     */
    public function partialTerms(Employee $employee): array
    {
        $vl = $this->balance($employee, 'VL');
        $sl = $this->balance($employee, 'SL');
        $salary = $this->monthlySalary($employee);
        $max = round(min(floor($vl * self::MAX_SHARE_OF_VL * 1000) / 1000, max(0, $vl + $sl - self::DAYS_TO_KEEP)), 3);

        $issues = [];
        if ($salary === null) {
            $issues[] = 'Your monthly salary isn\'t on file (no current plantilla item or salary schedule). Contact HR.';
        }
        if ($max <= 0) {
            $issues[] = 'You need more than '.self::DAYS_TO_KEEP.' days of VL and SL combined to monetize.';
        }
        if ($this->usedThisYear($employee)) {
            $issues[] = 'You already have a monetization request this year (once a year).';
        }

        return [
            'vl' => $vl,
            'sl' => $sl,
            'max_days' => max(0, $max),
            'monthly_salary' => $salary,
            'daily_rate' => $salary !== null ? round($salary / self::WORKING_DAYS_A_MONTH, 2) : null,
            'issues' => $issues,
        ];
    }

    public function requestPartial(Employee $employee, float $days, ?string $remarks, User $actor): LeaveMonetization
    {
        $terms = $this->partialTerms($employee);

        if ($terms['issues'] !== []) {
            throw ValidationException::withMessages(['days' => implode(' ', $terms['issues'])]);
        }
        if ($days <= 0 || $days > $terms['max_days']) {
            throw ValidationException::withMessages(['days' => "You can monetize up to {$terms['max_days']} days (half your VL, keeping ".self::DAYS_TO_KEEP.' days of VL and SL).']);
        }

        return $this->create($employee, 'partial', $days, 0, $terms, $remarks, $actor);
    }

    /**
     * HR files terminal leave for someone separated (or separating).
     */
    public function requestTerminal(Employee $employee, ?string $remarks, User $actor): LeaveMonetization
    {
        if (! $employee->separation_date) {
            throw ValidationException::withMessages(['employee_id' => 'Record the separation date on their employee record first.']);
        }
        if (LeaveMonetization::query()->where('employee_id', $employee->id)->where('kind', 'terminal')->whereIn('status', ['pending', 'approved', 'released'])->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'Terminal leave was already filed for them.']);
        }

        $terms = $this->partialTerms($employee);
        if ($terms['monthly_salary'] === null) {
            throw ValidationException::withMessages(['employee_id' => 'Their monthly salary isn\'t on file (no current plantilla item or salary schedule).']);
        }
        if ($terms['vl'] + $terms['sl'] <= 0) {
            throw ValidationException::withMessages(['employee_id' => 'They have no VL or SL left.']);
        }

        return $this->create($employee, 'terminal', max(0, $terms['vl']), max(0, $terms['sl']), $terms, $remarks, $actor);
    }

    public function approve(LeaveMonetization $request, User $by, ?string $remarks): void
    {
        $this->assertPending($request);

        DB::transaction(function () use ($request, $by, $remarks) {
            $request->forceFill(['status' => 'approved', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_remarks' => $remarks])->save();

            $what = $request->kind === 'terminal' ? 'Terminal leave' : 'Monetization';
            foreach (['VL' => (float) $request->vl_days, 'SL' => (float) $request->sl_days] as $code => $days) {
                if ($days > 0 && ($type = LeaveType::query()->where('code', $code)->first())) {
                    $this->credits->monetize($request->employee, $type, $days, "{$what}, {$request->reference_number}", $by);
                }
            }
        });
    }

    public function reject(LeaveMonetization $request, User $by, string $reason): void
    {
        $this->assertPending($request);
        $request->forceFill(['status' => 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_remarks' => $reason])->save();
    }

    public function markReleased(LeaveMonetization $request): void
    {
        if ($request->status !== 'approved') {
            throw ValidationException::withMessages(['status' => 'Only an approved request can be marked released.']);
        }

        $request->forceFill(['status' => 'released', 'released_at' => now()])->save();
    }

    public function cancel(LeaveMonetization $request): void
    {
        $this->assertPending($request);
        $request->forceFill(['status' => 'cancelled'])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(LeaveMonetization $request): array
    {
        $request->loadMissing(['employee.user', 'decider:id,name']);

        return [
            'id' => $request->id,
            'reference_number' => $request->reference_number,
            'employee_id' => $request->employee_id,
            'name' => $request->employee->user->name,
            'kind' => $request->kind,
            'kind_label' => $request->kind === 'terminal' ? 'Terminal leave' : 'Monetization',
            'vl_days' => (float) $request->vl_days,
            'sl_days' => (float) $request->sl_days,
            'days' => (float) $request->vl_days + (float) $request->sl_days,
            'vl_balance' => (float) $request->vl_balance,
            'sl_balance' => (float) $request->sl_balance,
            'monthly_salary' => (float) $request->monthly_salary,
            'amount' => (float) $request->amount,
            'status' => $request->status,
            'remarks' => $request->remarks,
            'decision_remarks' => $request->decision_remarks,
            'decided_by' => $request->decider?->name,
            'created_at' => $request->created_at->toIso8601String(),
            'decided_at' => $request->decided_at?->toIso8601String(),
            'released_at' => $request->released_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array{vl: float, sl: float, monthly_salary: ?float}  $terms
     */
    private function create(Employee $employee, string $kind, float $vlDays, float $slDays, array $terms, ?string $remarks, User $actor): LeaveMonetization
    {
        $request = new LeaveMonetization;
        $request->forceFill([
            'employee_id' => $employee->id,
            'kind' => $kind,
            'vl_days' => round($vlDays, 3),
            'sl_days' => round($slDays, 3),
            'vl_balance' => $terms['vl'],
            'sl_balance' => $terms['sl'],
            'monthly_salary' => $terms['monthly_salary'],
            'amount' => round($terms['monthly_salary'] / self::WORKING_DAYS_A_MONTH * ($vlDays + $slDays), 2),
            'calendar_year' => today()->year,
            'status' => 'pending',
            'remarks' => $remarks,
            'requested_by' => $actor->id,
        ])->save();

        $request->forceFill(['reference_number' => sprintf('MON-%d-%05d', today()->year, $request->id)])->save();

        return $request;
    }

    private function usedThisYear(Employee $employee): bool
    {
        return LeaveMonetization::query()->where('employee_id', $employee->id)->where('kind', 'partial')
            ->where('calendar_year', today()->year)->whereIn('status', ['pending', 'approved', 'released'])->exists();
    }

    private function balance(Employee $employee, string $code): float
    {
        $type = LeaveType::query()->where('code', $code)->first();

        return $type ? $this->credits->balance($employee, $type) : 0.0;
    }

    private function monthlySalary(Employee $employee): ?float
    {
        $current = $this->plantilla->historyOf($employee)->firstWhere('current', true);

        return isset($current['monthly_salary']) ? (float) $current['monthly_salary'] : null;
    }

    private function assertPending(LeaveMonetization $request): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Only a pending request can be changed.']);
        }
    }
}
