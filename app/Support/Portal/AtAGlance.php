<?php

namespace App\Support\Portal;

use App\Enums\LeaveApplicationStatus;
use App\Models\LeaveApplication;
use App\Models\User;
use App\Support\HR\Leave\LeaveApplications;
use App\Support\HR\Leave\LeaveCredits;

/**
 * The personal figures shown in the dashboard's "At a glance" row: unread
 * notifications for everyone; for people with an employee record, their
 * vacation and sick leave balances and the applications in progress; and,
 * for anyone with leave applications waiting on them, how many.
 */
class AtAGlance
{
    public function __construct(
        private readonly LeaveCredits $credits,
        private readonly LeaveApplications $applications,
    ) {}

    /**
     * @return array<int, array{key: string, label: string, value: string, hint: string, href: string, icon: string, tone: string}>
     */
    public function for(User $user): array
    {
        $unread = $user->unreadNotifications()->count();
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

        $tiles[] = [
            'key' => 'notifications',
            'label' => 'Notifications',
            'value' => (string) $unread,
            'hint' => $unread ? 'unread' : 'all read',
            'href' => '/notifications',
            'icon' => 'bell',
            'tone' => $unread ? 'info' : 'success',
        ];

        return $tiles;
    }
}
