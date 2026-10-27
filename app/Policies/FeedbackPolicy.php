<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Feedback;
use App\Models\User;

/**
 * Sending feedback needs no permission (every signed-in user may). Reading
 * everyone's is `feedback.view`; replying, taking notes and changing the
 * status is `feedback.manage`. Super Administrators pass via Gate::before.
 */
class FeedbackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::FeedbackView->value);
    }

    public function view(User $user, Feedback $feedback): bool
    {
        return $user->hasPermission(PermissionName::FeedbackView->value);
    }

    public function manage(User $user, Feedback $feedback): bool
    {
        return $user->hasPermission(PermissionName::FeedbackManage->value);
    }
}
