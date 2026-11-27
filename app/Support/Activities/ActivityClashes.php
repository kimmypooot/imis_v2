<?php

namespace App\Support\Activities;

use App\Models\Activity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calendar of Activities: clash warnings (never blocks). Two non-cancelled,
 * dated activities clash when their dates overlap, their times overlap
 * (an activity without times, or all-day, covers the whole day), and they
 * share a venue (case-insensitive, trimmed, non-empty) or the posting
 * office. Removed (soft-deleted) and month-only activities never clash.
 *
 * Times are compared as half-open ranges, so 9:00–12:00 and 12:00–15:00
 * do not clash. A start time without an end time runs to the end of the
 * day.
 */
class ActivityClashes
{
    /**
     * Other activities clashing with save-shaped data.
     *
     * @param  array{organizational_unit_id?: ?int, starts_on?: ?string, ends_on?: ?string, starts_at?: ?string, ends_at?: ?string, all_day?: bool, venue?: ?string}  $data
     * @return Collection<int, Activity>
     */
    public function for(array $data, ?int $ignoreId = null): Collection
    {
        $candidate = $this->slot($data);

        if ($candidate === null) {
            return collect();
        }

        return Activity::query()
            ->notCancelled()
            ->between(Carbon::parse($candidate['from']), Carbon::parse($candidate['to']))
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->orderBy('starts_on')->orderBy('starts_at')->orderBy('id')
            ->get()
            ->filter(fn (Activity $activity): bool => $this->clash($candidate, $this->slot($activity)))
            ->values();
    }

    /**
     * Clashing activities in a month, each pair listed once: under the
     * earlier activity (by date, time, then id).
     *
     * @return Collection<int, array{activity: Activity, with: Collection<int, Activity>}>
     */
    public function forMonth(int $year, int $month): Collection
    {
        $first = Carbon::create($year, $month, 1)->startOfDay();

        $activities = Activity::query()
            ->notCancelled()
            ->between($first, $first->copy()->endOfMonth())
            ->orderBy('starts_on')->orderBy('starts_at')->orderBy('id')
            ->get()
            ->values();

        $slots = $activities->map(fn (Activity $activity): ?array => $this->slot($activity));

        return $activities
            ->map(fn (Activity $activity, int $index): array => [
                'activity' => $activity,
                'with' => $activities
                    ->slice($index + 1)
                    ->filter(fn (Activity $other, int $otherIndex): bool => $this->clash($slots[$index], $slots[$otherIndex]))
                    ->values(),
            ])
            ->filter(fn (array $pair): bool => $pair['with']->isNotEmpty())
            ->values();
    }

    /**
     * Whether two slots clash.
     *
     * @param  array{from: string, to: string, start: ?string, end: ?string, venue: string, office: ?int}|null  $one
     * @param  array{from: string, to: string, start: ?string, end: ?string, venue: string, office: ?int}|null  $other
     */
    private function clash(?array $one, ?array $other): bool
    {
        if ($one === null || $other === null) {
            return false;
        }

        if ($one['from'] > $other['to'] || $other['from'] > $one['to']) {
            return false;
        }

        if ($one['start'] !== null && $other['start'] !== null
            && ! ($one['start'] < $other['end'] && $other['start'] < $one['end'])) {
            return false;
        }

        $sameVenue = $one['venue'] !== '' && $one['venue'] === $other['venue'];
        $sameOffice = $one['office'] !== null && $one['office'] === $other['office'];

        return $sameVenue || $sameOffice;
    }

    /**
     * The normalized date range, daily times, venue and office of an
     * activity or save-shaped data; null when it has no date.
     *
     * @param  Activity|array<string, mixed>  $source
     * @return array{from: string, to: string, start: ?string, end: ?string, venue: string, office: ?int}|null
     */
    private function slot(Activity|array $source): ?array
    {
        $get = fn (string $key): mixed => $source instanceof Activity ? $source->getAttribute($key) : ($source[$key] ?? null);

        $startsOn = $get('starts_on');

        if (blank($startsOn)) {
            return null;
        }

        $from = Carbon::parse($startsOn)->toDateString();
        $endsOn = $get('ends_on');
        $to = blank($endsOn) ? $from : Carbon::parse($endsOn)->toDateString();

        $start = null;
        $end = null;

        if (! $get('all_day') && filled($get('starts_at'))) {
            $start = $this->time($get('starts_at'));
            $end = filled($get('ends_at')) ? $this->time($get('ends_at')) : '24:00:00';
        }

        $office = $get('organizational_unit_id');

        return [
            'from' => $from,
            'to' => $to,
            'start' => $start,
            'end' => $end,
            'venue' => mb_strtolower(trim((string) $get('venue'))),
            'office' => $office === null ? null : (int) $office,
        ];
    }

    /**
     * A time as `H:i:s`, for string comparison.
     */
    private function time(string $time): string
    {
        return Carbon::parse($time)->format('H:i:s');
    }
}
