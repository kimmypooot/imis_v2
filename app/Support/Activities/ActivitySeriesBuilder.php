<?php

namespace App\Support\Activities;

use App\Enums\ActivityStatus;
use App\Enums\SeriesFrequency;
use App\Models\Activity;
use App\Models\ActivitySeries;
use App\Models\Holiday;
use App\Models\User;
use App\Support\HR\Leave\HolidayCalendar;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Calendar of Activities: repeating activities. A series is a rule; saving
 * it creates each occurrence as a real `scheduled` activity through
 * `Activities::save()`.
 *
 * `daily` means weekdays only (Monday to Friday), every `interval` calendar
 * days; `weekly` falls on `weekday` every `interval` weeks; `monthly` falls
 * on `day_of_month`, or on the `nth_weekday` `weekday` (e.g. the 1st
 * Monday), every `interval` months. A month without that day (the 31st, a
 * 5th Monday) is skipped, never moved. With `skip_holidays`, dates that
 * are a day-off holiday are dropped. A series never runs past 31 December
 * of the year it starts in.
 */
class ActivitySeriesBuilder
{
    /** The fields of an occurrence a series template may set. */
    private const TEMPLATE = ['title', 'responsible', 'participants', 'venue', 'mode', 'remarks', 'starts_at', 'ends_at', 'all_day'];

    public function __construct(private Activities $activities, private HolidayCalendar $holidays) {}

    /**
     * Save a series and create each of its occurrences.
     *
     * @param  array{frequency: SeriesFrequency|string, interval?: ?int, weekday?: ?int, day_of_month?: ?int, nth_weekday?: ?int, starts_on: string, until?: ?string, skip_holidays?: bool}  $rule
     * @param  array{title?: string, responsible?: ?string, participants?: ?string, venue?: ?string, mode?: string, remarks?: ?string, starts_at?: ?string, ends_at?: ?string, all_day?: bool}  $template
     *
     * @throws InvalidArgumentException when the rule is incomplete or its dates are out of order
     */
    public function create(array $rule, array $template, int $officeId, User $by): ActivitySeries
    {
        return DB::transaction(function () use ($rule, $template, $officeId, $by): ActivitySeries {
            $series = new ActivitySeries([
                ...$this->normalizeRule($rule),
                'template' => Arr::only($template, self::TEMPLATE),
                'organizational_unit_id' => $officeId,
                'created_by' => $by->id,
            ]);
            $series->save();

            foreach ($this->occurrences($series) as $date) {
                $this->createOccurrence($series, $date, $by);
            }

            return $series->fresh();
        });
    }

    /**
     * The dates a series falls on, in order, holidays already dropped when
     * the series skips them.
     *
     * @return Collection<int, Carbon>
     */
    public function occurrences(ActivitySeries $series): Collection
    {
        $start = Carbon::parse($series->starts_on)->startOfDay();
        $until = Carbon::parse($series->until)->startOfDay();
        $interval = max(1, (int) $series->interval);

        $dates = match ($series->frequency) {
            SeriesFrequency::Daily => $this->daily($start, $until, $interval),
            SeriesFrequency::Weekly => $this->weekly($start, $until, $interval, (int) $series->weekday),
            SeriesFrequency::Monthly => $this->monthly($start, $until, $interval, $series),
        };

        if ($series->skip_holidays && $dates->isNotEmpty()) {
            $daysOff = $this->holidays->between($start, $until)
                ->filter(fn (Holiday $holiday) => $holiday->type->isDayOff())
                ->map(fn (Holiday $holiday) => $holiday->date->toDateString())
                ->flip();

            $dates = $dates->reject(fn (Carbon $date) => $daysOff->has($date->toDateString()));
        }

        return $dates->values();
    }

