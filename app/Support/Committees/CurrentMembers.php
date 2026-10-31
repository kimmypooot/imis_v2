<?php

namespace App\Support\Committees;

use App\Enums\Status;
use App\Models\Committee;
use Illuminate\Support\Collection;

/**
 * Who is a member of a committee today: the active members of the active committee with
 * that short name, whose membership dates (if any) include today. Systems that read a
 * committee (ICT SRTS reads the ITG, IPCRS the PMT) never keep their own copy of it.
 */
class CurrentMembers
{
    /**
     * User ids of the people who are members today.
     *
     * @return Collection<int, int>
     */
    public static function idsOf(string $shortName): Collection
    {
        return self::positionsOf($shortName)->keys();
    }

    /**
     * The position each current member holds in the committee as it is written there ("Chairperson",
     * "Secretariat, Alternate"), by user id; empty when none was entered. Someone with more than one
     * current membership has their positions joined.
     *
     * @return Collection<int, string>
     */
    public static function positionsOf(string $shortName): Collection
    {
        $committee = $shortName === '' ? null : Committee::query()->where('short_name', $shortName)->where('status', Status::Active)->first();

        if ($committee === null) {
            return collect();
        }

        $today = now()->toDateString();

        return $committee->activeMembers()
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $today))
            ->orderBy('id')->get(['user_id', 'position'])
            ->groupBy('user_id')
            ->map(fn (Collection $rows) => $rows->pluck('position')->map(fn ($p) => trim((string) $p))->filter()->unique()->implode(', '));
    }
}
