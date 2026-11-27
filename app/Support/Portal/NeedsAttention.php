<?php

namespace App\Support\Portal;

use App\Enums\LeaveApplicationStatus;
use App\Enums\PdsSubmissionStatus;
use App\Enums\SalnStatus;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationStep;
use App\Models\PayrollRun;
use App\Models\Saln;
use App\Models\User;
use App\Support\HR\Dtr\DtrSheets;
use App\Support\HR\Leave\LeaveApplications;
use App\Support\HR\Payroll\PayrollAccounting;
use App\Support\HR\Payroll\PayslipRequests;

/**
 * The dashboard's "Needs your attention" list: what is waiting on this
 * person right now, each with the page that settles it. Things to decide
 * for others (leave applications, payslip requests, payroll withholding
 * tax), and their own items sent back or still to sign (a returned leave
 * application, SALN or PDS, last month's DTR). Only what they can act on;
 * news and figures stay in the tiles and the bell.
 */
class NeedsAttention
{
    public function __construct(
        private readonly LeaveApplications $applications,
        private readonly PayslipRequests $payslips,
        private readonly PayrollAccounting $accounting,
        private readonly DtrSheets $sheets,
    ) {}

    /**
     * @return array<int, array{key: string, kind: string, title: string, meta: string, href: string, action: string}>
     */
    public function for(User $user): array
    {
        $items = [];

        $queue = $this->applications->queueFor($user);
        if ($queue->isNotEmpty()) {
            $count = $queue->count();
            $names = $queue->take(2)->map(fn (LeaveApplicationStep $step): string => sprintf(
                '%s (%s, %s)',
                $step->application->employee->user->name,
                $step->application->leaveType->code,
                $this->days((float) $step->application->days),
            ))->implode(' · ');

            $items[] = [
                'key' => 'leave-approvals',
                'kind' => 'approve',
                'title' => $count === 1 ? 'Approve 1 leave application' : "Approve {$count} leave applications",
                'meta' => $names.($count > 2 ? ' and '.($count - 2).' more' : ''),
                'href' => '/leave/approvals',
                'action' => 'Review',
            ];
        }

        $payslips = $this->payslips->queueFor($user)->count();
        if ($payslips) {
            $items[] = [
                'key' => 'payslip-approvals',
                'kind' => 'approve',
                'title' => $payslips === 1 ? 'Approve 1 payslip request' : "Approve {$payslips} payslip requests",
                'meta' => 'Requested by staff, oldest first',
                'href' => '/payslips/approvals',
                'action' => 'Review',
            ];
        }

        if ($user->can('enterTax', PayrollRun::class)) {
            $payrolls = $this->accounting->waiting()->count();
            if ($payrolls) {
                $items[] = [
                    'key' => 'payroll-tax',
                    'kind' => 'review',
                    'title' => $payrolls === 1 ? 'Enter withholding tax for 1 payroll' : "Enter withholding tax for {$payrolls} payrolls",
                    'meta' => 'Sent by HRD; returns to them to finalize',
                    'href' => '/payroll/tax-review',
                    'action' => 'Open',
                ];
            }
        }

        if ($employee = $user->employee) {
            array_push($items, ...$this->own($employee));
        }

        return $items;
    }

    /**
     * The employee's own items: sent back to them, or still to sign.
     *
     * @return array<int, array{key: string, kind: string, title: string, meta: string, href: string, action: string}>
     */
    private function own(Employee $employee): array
    {
        $items = [];

        LeaveApplication::query()
            ->with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('status', LeaveApplicationStatus::Returned)
            ->latest('updated_at')
            ->limit(3)
            ->get()
            ->each(function (LeaveApplication $application) use (&$items): void {
                $items[] = [
                    'key' => "leave-returned-{$application->id}",
                    'kind' => 'returned',
                    'title' => "{$application->leaveType->name} returned for correction",
                    'meta' => $application->reference_number.' · '.$application->date_from->format('j M').($application->date_to->isSameDay($application->date_from) ? '' : '–'.$application->date_to->format('j M')),
                    'href' => '/my/leave/applications',
                    'action' => 'Fix',
                ];
            });

        $last = today()->subMonthNoOverflow();
        $toSign = collect($this->sheets->forMonth($employee, $last)['sheets'])
            ->contains(fn (array $sheet): bool => (int) $sheet['totals']['minutes'] > 0 && ($sheet['signature']['status'] ?? null) !== 'valid');
        if ($toSign) {
            $items[] = [
                'key' => 'dtr-sign',
                'kind' => 'sign',
                'title' => "Sign your {$last->format('F')} DTR",
                'meta' => 'Your daily time record for '.$last->format('F Y').' is not signed yet',
                'href' => '/my/dtr?month='.$last->format('Y-m'),
                'action' => 'Sign',
            ];
        }

        $employee->salns()
            ->where('status', SalnStatus::Returned)
            ->with('events')
            ->latest('reckoning_date')
            ->get()
            ->each(function (Saln $saln) use (&$items): void {
                $remarks = $saln->events->sortByDesc('id')->firstWhere('action', 'returned')?->remarks;

                $items[] = [
                    'key' => "saln-returned-{$saln->id}",
                    'kind' => 'returned',
                    'title' => "SALN {$saln->reckoning_date->year} returned for correction",
                    'meta' => $remarks ?: 'Sent back by the SALN Committee',
                    'href' => "/my/saln/{$saln->id}",
                    'action' => 'Fix',
                ];
            });

        $pds = $employee->submissions()->latest('id')->first();
        if ($pds?->status === PdsSubmissionStatus::Returned) {
            $items[] = [
                'key' => 'pds-returned',
                'kind' => 'returned',
                'title' => 'PDS returned by HR',
                'meta' => $pds->remarks ?: 'Correct it and submit it again',
                'href' => '/my/pds',
                'action' => 'Fix',
            ];
        }

        return $items;
    }

    private function days(float $days): string
    {
        $shown = rtrim(rtrim(number_format($days, 3), '0'), '.');

        return $shown.($days == 1.0 ? ' day' : ' days');
    }
}
