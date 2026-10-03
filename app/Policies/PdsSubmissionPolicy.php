<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Pds\PdsSubmission;
use App\Models\User;

/**
 * HR-2b signed PDS submissions. Registered in AppServiceProvider (the
 * model sits in App\Models\Pds, outside auto-discovery's reach).
 */
class PdsSubmissionPolicy
{
    /**
     * The verification queue.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::PdsVerify->value);
    }

    /**
     * A signed copy: your own, or anyone's for HR who can read PDSs.
     */
    public function view(User $user, PdsSubmission $submission): bool
    {
        return $this->ownedBy($user, $submission)
            || $user->hasAnyPermission([PermissionName::PdsView->value, PermissionName::PdsVerify->value]);
    }

    /**
     * Verify or return: HR only, never your own PDS, and only while it
     * still awaits review. (Super Administrators pass via Gate::before,
     * but the controller still refuses a non-pending submission.)
     */
    public function review(User $user, PdsSubmission $submission): bool
    {
        return $submission->isPending()
            && ! $this->ownedBy($user, $submission)
            && $user->hasPermission(PermissionName::PdsVerify->value);
    }

    private function ownedBy(User $user, PdsSubmission $submission): bool
    {
        return $submission->employee()->value('user_id') === $user->id;
    }
}
