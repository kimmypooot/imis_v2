<?php

namespace App\Support\HR;

use App\Enums\PayrollRunStatus;
use App\Enums\PermissionName;
use App\Models\LeaveLedgerEntry;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\HR\Dtr\DtrOverview;
use App\Support\HR\Dtr\TardinessDeductions;
use App\Support\HR\Dtr\UploadMonitor;
use App\Support\HR\SalaryNotices\StepIncrementsDue;
use App\Support\HR\Saln\SalnObligations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The HR dashboard banner: how many things are waiting on HR, and where this
 * month's HR cycle stands — biometric logs in, DTR entries verified, last
 * month's tardiness charged, the payroll drafted, sent to Accounting and
 * finalized — plus seasonal steps when they are due (the January year start,
 * SALN filing until April 30, step increments coming due). Each step shows
 * only to people with its permission. The month's figures are shared by
 * everyone and kept for a few minutes, since some of them build every DTR.
 */
class HrMonth
{
    private const CACHE_SECONDS = 300;

    /**
     * @return array{month: string, waiting: int, steps: array<int, array{key: string, label: string, detail: string, state: string, href: string}>, actions: array<int, array{label: string, href: string, icon: string}>}
     */
    public function for(User $user): array
    {
        $today = today();
        $figures = Cache::remember('hr-month:'.$today->toDateString(), self::CACHE_SECONDS, fn () => $this->figures($today));
        $can = fn (PermissionName ...$permissions) => $user->hasAnyPermission(array_map(fn (PermissionName $permission) => $permission->value, $permissions));
        $queues = HrQueues::countsFor($user);
        $leaveWaiting = $queues['leaveApplications'];

        $steps = array_values(array_filter([
            $can(PermissionName::LeaveManage) && $today->month === 1 ? $this->yearStartStep($figures) : null,
            $can(PermissionName::DtrView, PermissionName::DtrManage) ? $this->biometricStep($figures) : null,
            $can(PermissionName::DtrView, PermissionName::DtrManage) ? $this->entriesStep($figures) : null,
            $can(PermissionName::LeaveManage) ? $this->tardinessStep($figures, $today) : null,
            $can(PermissionName::PayrollView, PermissionName::PayrollManage) ? $this->payrollStep($figures) : null,
            $can(PermissionName::SalnManage) && $figures['saln'] ? $this->salnStep($figures['saln']) : null,
            $can(PermissionName::SalaryNoticesManage) && $figures['nosi_due'] > 0 ? $this->nosiStep($figures['nosi_due']) : null,
        ]));

        $actions = array_values(array_filter([
            $can(PermissionName::DtrManage) ? ['label' => 'Upload biometric log', 'href' => '/admin/hr/dtr/import', 'icon' => 'download'] : null,
            $can(PermissionName::PayrollManage) ? ['label' => $figures['payroll'] ? 'Open payroll' : 'Draft payroll', 'href' => $figures['payroll'] ? "/admin/hr/payroll/runs/{$figures['payroll']['id']}" : '/admin/hr/payroll', 'icon' => 'database'] : null,
            $can(PermissionName::LeaveManage) && $leaveWaiting > 0 ? ['label' => "Review leave ({$leaveWaiting})", 'href' => '/admin/hr/leave/applications', 'icon' => 'calendar'] : null,
        ]));

        return [
            'month' => $today->format('F'),
            'waiting' => $queues['pdsVerifications'] + $queues['ldReviews'] + $leaveWaiting + $queues['dataCorrections']
                + $queues['payslipRequests'] + $queues['payrolls'] + $queues['salnReviews'],
            'steps' => $steps,
            'actions' => $actions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function figures(Carbon $today): array
    {
        $offices = collect(app(UploadMonitor::class)->forMonth($today));
        $last = $today->copy()->subMonthNoOverflow();
        $tardiness = app(TardinessDeductions::class)->forMonth($last)->reject(fn (array $row) => $row['card_managed']);
        $run = PayrollRun::query()->where('year', $today->year)->where('month', $today->month)->first();
        $saln = null;

        if ($today->month <= 4) {
            $annual = app(SalnObligations::class)->annual($today->year - 1);
            $saln = $annual->isEmpty() ? null : ['filed' => $annual->whereIn('state', ['filed', 'exempt'])->count(), 'total' => $annual->count()];
        }

        return [
            'offices_in' => $offices->where('status', 'received')->count(),
            'offices' => $offices->count(),
            'unverified' => app(DtrOverview::class)->counts($today, null, null)['unverified'],
            'to_charge' => $tardiness->filter(fn (array $row) => (! $row['deduction'] && $row['minutes'] > 0) || ($row['deduction']['dtr_changed'] ?? false))->count(),
            'payroll' => $run ? ['id' => $run->id, 'status' => $run->status, 'label' => $run->status->label(), 'release_on' => $run->release_on?->format('M j')] : null,
            'year_started' => LeaveLedgerEntry::query()->where('kind', 'grant')->where('period_year', $today->year)->exists(),
            'saln' => $saln,
            'nosi_due' => app(StepIncrementsDue::class)->due($today)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array{key: string, label: string, detail: string, state: string, href: string}
     */
    private function biometricStep(array $figures): array
    {
        $complete = $figures['offices'] > 0 && $figures['offices_in'] === $figures['offices'];

        return [
            'key' => 'biometric',
            'label' => 'Biometric logs',
            'detail' => "{$figures['offices_in']} of {$figures['offices']} offices in",
            'state' => $complete ? 'done' : ($figures['offices_in'] > 0 ? 'active' : 'todo'),
            'href' => '/admin/hr/dtr/import',
        ];
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array{key: string, label: string, detail: string, state: string, href: string}
     */
    private function entriesStep(array $figures): array
    {
        $count = $figures['unverified'];

        return [
            'key' => 'entries',
            'label' => 'DTR entries',
            'detail' => $count ? $count.' '.($count === 1 ? 'person has' : 'people have').' days to verify' : 'none waiting',
            'state' => $count ? 'active' : 'done',
            'href' => '/admin/hr/dtr?filter=unverified',
        ];
    }

    /**
     * Last month's tardiness while some is still to charge; otherwise when
     * this month's opens.
     *
     * @param  array<string, mixed>  $figures
     * @return array{key: string, label: string, detail: string, state: string, href: string}
     */
    private function tardinessStep(array $figures, Carbon $today): array
    {
        $last = $today->copy()->subMonthNoOverflow();

        return $figures['to_charge'] > 0
            ? ['key' => 'tardiness', 'label' => 'Tardiness sync', 'detail' => "{$figures['to_charge']} to charge for {$last->format('F')}", 'state' => 'active', 'href' => '/admin/hr/leave/tardiness?month='.$last->format('Y-m')]
            : ['key' => 'tardiness', 'label' => 'Tardiness sync', 'detail' => 'opens '.$today->copy()->addMonthNoOverflow()->startOfMonth()->format('M j'), 'state' => 'todo', 'href' => '/admin/hr/leave/tardiness'];
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array{key: string, label: string, detail: string, state: string, href: string}
     */
    private function payrollStep(array $figures): array
    {
        $run = $figures['payroll'];

        if (! $run) {
            return ['key' => 'payroll', 'label' => 'Payroll', 'detail' => 'not drafted yet', 'state' => 'todo', 'href' => '/admin/hr/payroll'];
        }

        $release = $run['release_on'] ? " · release {$run['release_on']}" : '';

        return [
            'key' => 'payroll',
            'label' => 'Payroll',
            'detail' => match ($run['status']) {
                PayrollRunStatus::Returned => 'back from Accounting: finalize'.$release,
                PayrollRunStatus::Finalized => 'finalized'.$release,
                default => mb_strtolower($run['label']).$release,
            },
            'state' => $run['status'] === PayrollRunStatus::Finalized ? 'done' : 'active',
            'href' => "/admin/hr/payroll/runs/{$run['id']}",
        ];
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array{key: string, label: string, detail: string, state: string, href: string}
     */
    private function yearStartStep(array $figures): array
    {
        return [
            'key' => 'year-start',
            'label' => 'Year start',
            'detail' => $figures['year_started'] ? 'SPL and Wellness Leave granted' : 'not run yet',
            'state' => $figures['year_started'] ? 'done' : 'active',
            'href' => '/admin/hr/leave',
        ];
    }

    /**
     * @param  array{filed: int, total: int}  $saln
     * @return array{key: string, label: string, detail: string, state: string, href: string}
     */
    private function salnStep(array $saln): array
    {
        return [
            'key' => 'saln',
            'label' => 'SALN filing',
            'detail' => "{$saln['filed']} of {$saln['total']} filed · due Apr 30",
            'state' => $saln['filed'] === $saln['total'] ? 'done' : 'active',
            'href' => '/admin/hr/saln',
        ];
    }

    /**
     * @return array{key: string, label: string, detail: string, state: string, href: string}
     */
    private function nosiStep(int $due): array
    {
        return [
            'key' => 'nosi',
            'label' => 'Step increments',
            'detail' => "{$due} due within 60 days",
            'state' => 'active',
            'href' => '/admin/hr/salary-notices?tab=nosi',
        ];
    }
}
