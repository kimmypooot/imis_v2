<?php

namespace App\Http\Requests\Committee\Concerns;

use App\Enums\Status;
use App\Models\CommitteeMember;

/**
 * Shared invalid-membership guard: a user may have more than one
 * membership row for the same committee over time (distinct terms), but
 * never more than one *active* row at once. Used wherever a request
 * could leave a membership in `active` status — adding a member,
 * editing one, or reactivating one.
 */
trait GuardsDuplicateActiveMembership
{
    private function hasOtherActiveMembership(int $committeeId, int $userId, ?int $excludingMemberId = null): bool
    {
        return CommitteeMember::query()
            ->where('committee_id', $committeeId)
            ->where('user_id', $userId)
            ->where('status', Status::Active->value)
            ->when($excludingMemberId, fn ($query, $id) => $query->whereKeyNot($id))
            ->exists();
    }
}
