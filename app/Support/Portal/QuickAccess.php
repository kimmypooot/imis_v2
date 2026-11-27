<?php

namespace App\Support\Portal;

use App\Models\Activity;
use App\Models\User;

/**
 * The welcome banner's round shortcuts: the same five for everyone, each
 * left out when the user can't open that page. Filing leave and payslips
 * need an employee record (their controllers refuse without one); My DTR
 * and My PDS open without one but only to say HR hasn't set it up, so
 * they need one too. The calendar needs `activities.view` (ActivityPolicy).
 */
class QuickAccess
{
    /**
     * @return array<int, array{key: string, label: string, href: string, icon: string}>
     */
    public function for(User $user): array
    {
        $hasRecord = $user->employee !== null;

        return array_values(array_filter([
            $hasRecord ? ['key' => 'leave', 'label' => 'File Leave', 'href' => '/my/leave/apply', 'icon' => 'pencil'] : null,
            $hasRecord ? ['key' => 'dtr', 'label' => 'My DTR', 'href' => '/my/dtr', 'icon' => 'clock'] : null,
            $hasRecord ? ['key' => 'payslips', 'label' => 'My Payslip', 'href' => '/my/payslips', 'icon' => 'documentText'] : null,
            $hasRecord ? ['key' => 'pds', 'label' => 'My PDS', 'href' => '/my/pds', 'icon' => 'user'] : null,
            $user->can('viewAny', Activity::class) ? ['key' => 'activities', 'label' => 'Calendar', 'href' => '/activities', 'icon' => 'calendar'] : null,
        ]));
    }
}
