<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\ServiceCategory;
use App\Models\User;

/**
 * Categories are part of managing the services directory, so they use the
 * services permissions rather than a set of their own.
 */
class ServiceCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::ServicesView->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionName::ServicesCreate->value);
    }

    public function update(User $user, ServiceCategory $category): bool
    {
        return $user->hasPermission(PermissionName::ServicesUpdate->value);
    }

    public function archive(User $user, ServiceCategory $category): bool
    {
        return $user->hasPermission(PermissionName::ServicesArchive->value);
    }
}
