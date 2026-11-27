<?php

namespace App\Support\Activities;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\OrganizationalUnit;
use Illuminate\Support\Collection;

/**
 * Calendar of Activities: PALD's plan-versus-actual counts for a year, per
 * office (in the regional office's order). `planned` is every activity that
 * was part of the plan (not flagged unplanned), whatever became of it;
 * `moved` is those with a date change; `open` is neither done nor
 * cancelled. Removed (soft-deleted) activities are not counted.
 */
class PlanVersusActual
{
    /**
     * @return Collection<int, array{office: string, planned: int, done: int, cancelled: int, moved: int, unplanned: int, open: int}>
     */
    public static function for(int $year): Collection
    {
        $done = ActivityStatus::Done->value;
        $cancelled = ActivityStatus::Cancelled->value;

        $counts = Activity::query()
            ->where('year', $year)
            ->groupBy('organizational_unit_id')
            ->select('organizational_unit_id')
            ->selectRaw('SUM(CASE WHEN unplanned = 0 THEN 1 ELSE 0 END) as planned')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as done', [$done])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled', [$cancelled])
            ->selectRaw('SUM(CASE WHEN EXISTS (SELECT 1 FROM activity_moves WHERE activity_moves.activity_id = activities.id) THEN 1 ELSE 0 END) as moved')
            ->selectRaw('SUM(CASE WHEN unplanned = 1 THEN 1 ELSE 0 END) as unplanned')
            ->selectRaw('SUM(CASE WHEN status NOT IN (?, ?) THEN 1 ELSE 0 END) as open', [$done, $cancelled])
            ->toBase()
            ->get()
            ->keyBy('organizational_unit_id');

        if ($counts->isEmpty()) {
            return collect();
        }

        return OrganizationalUnit::query()
            ->whereKey($counts->keys()->filter()->all())
            ->inOrder()
            ->get(['id', 'name', 'short_name'])
            ->map(fn (OrganizationalUnit $office): array => [
                'office' => $office->short_name ?: $office->name,
                ...self::numbers($counts[$office->id]),
            ])
            ->values();
    }

    /**
     * @return array{planned: int, done: int, cancelled: int, moved: int, unplanned: int, open: int}
     */
    private static function numbers(object $row): array
    {
        return [
            'planned' => (int) $row->planned,
            'done' => (int) $row->done,
            'cancelled' => (int) $row->cancelled,
            'moved' => (int) $row->moved,
            'unplanned' => (int) $row->unplanned,
            'open' => (int) $row->open,
        ];
    }
}