    /**
     * "This and the following ones": change the series' rule and template,
     * then replace its occurrences dated on or after `$from` that are still
     * untouched (attached, scheduled, never moved, never returned). Done
     * and cancelled occurrences keep their place; one already on a date
     * the new rule gives is not doubled. Occurrences moved, returned or
     * edited on their own were detached already and are left alone.
     *
     * @param  array{frequency: SeriesFrequency|string, interval?: ?int, weekday?: ?int, day_of_month?: ?int, nth_weekday?: ?int, starts_on: string, until?: ?string, skip_holidays?: bool}  $rule
     * @param  array{title?: string, responsible?: ?string, participants?: ?string, venue?: ?string, mode?: string, remarks?: ?string, starts_at?: ?string, ends_at?: ?string, all_day?: bool}  $template
     *
     * @throws InvalidArgumentException when the rule is incomplete or its dates are out of order
     */
    public function updateFollowing(ActivitySeries $series, Carbon $from, array $rule, array $template, User $by): ActivitySeries
    {
        $from = $from->copy()->startOfDay();

        return DB::transaction(function () use ($series, $from, $rule, $template, $by): ActivitySeries {
            $series->fill([
                ...$this->normalizeRule($rule),
                'template' => Arr::only($template, self::TEMPLATE),
            ])->save();

            Activity::query()
                ->where('series_id', $series->id)
                ->where('status', ActivityStatus::Scheduled->value)
                ->whereNull('returned_at')
                ->whereDoesntHave('moves')
                ->whereDate('starts_on', '>=', $from)
                ->get()
                ->each(fn (Activity $activity) => $activity->forceDelete());

            $kept = Activity::query()
                ->where('series_id', $series->id)
                ->whereDate('starts_on', '>=', $from)
                ->get()
                ->map(fn (Activity $activity) => $activity->starts_on->toDateString())
                ->flip();

            foreach ($this->occurrences($series) as $date) {
                if ($date->lt($from) || $kept->has($date->toDateString())) {
                    continue;
                }
                $this->createOccurrence($series, $date, $by);
            }

            return $series->fresh();
        });
    }

    /**
     * Create one scheduled occurrence from the series template and attach
     * it to the series.
     */
    private function createOccurrence(ActivitySeries $series, Carbon $date, User $by): Activity
    {
        $activity = $this->activities->save([
            ...($series->template ?? []),
            'organizational_unit_id' => $series->organizational_unit_id,
            'starts_on' => $date->toDateString(),
            'ends_on' => $date->toDateString(),
        ], $by);

        $activity->forceFill(['series_id' => $series->id])->save();

        return $activity;
    }

    /**
     * Check the rule and put it in stored form, with `until` capped at
     * 31 December of the starting year (that year's end when left out).
     *
     * @param  array<string, mixed>  $rule
     * @return array{frequency: SeriesFrequency, interval: int, weekday: ?int, day_of_month: ?int, nth_weekday: ?int, starts_on: string, until: string, skip_holidays: bool}
     */
    private function normalizeRule(array $rule): array
    {
        $frequency = $rule['frequency'] ?? null;
        $frequency = $frequency instanceof SeriesFrequency ? $frequency : SeriesFrequency::tryFrom((string) $frequency);

        if ($frequency === null) {
            throw new InvalidArgumentException('Choose how often the activity repeats.');
        }
        if (blank($rule['starts_on'] ?? null)) {
            throw new InvalidArgumentException('Give the date the activity starts repeating.');
        }

        $interval = (int) ($rule['interval'] ?? 1);
        if ($interval < 1 || $interval > 255) {
            throw new InvalidArgumentException('Repeat every 1 to 255 days, weeks or months.');
        }

        $weekday = $this->optionalInt($rule['weekday'] ?? null);
        $dayOfMonth = $this->optionalInt($rule['day_of_month'] ?? null);
        $nthWeekday = $this->optionalInt($rule['nth_weekday'] ?? null);

        if ($weekday !== null && ($weekday < 1 || $weekday > 7)) {
            throw new InvalidArgumentException('Choose a day of the week.');
        }

        if ($frequency === SeriesFrequency::Daily) {
            [$weekday, $dayOfMonth, $nthWeekday] = [null, null, null];
        } elseif ($frequency === SeriesFrequency::Weekly) {
            if ($weekday === null) {
                throw new InvalidArgumentException('Choose the day of the week the activity repeats on.');
            }
            [$dayOfMonth, $nthWeekday] = [null, null];
        } elseif ($dayOfMonth !== null) {
            if ($dayOfMonth < 1 || $dayOfMonth > 31) {
                throw new InvalidArgumentException('Choose a day of the month from 1 to 31.');
            }
            [$weekday, $nthWeekday] = [null, null];
        } elseif ($nthWeekday === null || $weekday === null || $nthWeekday < 1 || $nthWeekday > 5) {
            throw new InvalidArgumentException('Choose the day of the month, or which weekday of the month, the activity repeats on.');
        }

        $startsOn = Carbon::parse($rule['starts_on'])->startOfDay();
        $yearEnd = $startsOn->copy()->endOfYear()->startOfDay();
        $until = blank($rule['until'] ?? null) ? $yearEnd : Carbon::parse($rule['until'])->startOfDay();

        if ($until->gt($yearEnd)) {
            $until = $yearEnd;
        }
        if ($until->lt($startsOn)) {
            throw new InvalidArgumentException('The last date can\'t be before the first.');
        }

        return [
            'frequency' => $frequency,
            'interval' => $interval,
            'weekday' => $weekday,
            'day_of_month' => $dayOfMonth,
            'nth_weekday' => $nthWeekday,
            'starts_on' => $startsOn->toDateString(),
            'until' => $until->toDateString(),
            'skip_holidays' => (bool) ($rule['skip_holidays'] ?? true),
        ];
    }

