<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\OrganizationalUnit;
use App\Models\User;

class OrganizationalUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::OrganizationalUnitsView->value);
    }

    public function view(User $user, OrganizationalUnit $organizationalUnit): bool
    {
        return $user->hasPermission(PermissionName::OrganizationalUnitsView->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionName::OrganizationalUnitsCreate->value);
    }

    public function update(User $user, OrganizationalUnit $organizationalUnit): bool
    {
        return $user->hasPermission(PermissionName::OrganizationalUnitsUpdate->value);
    }

    /**
     * Activating/deactivating a unit — kept distinct from "update" (editing
     * its details) the same way Role's managePermissions is distinct from
     * update, and gated by its own permission per the phase spec.
     */
    public function archive(User $user, OrganizationalUnit $organizationalUnit): bool
    {
        return $user->hasPermission(PermissionName::OrganizationalUnitsArchive->value);
    }
}
