<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Activity;
use App\Models\User;

/**
 * Calendar of Activities. Every staff account views; an office's poster
 * adds and keeps their own office's activities ("own office" is exactly
 * the account's unit, no parent or child units); PALD (`activities.manage`)
 * keeps any office's, and alone returns or removes them.
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::ActivitiesView->value);
    }

    public function view(User $user, Activity $activity): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission([PermissionName::ActivitiesPost->value, PermissionName::ActivitiesManage->value]);
    }

    /**
     * Whether the person may post for this office: PALD for any, a poster
     * for exactly their own unit.
     */
    public function postFor(User $user, int $officeId): bool
    {
        return $this->manage($user)
            || ($user->hasPermission(PermissionName::ActivitiesPost->value)
                && $user->organizational_unit_id !== null
                && $officeId === (int) $user->organizational_unit_id);
    }

    public function update(User $user, Activity $activity): bool
    {
        return $this->postFor($user, (int) $activity->organizational_unit_id);
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission(PermissionName::ActivitiesManage->value);
    }
}
