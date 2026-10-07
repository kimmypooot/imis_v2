<?php

namespace App\Support\Executive\Sources;

use App\Enums\PayrollRunStatus;
use App\Enums\PayslipRequestStatus;
use App\Enums\PermissionName;
use App\Models\PayrollRun;
use App\Models\PayslipRequest;

/**
 * Payroll (HR-8): this month's gross and net pay (once drafted) and
 * payslip requests waiting on the head of HRD. Starter figures; totals
 * only, never a person's pay.
 */
class PayrollSource implements MetricSource
{
    public function key(): string
    {
        return 'payroll';
    }

    public function label(): string
    {
        return 'Payroll';
    }

    public function kind(): string
    {
        return 'module';
    }

    public function collect(): array
    {
        $today = today();
        $run = PayrollRun::query()->where('year', $today->year)->where('month', $today->month)->first();
        $state = match (true) {
            $run === null => 'Not yet drafted',
            $run->status === PayrollRunStatus::Finalized => 'Finalized',
            default => 'Draft — not final',
        };
        $link = ['href' => '/admin/hr/payroll', 'permission' => PermissionName::PayrollView->value];

        return [
            ['key' => 'payroll.gross_month', 'label' => 'Gross pay this month', 'value' => $run ? (float) $run->entries()->sum('gross_pay') : null, 'unit' => 'peso', 'period' => $today->format('F Y'), 'note' => $state, ...$link],
            ['key' => 'payroll.net_month', 'label' => 'Net pay this month', 'value' => $run ? (float) $run->entries()->sum('net_pay') : null, 'unit' => 'peso', 'period' => $today->format('F Y'), 'note' => $state, ...$link],
            ['key' => 'payroll.payslip_requests_pending', 'label' => 'Payslip requests awaiting approval', 'value' => PayslipRequest::query()->where('status', PayslipRequestStatus::Pending)->count(), 'unit' => 'count', ...$link],
        ];
    }
}
