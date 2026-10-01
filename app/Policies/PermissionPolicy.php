<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\User;

class PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::PermissionsView->value);
    }

    public function view(User $user, Permission $permission): bool
    {
        return $user->hasPermission(PermissionName::PermissionsView->value);
    }
}
