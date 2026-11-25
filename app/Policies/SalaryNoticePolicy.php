<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\SalaryNotice;
use App\Models\User;

/**
 * Notices of Salary Adjustment and of Step Increment. HR with
 * `salary_notices.manage` generates, prints and files them, never their
 * own (separation of duties). Filing also needs the right to add to the
 * employee's 201 File. Super Administrators pass through Gate::before.
 */
class SalaryNoticePolicy
{
    public function manage(User $user): bool
    {
        return $user->hasPermission(PermissionName::SalaryNoticesManage->value);
    }

    /** Printing a notice. */
    public function view(User $user, SalaryNotice $notice): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, SalaryNotice $notice): bool
    {
        return $this->manage($user) && ! $this->own($user, $notice);
    }

    public function file(User $user, SalaryNotice $notice): bool
    {
        return $this->delete($user, $notice) && $user->can('manageDocuments', $notice->employee);
    }

    private function own(User $user, SalaryNotice $notice): bool
    {
        return $notice->employee?->user_id === $user->id;
    }
}
