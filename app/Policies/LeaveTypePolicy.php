<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;

/**
 * HR-6 leave administration: leave types, holidays, and the credits
 * overview with its accrual runs. `leave.manage` also reads. Someone's own
 * card is EmployeePolicy::viewLeave / manageLeave.
 */
class LeaveTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([PermissionName::LeaveView->value, PermissionName::LeaveManage->value]);
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission(PermissionName::LeaveManage->value);
    }
}
