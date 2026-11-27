<?php

namespace App\Support\Activities;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Holiday;
use App\Models\User;
use App\Support\HR\Leave\HolidayCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The dashboard's Calendar of Activities section: what's coming up in the
 * region over the next 7 days (today and the six after, in the app's
 * timezone), with day-off holidays inline, and a month for the mini
 * calendar (the days that have activities, and its day-off holidays).
 * Cancelled activities are listed, struck through, but not counted.
 */
class UpcomingActivities
{
    /** How many entries the list shows. */
    public const LIMIT = 6;

    /** Today and the six days after. */
    public const DAYS = 7;

    public function __construct(private readonly HolidayCalendar $holidays) {}

    /**
     * @param  string|null  $month  The mini calendar's month (`YYYY-MM`); this month when missing or invalid.
     * @return array{today: string, items: array<int, array<string, mixed>>, month: array{month: string, current: bool, days: array<int, string>, holidays: array<int, array{date: string, name: string}>}, count: int}
     */
    public function for(User $user, ?Carbon $now = null, ?string $month = null): array
    {
        $today = $this->today($now);
        $last = $today->copy()->addDays(self::DAYS - 1);

        $activities = Activity::query()
            ->with(['office', 'moves'])
            ->between($today, $last)
            ->orderBy('starts_on')->orderBy('starts_at')->orderBy('id')
            ->get();

        $entries = $activities
            ->map(fn (Activity $activity) => $this->activityItem($activity, $user, $today))
            ->concat($this->dayOffHolidays($today, $last)->map(fn (Holiday $holiday) => $this->holidayItem($holiday, $today)))
            // By day; holidays head their day, then activities by time.
            ->sortBy(fn (array $item) => [$item['date'], $item['type'] === 'holiday' ? 0 : 1, $item['sort']])
            ->take(self::LIMIT)
            ->map(fn (array $item) => collect($item)->except('sort')->all())
            ->values()
            ->all();

        return [
            'today' => $today->toDateString(),
            'items' => $entries,
            'month' => $this->month($this->parseMonth($month) ?? $today->copy()->startOfMonth(), $today),
            'count' => $activities->where('status', '!=', ActivityStatus::Cancelled)->count(),
        ];
    }

    /**
     * Non-cancelled activities in the next 7 days, for the At a glance tile.
     */
    public function count(?Carbon $now = null): int
    {
        $today = $this->today($now);

        return Activity::query()->between($today, $today->copy()->addDays(self::DAYS - 1))->notCancelled()->count();
    }

    private function today(?Carbon $now): Carbon
    {
        return ($now ?? now())->copy()->timezone(config('app.timezone'))->startOfDay();
    }

    /**
     * @return array<string, mixed>
     */
    private function activityItem(Activity $activity, User $user, Carbon $today): array
    {
        // A multi-day activity that began before today shows under today.
        $date = $activity->starts_on->lt($today) ? $today : $activity->starts_on;
        $movedFrom = $activity->moves->first()?->from_starts_on;

        $tags = [];
        if ($user->organizational_unit_id !== null && $activity->organizational_unit_id === $user->organizational_unit_id) {
            $tags[] = ['key' => 'mine', 'label' => 'Your office'];
        }
        if ($movedFrom) {
            $tags[] = ['key' => 'moved_from', 'label' => 'Moved from '.$movedFrom->format('j M')];
        }
        if ($activity->status === ActivityStatus::Cancelled) {
            $tags[] = ['key' => 'cancelled', 'label' => 'Cancelled'];
        }

        return [
            'key' => 'activity-'.$activity->id,
            'type' => 'activity',
            'id' => $activity->id,
            'date' => $date->toDateString(),
            'today' => $date->isSameDay($today),
            'title' => $activity->title,
            'time' => MonthSheet::timeText($activity),
            'mode' => $activity->mode?->label(),
            'venue' => $activity->venue,
            'responsible' => filled($activity->responsible) ? $activity->responsible : $activity->office?->short_name,
            'participants' => $activity->participants,
            'tags' => $tags,
            'href' => '/activities?'.http_build_query(['month' => $date->format('Y-m'), 'day' => $date->toDateString(), 'activity' => $activity->id]),
            'sort' => (string) $activity->starts_at.'|'.str_pad((string) $activity->id, 10, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function holidayItem(Holiday $holiday, Carbon $today): array
    {
        return [
            'key' => 'holiday-'.$holiday->date->toDateString().'-'.$holiday->name,
            'type' => 'holiday',
            'id' => null,
            'date' => $holiday->date->toDateString(),
            'today' => $holiday->date->isSameDay($today),
            'title' => $holiday->name,
            'time' => null,
            'mode' => null,
            'venue' => null,
            'responsible' => null,
            'participants' => null,
            'tags' => [],
            'kind' => $holiday->type->label(),
            'href' => '/activities?'.http_build_query(['month' => $holiday->date->format('Y-m'), 'day' => $holiday->date->toDateString()]),
            'sort' => $holiday->name,
        ];
    }

    /**
     * The mini calendar: the days with (non-cancelled) activities and the
     * day-off holidays.
     *
     * @return array{month: string, current: bool, days: array<int, string>, holidays: array<int, array{date: string, name: string}>}
     */
    private function month(Carbon $first, Carbon $today): array
    {
        $last = $first->copy()->endOfMonth()->startOfDay();

        $days = Activity::query()->between($first, $last)->notCancelled()
            ->get(['starts_on', 'ends_on'])
            ->flatMap(function (Activity $activity) use ($first, $last): array {
                $from = $activity->starts_on->max($first);
                $to = ($activity->ends_on ?? $activity->starts_on)->min($last);
                $dates = [];
                for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                    $dates[] = $day->toDateString();
                }

                return $dates;
            })
            ->unique()->sort()->values()->all();

        return [
            'month' => $first->format('Y-m'),
            'current' => $first->isSameMonth($today),
            'days' => $days,
            'holidays' => $this->dayOffHolidays($first, $last)
                ->map(fn (Holiday $holiday) => ['date' => $holiday->date->toDateString(), 'name' => $holiday->name])
                ->values()->all(),
        ];
    }

    /**
     * @return Collection<int, Holiday>
     */
    private function dayOffHolidays(Carbon $from, Carbon $to): Collection
    {
        return $this->holidays->between($from, $to)->filter(fn (Holiday $holiday) => $holiday->type->isDayOff())->values();
    }

    private function parseMonth(?string $month): ?Carbon
    {
        if ($month === null || ! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $parts) || (int) $parts[1] < 2000 || (int) $parts[1] > 2100) {
            return null;
        }

        return Carbon::create((int) $parts[1], (int) $parts[2], 1, 0, 0, 0, config('app.timezone'));
    }
}