    /**
     * Weekdays from `$start` to `$until`, every `$interval` calendar days.
     *
     * @return Collection<int, Carbon>
     */
    private function daily(Carbon $start, Carbon $until, int $interval): Collection
    {
        $dates = collect();

        for ($date = $start->copy(); $date->lte($until); $date = $date->copy()->addDays($interval)) {
            if ($date->isWeekday()) {
                $dates->push($date);
            }
        }

        return $dates;
    }

    /**
     * The ISO `$weekday` on or after `$start`, then every `$interval` weeks.
     *
     * @return Collection<int, Carbon>
     */
    private function weekly(Carbon $start, Carbon $until, int $interval, int $weekday): Collection
    {
        $dates = collect();
        $date = $start->copy()->addDays(($weekday - $start->dayOfWeekIso + 7) % 7);

        for (; $date->lte($until); $date = $date->copy()->addWeeks($interval)) {
            $dates->push($date);
        }

        return $dates;
    }

    /**
     * The series' day in the starting month, then every `$interval` months,
     * skipping months that don't have that day.
     *
     * @return Collection<int, Carbon>
     */
    private function monthly(Carbon $start, Carbon $until, int $interval, ActivitySeries $series): Collection
    {
        $dates = collect();

        for ($month = $start->copy()->startOfMonth(); $month->lte($until); $month = $month->copy()->addMonthsNoOverflow($interval)) {
            $date = $series->day_of_month !== null
                ? $this->dayOfMonth($month, $series->day_of_month)
                : $this->nthWeekday($month, (int) $series->nth_weekday, (int) $series->weekday);

            if ($date !== null && $date->gte($start) && $date->lte($until)) {
                $dates->push($date);
            }
        }

        return $dates;
    }

    /** The given day of the month, or null when the month is shorter. */
    private function dayOfMonth(Carbon $month, int $day): ?Carbon
    {
        return $day <= $month->daysInMonth ? $month->copy()->day($day) : null;
    }

    /** The nth ISO weekday of the month (e.g. the 1st Monday), or null when the month has none. */
    private function nthWeekday(Carbon $month, int $nth, int $weekday): ?Carbon
    {
        $first = $month->copy()->addDays(($weekday - $month->dayOfWeekIso + 7) % 7);
        $date = $first->addWeeks($nth - 1);

        return $date->month === $month->month ? $date : null;
    }

    /** A whole number, or null when blank. */
    private function optionalInt(mixed $value): ?int
    {
        return blank($value) ? null : (int) $value;
    }
}
