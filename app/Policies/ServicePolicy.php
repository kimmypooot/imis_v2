<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::ServicesView->value);
    }

    public function view(User $user, Service $service): bool
    {
        return $user->hasPermission(PermissionName::ServicesView->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionName::ServicesCreate->value);
    }

    public function update(User $user, Service $service): bool
    {
        return $user->hasPermission(PermissionName::ServicesUpdate->value);
    }

    /**
     * Activating/deactivating a service — kept distinct from "update" the
     * same way OrganizationalUnit's archive ability is, and gated by its
     * own permission per the phase spec.
     */
    public function archive(User $user, Service $service): bool
    {
        return $user->hasPermission(PermissionName::ServicesArchive->value);
    }
}
