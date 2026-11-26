<?php

namespace App\Support\Portal;

use App\Enums\LeaveApplicationStatus;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\User;
use App\Support\HR\Dtr\DtrSheets;
use App\Support\HR\Leave\LeaveApplications;
use App\Support\HR\Leave\LeaveCredits;

/**
 * The personal figures shown in the dashboard's "At a glance" row: for
 * people with an employee record, their vacation and sick leave balances,
 * the applications in progress and this month's DTR (late and undertime so
 * far, and whether last month's sheet still needs signing); and, for anyone
 * with leave applications waiting on them, how many. Unread notifications
 * are left to the bell in the header.
 */
class AtAGlance
{
    public function __construct(
        private readonly LeaveCredits $credits,
        private readonly LeaveApplications $applications,
        private readonly DtrSheets $sheets,
    ) {}

    /**
     * @return array<int, array{key: string, label: string, value: string, hint: string, href: string, icon: string, tone: string}>
     */
    public function for(User $user): array
    {
        $tiles = [];
        $employee = $user->employee;

        if ($employee) {
            $balances = $this->credits->balances($employee)->keyBy('code');

            foreach (['VL' => 'Vacation leave', 'SL' => 'Sick leave'] as $code => $label) {
                if ($balances->has($code)) {
                    $tiles[] = [
                        'key' => strtolower($code),
                        'label' => $label,
                        'value' => number_format((float) $balances[$code]['balance'], 3),
                        'hint' => 'days left',
                        'href' => '/my/leave',
                        'icon' => 'calendar',
                        'tone' => 'info',
                    ];
                }
            }

            $inProgress = LeaveApplication::query()
                ->where('employee_id', $employee->id)
                ->whereIn('status', [LeaveApplicationStatus::ForReview, LeaveApplicationStatus::Returned])
                ->get(['id', 'status']);

            $returned = $inProgress->where('status', LeaveApplicationStatus::Returned)->count();

            $tiles[] = [
                'key' => 'applications',
                'label' => 'Leave in progress',
                'value' => (string) $inProgress->count(),
                'hint' => $returned ? "{$returned} needs correction" : 'applications',
                'href' => '/my/leave/applications',
                'icon' => 'clipboard',
                'tone' => $returned ? 'danger' : 'warning',
            ];

            $tiles[] = $this->dtr($employee);
        }

        $waiting = $this->applications->queueFor($user)->count();

        if ($waiting) {
            $tiles[] = [
                'key' => 'approvals',
                'label' => 'Waiting for you',
                'value' => (string) $waiting,
                'hint' => $waiting === 1 ? 'leave application' : 'leave applications',
                'href' => '/leave/approvals',
                'icon' => 'checkCircle',
                'tone' => 'warning',
            ];
        }

        return $tiles;
    }

    /**
     * This month's late and undertime so far (h:mm), and a nudge when last
     * month's sheet has hours on it but is not, or no longer validly, signed.
     *
     * @return array{key: string, label: string, value: string, hint: string, href: string, icon: string, tone: string}
     */
    private function dtr(Employee $employee): array
    {
        $month = today();
        $minutes = collect($this->sheets->forMonth($employee, $month)['sheets'])
            ->sum(fn (array $sheet): int => (int) $sheet['totals']['late_minutes'] + (int) $sheet['totals']['undertime_minutes']);

        $last = $month->copy()->subMonthNoOverflow();
        $toSign = collect($this->sheets->forMonth($employee, $last)['sheets'])
            ->contains(fn (array $sheet): bool => (int) $sheet['totals']['minutes'] > 0 && ($sheet['signature']['status'] ?? null) !== 'valid');

        return [
            'key' => 'dtr',
            'label' => 'Late & undertime',
            'value' => intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT),
            'hint' => $toSign ? 'sign '.$last->format('F') : $month->format('F'),
            'href' => '/my/dtr'.($toSign ? '?month='.$last->format('Y-m') : ''),
            'icon' => 'clock',
            'tone' => $toSign ? 'warning' : ($minutes ? 'info' : 'success'),
        ];
    }
}
