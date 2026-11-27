<?php

namespace App\Support\Activities;

use App\Models\Activity;
use App\Models\ActivitySeries;
use App\Models\AuditLog;
use Illuminate\Support\Collection;

/**
 * Calendar of Activities: PALD's "What changed", read from the audit log's
 * `activity.*` events of the last few days, newest first. Each row names
 * the office and links to the activity's month while the activity (or
 * series) still exists; a removed activity has no link.
 */
class ActivityChanges
{
    /** The most rows shown, so a busy month can't make the page heavy. */
    public const LIMIT = 200;

    /**
     * @return Collection<int, array{id: int, event: string, description: string, who: ?string, when: ?string, office: ?string, link: ?string}>
     */
    public static function since(int $days): Collection
    {
        $logs = AuditLog::query()
            ->where('event', 'like', 'activity.%')
            ->where('created_at', '>=', now()->subDays($days))
            ->with('actor:id,name')
            ->latest('created_at')->latest('id')
            ->limit(self::LIMIT)
            ->get();

        $activityType = (new Activity)->getMorphClass();
        $seriesType = (new ActivitySeries)->getMorphClass();

        $activities = Activity::withTrashed()
            ->with('office:id,name,short_name')
            ->whereKey($logs->where('target_type', $activityType)->pluck('target_id')->unique()->all())
            ->get()
            ->keyBy('id');
        $series = ActivitySeries::query()
            ->with('office:id,name,short_name')
            ->whereKey($logs->where('target_type', $seriesType)->pluck('target_id')->unique()->all())
            ->get()
            ->keyBy('id');

        return $logs->map(function (AuditLog $log) use ($activityType, $seriesType, $activities, $series): array {
            $office = null;
            $link = null;

            if ($log->target_type === $activityType && ($activity = $activities->get($log->target_id)) !== null) {
                $office = $activity->office?->short_name ?: $activity->office?->name;
                $link = $activity->trashed() ? null : self::monthLink($activity->year, $activity->month);
            } elseif ($log->target_type === $seriesType && ($repeating = $series->get($log->target_id)) !== null) {
                $office = $repeating->office?->short_name ?: $repeating->office?->name;
                $link = self::monthLink($repeating->starts_on->year, $repeating->starts_on->month);
            }

            return [
                'id' => $log->id,
                'event' => str($log->event->action())->replace('_', ' ')->headline()->value(),
                'description' => $log->description,
                'who' => $log->actor?->name ?? $log->actor_label,
                'when' => $log->created_at?->toIso8601String(),
                'office' => $office,
                'link' => $link,
            ];
        })->values();
    }

    private static function monthLink(int $year, int $month): string
    {
        return '/activities?month='.sprintf('%04d-%02d', $year, $month);
    }
}
