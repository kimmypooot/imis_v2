<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Announcement;
use App\Models\User;

/**
 * Admin-side abilities only. Portal visibility is not a policy question —
 * it is decided by Announcement::scopePublished()/isPublished() the same
 * way for every authenticated user.
 */
class AnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::AnnouncementsView->value);
    }

    /**
     * Includes the admin preview of draft/scheduled/expired/archived entries.
     */
    public function view(User $user, Announcement $announcement): bool
    {
        return $user->hasPermission(PermissionName::AnnouncementsView->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionName::AnnouncementsCreate->value);
    }

    /**
     * Editing content, and featuring/unfeaturing.
     */
    public function update(User $user, Announcement $announcement): bool
    {
        return $user->hasPermission(PermissionName::AnnouncementsUpdate->value);
    }

    /**
     * Publishing and unpublishing — separate from `update` so an office
     * can let someone draft announcements without letting them put content
     * in front of every portal user.
     */
    public function publish(User $user, Announcement $announcement): bool
    {
        return $user->hasPermission(PermissionName::AnnouncementsPublish->value);
    }

    /**
     * Archiving, and restoring an archived announcement to draft.
     */
    public function archive(User $user, Announcement $announcement): bool
    {
        return $user->hasPermission(PermissionName::AnnouncementsArchive->value);
    }
}
