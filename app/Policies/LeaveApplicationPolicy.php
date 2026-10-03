<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\LeaveApplication;
use App\Models\LeaveDesignation;
use App\Models\User;

/**
 * HR-6b: who can see a leave application (and its form and attachment) —
 * the employee; anyone on its approval chain (named, or who acted); while
 * it's under review, HR certifiers, the Head and an officer-in-charge on
 * duty; and HR with `leave.view` or `leave.manage`. Acting on it is
 * LeaveApplications::authority().
 */
class LeaveApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([PermissionName::LeaveView->value, PermissionName::LeaveManage->value]);
    }

    public function view(User $user, LeaveApplication $application): bool
    {
        if ($this->owns($user, $application) || $this->viewAny($user)) {
            return true;
        }

        if ($application->steps()->where(fn ($query) => $query->where('assigned_to', $user->id)->orWhere('acted_by', $user->id))->exists()) {
            return true;
        }

        return $application->status->isOpen() && (
            $user->hasAnyPermission([PermissionName::LeaveCertify->value, PermissionName::LeaveApprove->value])
            || LeaveDesignation::query()->activeOn()->where('designee_id', $user->id)->exists()
        );
    }

    /** Cancelling, or correcting and resubmitting a returned one. */
    public function update(User $user, LeaveApplication $application): bool
    {
        return $this->owns($user, $application) && $application->status->isOpen();
    }

    private function owns(User $user, LeaveApplication $application): bool
    {
        return $application->employee()->value('user_id') === $user->id;
    }
}
