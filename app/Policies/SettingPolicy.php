<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Setting;
use App\Models\User;

/**
 * Who may see and change settings. Whether a *particular* setting may be
 * changed at all (protected settings) is not a policy question — Super
 * Administrators pass every Gate check via Gate::before, so that rule is
 * enforced in UpdateSettingsRequest for everyone instead.
 */
class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::SettingsView->value);
    }

    public function update(User $user): bool
    {
        return $user->hasPermission(PermissionName::SettingsUpdate->value);
    }
}
