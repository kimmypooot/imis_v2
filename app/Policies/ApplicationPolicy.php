<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::ApplicationsView->value);
    }

    public function view(User $user, Application $application): bool
    {
        return $user->hasPermission(PermissionName::ApplicationsView->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionName::ApplicationsCreate->value);
    }

    public function update(User $user, Application $application): bool
    {
        return $user->hasPermission(PermissionName::ApplicationsUpdate->value);
    }

    /**
     * Activating/deactivating a system directory entry — kept distinct from
     * "update" the same way Service's archive ability is, and gated by its
     * own permission per the phase spec.
     */
    public function archive(User $user, Application $application): bool
    {
        return $user->hasPermission(PermissionName::ApplicationsArchive->value);
    }

    /**
     * Starting/ending maintenance for this system (its own permission, like
     * archive). The same permission lets a user launch it meanwhile — see
     * Application::maintenanceBypassedBy().
     */
    public function maintenance(User $user, Application $application): bool
    {
        return $user->hasPermission(PermissionName::ApplicationsMaintenance->value);
    }

    /**
     * Defining the roles that exist inside this system is configuration of
     * the system itself, so it rides on "update".
     */
    public function manageRoles(User $user, Application $application): bool
    {
        return $this->update($user, $application);
    }

    /**
     * Granting, changing, or revoking users' roles in this system. Which
     * *users* the actor may change is checked separately by
     * UserPolicy::manageSystemAccess.
     */
    public function manageAccess(User $user, Application $application): bool
    {
        return $user->hasPermission(PermissionName::ApplicationsManageAccess->value);
    }
}
