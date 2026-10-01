<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Changelog;
use App\Models\User;

class ChangelogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::ChangelogsView->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionName::ChangelogsManage->value);
    }

    public function update(User $user, Changelog $changelog): bool
    {
        return $user->hasPermission(PermissionName::ChangelogsManage->value);
    }

    /**
     * Publishing/unpublishing — separate from writing, since publishing is
     * what pops a release up on everyone's dashboard.
     */
    public function publish(User $user, Changelog $changelog): bool
    {
        return $user->hasPermission(PermissionName::ChangelogsPublish->value);
    }
}
