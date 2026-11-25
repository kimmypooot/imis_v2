<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\SalnStatus;
use App\Models\Saln;
use App\Models\User;

/**
 * SALNs. The employee works on their own while their account is active;
 * HRD (`saln.manage`) on anyone's, including separated staff and staff
 * without an account; the SALN Committee (`saln.review`) sees them once HRD
 * has received the hard copy. Nobody decides their own. (Super
 * Administrators pass via Gate::before, but SalnFilings still refuses a
 * decision on one's own SALN.)
 */
class SalnPolicy
{
    /**
     * The review queue: the SALN Committee and HRD.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([PermissionName::SalnReview->value, PermissionName::SalnManage->value]);
    }

    /**
     * Compliance, exemptions and starting a SALN for someone: HRD.
     */
    public function manage(User $user): bool
    {
        return $user->hasPermission(PermissionName::SalnManage->value);
    }

    public function view(User $user, Saln $saln): bool
    {
        return $this->ownedBy($user, $saln)
            || $user->hasPermission(PermissionName::SalnManage->value)
            || ($user->hasPermission(PermissionName::SalnReview->value) && $this->reachedHrd($saln));
    }

    /**
     * Encode, save and print.
     */
    public function update(User $user, Saln $saln): bool
    {
        return ($this->ownedBy($user, $saln) && $user->isActive())
            || $user->hasPermission(PermissionName::SalnManage->value);
    }

    public function receive(User $user, Saln $saln): bool
    {
        return $user->hasPermission(PermissionName::SalnManage->value);
    }

    /**
     * Clear or return: the Committee, or HRD overriding it — never one's own.
     */
    public function decide(User $user, Saln $saln): bool
    {
        return ! $this->ownedBy($user, $saln)
            && $user->hasAnyPermission([PermissionName::SalnReview->value, PermissionName::SalnManage->value]);
    }

    /**
     * Upload the signed, sworn scan: HRD, or the employee once cleared.
     */
    public function file(User $user, Saln $saln): bool
    {
        return $user->hasPermission(PermissionName::SalnManage->value)
            || ($this->ownedBy($user, $saln) && $saln->status === SalnStatus::Cleared);
    }

    private function ownedBy(User $user, Saln $saln): bool
    {
        return $saln->employee()->value('user_id') === $user->id;
    }

    private function reachedHrd(Saln $saln): bool
    {
        return in_array($saln->status, [SalnStatus::Received, SalnStatus::Returned, SalnStatus::Cleared, SalnStatus::Filed], true);
    }
}
