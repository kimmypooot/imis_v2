<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Committee;
use App\Models\User;

class CommitteePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::CommitteesView->value);
    }

    public function view(User $user, Committee $committee): bool
    {
        return $user->hasPermission(PermissionName::CommitteesView->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionName::CommitteesCreate->value);
    }

    public function update(User $user, Committee $committee): bool
    {
        return $user->hasPermission(PermissionName::CommitteesUpdate->value);
    }

    /**
     * Activating/deactivating the committee itself.
     */
    public function archive(User $user, Committee $committee): bool
    {
        return $user->hasPermission(PermissionName::CommitteesArchive->value);
    }

    /**
     * Adding, editing, or ending a membership — kept under the same
     * `committees.update` permission as editing the committee itself
     * (no separate membership permission), the same way Role's
     * permission-assignment and User's role-assignment reuse their
     * parent resource's `.update` permission rather than minting a new
     * one.
     */
    public function manageMembers(User $user, Committee $committee): bool
    {
        return $user->hasPermission(PermissionName::CommitteesUpdate->value);
    }
}
