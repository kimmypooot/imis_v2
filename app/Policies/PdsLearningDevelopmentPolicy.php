<?php

namespace App\Policies;

use App\Enums\LdApprovalStatus;
use App\Enums\PermissionName;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\User;

/**
 * HR-3 Learning & Development records. Registered in AppServiceProvider
 * (the model sits in App\Models\Pds).
 */
class PdsLearningDevelopmentPolicy
{
    /**
     * HR-3b: HRD's overview of everyone's logs.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([PermissionName::LdView->value, PermissionName::LdManage->value]);
    }

    /**
     * HR-3b: the review queue.
     */
    public function viewQueue(User $user): bool
    {
        return $user->hasPermission(PermissionName::LdApprove->value);
    }

    /**
     * HR-3b bulk add. Each person must also pass EmployeePolicy::manageLd
     * (so never yourself).
     */
    public function createMany(User $user): bool
    {
        return $user->hasPermission(PermissionName::LdManage->value);
    }

    /**
     * Your own records, or anyone's for HRD — including reviewers, who
     * check the certificate before approving.
     */
    public function view(User $user, PdsLearningDevelopment $record): bool
    {
        return $this->ownedBy($user, $record) || $user->hasAnyPermission([
            PermissionName::LdView->value,
            PermissionName::LdManage->value,
            PermissionName::LdApprove->value,
        ]);
    }

    /**
     * The owner edits their entries until HRD approves them (a rejected
     * one can be fixed and resubmitted). Approved records are locked.
     */
    public function update(User $user, PdsLearningDevelopment $record): bool
    {
        return $this->ownedBy($user, $record) && $record->isEditableByOwner();
    }

    public function delete(User $user, PdsLearningDevelopment $record): bool
    {
        return $this->update($user, $record);
    }

    /**
     * HR-3b: HRD approves or rejects an employee's pending entry — never
     * their own. A rejected one can be reviewed again (e.g. approved after
     * all, once the employee sends the certificate by other means).
     */
    public function review(User $user, PdsLearningDevelopment $record): bool
    {
        return $record->approval_status !== LdApprovalStatus::Approved
            && ! $this->ownedBy($user, $record)
            && $user->hasPermission(PermissionName::LdApprove->value);
    }

    /**
     * HR-3b: HRD edits or removes anyone's record, approved ones included —
     * except their own (separation of duties).
     */
    public function manage(User $user, PdsLearningDevelopment $record): bool
    {
        return ! $this->ownedBy($user, $record) && $user->hasPermission(PermissionName::LdManage->value);
    }

    private function ownedBy(User $user, PdsLearningDevelopment $record): bool
    {
        return $record->employee()->value('user_id') === $user->id;
    }
}
