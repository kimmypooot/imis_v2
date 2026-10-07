<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;

/**
 * The Executive Dashboard: `viewAny` is seeing it (and its printed
 * report); `manage` is its settings — summary feeds, targets, and
 * collecting on demand. Checked against ExecutiveSnapshot::class.
 */
class ExecutiveSnapshotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::ExecutiveView->value);
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission(PermissionName::ExecutiveManage->value);
    }
}
