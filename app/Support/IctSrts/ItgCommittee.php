<?php

namespace App\Support\IctSrts;

use App\Enums\Status;
use App\Models\Committee;
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
        $short = (string) config('ictsrts.itg_committee');
        $committee = $short === '' ? null : Committee::query()->where('short_name', $short)->where('status', Status::Active)->first();

        if ($committee === null) {
            return collect();
        }

        $today = now()->toDateString();

        return $committee->activeMembers()
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $today))
            ->pluck('user_id');
    }

    public static function includes(int $userId): bool
    {
        return self::memberIds()->contains($userId);
    }
}
