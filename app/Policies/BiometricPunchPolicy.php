<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;

/**
 * DTR administration: biometric logs, device IDs and their mapping to
 * employees. `dtr.manage` also reads.
 */
class BiometricPunchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([PermissionName::DtrView->value, PermissionName::DtrManage->value]);
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission(PermissionName::DtrManage->value);
    }
}
