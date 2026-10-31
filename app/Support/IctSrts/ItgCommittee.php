<?php

namespace App\Support\IctSrts;

use App\Support\Committees\CurrentMembers;
use Illuminate\Support\Collection;

/**
 * Who is in the ICT Group: the active members of the Information Technology
 * Group committee (the committee whose short name is `ictsrts.itg_committee`),
 * on today's date. The super-admin manages the group at admin/committees; ICT
 * SRTS only reads it.
 */
class ItgCommittee
{
    /**
     * User ids of the people who are members today.
     *
     * @return Collection<int, int>
     */
    public static function memberIds(): Collection
    {
        return CurrentMembers::idsOf((string) config('ictsrts.itg_committee'));
    }

    public static function includes(int $userId): bool
    {
        return self::memberIds()->contains($userId);
    }
}
