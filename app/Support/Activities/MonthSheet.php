<?php

namespace App\Support\Activities;

use App\Enums\ActivityMode;
use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Holiday;
use App\Support\HR\Leave\HolidayCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calendar of Activities: one month in the reference sheet's layout, for
 * the print page and the Excel download. A date row for each day with
 * activities or a day-off holiday (holidays first, as `HOLIDAY` rows),
 * then that day's activities, whole-day ones first and the rest by start
 * time; a multi-day activity appears under each of its days. Month-only
 * activities are listed last under "Dates to be announced". Cancelled
 * activities stay, flagged and with their reason in the remarks.
 *
 * @phpstan-type Row array{time: string, mode: string, venue: string, participants: string, activity: string, responsible: string, remarks: string, cancelled: bool}
 */
class MonthSheet
{
    /**
     * The office and mode, when given, narrow the activities; holiday rows stay.
     *
     * @return array{title: string, days: array<int, array{label: string, holiday: ?string, rows: array<int, Row>}>, tba: array<int, Row>}
     */
    public static function build(int $year, int $month, ?int $officeId = null, ?ActivityMode $mode = null): array
    {
        $first = Carbon::create($year, $month, 1)->startOfDay();
        $last = $first->copy()->endOfMonth()->startOfDay();

        $activities = Activity::query()
            ->with(['office', 'moves'])
            ->inMonth($year, $month)
            ->when($officeId !== null, fn ($query) => $query->where('organizational_unit_id', $officeId))
            ->when($mode !== null, fn ($query) => $query->where('mode', $mode->value))
            ->get()
            ->sortBy(fn (Activity $activity) => [$activity->all_day ? 0 : 1, (string) $activity->starts_at, $activity->id])
            ->values();

        [$dated, $monthOnly] = $activities->partition(fn (Activity $activity) => $activity->starts_on !== null);

        $holidays = app(HolidayCalendar::class)->between($first, $last)
            ->filter(fn (Holiday $holiday) => $holiday->type->isDayOff())
            ->groupBy(fn (Holiday $holiday) => $holiday->date->toDateString());

        $days = [];

        for ($day = $first->copy(); $day->lte($last); $day->addDay()) {
            $date = $day->toDateString();
            /** @var Collection<int, Holiday> $dayHolidays */
            $dayHolidays = $holidays->get($date, collect());
            $dayActivities = $dated->filter(fn (Activity $activity) => $activity->starts_on->toDateString() <= $date
                && ($activity->ends_on ?? $activity->starts_on)->toDateString() >= $date);

            if ($dayHolidays->isEmpty() && $dayActivities->isEmpty()) {
                continue;
            }

            $days[] = [
                'label' => $day->format('j F Y (l)'),
                'holiday' => $dayHolidays->isEmpty() ? null : $dayHolidays->pluck('name')->implode(', '),
                'rows' => [
                    ...$dayHolidays->map(fn (Holiday $holiday) => self::holidayRow($holiday))->values()->all(),
                    ...$dayActivities->map(fn (Activity $activity) => self::row($activity))->values()->all(),
                ],
            ];
        }

        return [
            'title' => 'CSC RO VIII CALENDAR OF ACTIVITIES for '.strtoupper($first->format('F Y')),
            'days' => $days,
            'tba' => $monthOnly->map(fn (Activity $activity) => self::row($activity))->values()->all(),
        ];
    }

    /**
     * `8:00 AM - 10:00 AM`, noon as `12:00 NN`, no end as `- onwards`,
     * a whole-day activity as `Whole day`; blank when no time is set.
     */
    public static function timeText(Activity $activity): string
    {
        if ($activity->all_day) {
            return 'Whole day';
        }

        if (blank($activity->starts_at)) {
            return '';
        }

        $end = blank($activity->ends_at) ? 'onwards' : self::clock($activity->ends_at);

        return self::clock($activity->starts_at).' - '.$end;
    }

    /**
     * The office's remarks, then `Moved from {day month}` for the latest
     * move, `Cancelled: {reason}` and `Unplanned`, joined with `; `.
     */
    public static function remarksText(Activity $activity): string
    {
        $movedFrom = $activity->moves->first()?->from_starts_on;
        $cancelled = $activity->status === ActivityStatus::Cancelled;

        return collect([
            $activity->remarks,
            $movedFrom ? 'Moved from '.$movedFrom->format('j F') : null,
            $cancelled ? (filled($activity->cancel_reason) ? 'Cancelled: '.$activity->cancel_reason : 'Cancelled') : null,
            $activity->unplanned ? 'Unplanned' : null,
        ])->filter(fn (?string $part) => filled($part))->implode('; ');
    }

    /**
     * @return Row
     */
    private static function row(Activity $activity): array
    {
        return [
            'time' => self::timeText($activity),
            'mode' => $activity->mode?->label() ?? '',
            'venue' => (string) $activity->venue,
            'participants' => (string) $activity->participants,
            'activity' => (string) $activity->title,
            'responsible' => filled($activity->responsible) ? (string) $activity->responsible : (string) $activity->office?->short_name,
            'remarks' => self::remarksText($activity),
            'cancelled' => $activity->status === ActivityStatus::Cancelled,
        ];
    }

    /**
     * @return Row
     */
    private static function holidayRow(Holiday $holiday): array
    {
        return [
            'time' => 'HOLIDAY',
            'mode' => '',
            'venue' => '',
            'participants' => '',
            'activity' => $holiday->name,
            'responsible' => '',
            'remarks' => '',
            'cancelled' => false,
        ];
    }

    private static function clock(string $time): string
    {
        $at = Carbon::createFromFormat('H:i:s', strlen($time) === 5 ? $time.':00' : $time);

        return $at->format('H:i') === '12:00' ? '12:00 NN' : $at->format('g:i A');
    }
}
