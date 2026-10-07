<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::UsersView->value);
    }

    public function view(User $user, User $target): bool
    {
        return $user->hasPermission(PermissionName::UsersView->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionName::UsersCreate->value);
    }

    public function update(User $user, User $target): bool
    {
        if (! $user->hasPermission(PermissionName::UsersUpdate->value)) {
            return false;
        }

        return $this->mayModifyProtectedTarget($user, $target);
    }

    public function assignRoles(User $user, User $target): bool
    {
        if (! $user->hasPermission(PermissionName::UsersUpdate->value)) {
            return false;
        }

        return $this->mayModifyProtectedTarget($user, $target);
    }

    /**
     * Activating/deactivating an account — the `users.delete` permission
     * is this app's stable identifier for that action; there is no hard
     * delete for user accounts, consistent with every other directory
     * module in this codebase.
     */
    public function updateStatus(User $user, User $target): bool
    {
        if (! $user->hasPermission(PermissionName::UsersDelete->value)) {
            return false;
        }

        return $this->mayModifyProtectedTarget($user, $target);
    }

    /**
     * Setting someone's password directly is a Super Administrator
     * override (they pass through Gate::before); everyone else sends a
     * reset link instead. SetUserPasswordRequest also refuses it on
     * one's own account, which goes through Change Password.
     */
    public function setPassword(User $user, User $target): bool
    {
        return false;
    }

    /**
     * Granting/changing/revoking this user's roles inside external
     * systems. Besides the protected-target rule, a non-super-admin may
     * never change their *own* system access — holding
     * `applications.manage_access` must not let someone make themselves
     * an administrator of another system.
     */
    public function manageSystemAccess(User $user, User $target): bool
    {
        if (! $user->hasPermission(PermissionName::ApplicationsManageAccess->value)) {
            return false;
        }

        return ! $user->is($target) && $this->mayModifyProtectedTarget($user, $target);
    }

    /**
     * A Super Administrator target is "protected": only another Super
     * Administrator may edit their account, change their roles, or
     * activate/deactivate them. An actual Super Administrator actor
     * never reaches this method at all (Gate::before short-circuits
     * every ability check for them), so this only ever runs for a
     * non-super-admin actor — exactly who it's meant to restrict.
     */
    private function mayModifyProtectedTarget(User $user, User $target): bool
    {
        return ! $target->hasRole(RoleName::SuperAdmin);
    }
}
