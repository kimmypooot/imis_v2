<?php

namespace App\Support\Ipcrs;

use App\Support\Committees\CurrentMembers;
use Illuminate\Support\Collection;

/**
 * Who is on the Performance Management Team: the active members, today, of the committee whose
 * short name is `ipcrs.pmt_committee` (the PMT of CSC MC No. 06, s. 2012). The super-admin manages
 * the team at admin/committees; IPCRS only reads it, and lets its members see the ratings.
 */
class PmtCommittee
{
    /**
     * User ids of the people who are members today.
     *
     * @return Collection<int, int>
     */
    public static function memberIds(): Collection
    {
        return CurrentMembers::idsOf((string) config('ipcrs.pmt_committee'));
    }

    /**
     * The position each member holds in the team, by user id (empty text when none was entered).
     *
     * @return Collection<int, string>
     */
    public static function positions(): Collection
    {
        return CurrentMembers::positionsOf((string) config('ipcrs.pmt_committee'));
    }

    public static function includes(int $userId): bool
    {
        return self::memberIds()->contains($userId);
    }
}
