<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::RolesView->value);
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission(PermissionName::RolesView->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionName::RolesCreate->value);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermission(PermissionName::RolesUpdate->value);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermission(PermissionName::RolesDelete->value);
    }

    /**
     * Attach/detach permissions on a role — a distinct, more sensitive
     * ability than a plain "update" (renaming/describing a role).
     */
    public function managePermissions(User $user, Role $role): bool
    {
        return $user->hasPermission(PermissionName::PermissionsManage->value);
    }
}
