<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;

/**
 * Admin-3, Admin → Scheduled Tasks: seeing the tasks goes with seeing
 * the settings; pausing, resuming and running one needs its own
 * permission. Checked against ScheduledTaskPause::class.
 */
class ScheduledTaskPausePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::SettingsView->value);
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission(PermissionName::ScheduledTasksManage->value);
    }
}
