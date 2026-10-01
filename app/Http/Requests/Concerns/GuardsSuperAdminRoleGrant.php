<?php

namespace App\Http\Requests\Concerns;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;

/**
 * Shared privilege-escalation guard for any request that assigns roles to
 * a user: only an existing Super Administrator may grant the super-admin
 * role to anyone, including themselves. Used by both
 * `User\AssignRolesRequest` and `User\StoreUserRequest` so the rule is
 * defined once rather than duplicated per entry point.
 */
trait GuardsSuperAdminRoleGrant
{
    /**
     * @param  array<int, int>  $roleIds
     */
    private function isGrantingSuperAdminWithoutAuthority(User $actor, array $roleIds): bool
    {
        if ($roleIds === [] || $actor->hasRole(RoleName::SuperAdmin)) {
            return false;
        }

        return Role::query()
            ->whereKey($roleIds)
            ->where('name', RoleName::SuperAdmin->value)
            ->exists();
    }
}
