<?php

namespace App\Support\Activities;

use App\Enums\ActivityMode;
use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityMove;
use App\Models\ActivityPlanCutoff;
use App\Models\User;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Calendar of Activities: the only writer of activities and their moves.
 *
 * An activity without a date is `planned` for its year and month; giving
 * it its first date makes it `scheduled` (no move). Changing the date or
 * time of an activity that already had one records an `ActivityMove` and
 * needs a reason. Activities created after the year's plan cut-off are
 * flagged unplanned, and the flag is never cleared automatically. Editing,
 * moving or returning an occurrence of a series detaches it (the series is
 * kept in `detached_from_series_id`). Nothing here
 * audits; the controllers do.
 */
class Activities
{
    /** The fields that say when an activity is held. */
    private const WHEN = ['starts_on', 'ends_on', 'starts_at', 'ends_at', 'all_day'];

    /** The descriptive fields a poster fills in. */
    private const DETAILS = ['organizational_unit_id', 'title', 'responsible', 'participants', 'venue', 'mode', 'remarks'];

    /**
     * Create an activity, or edit one. On an edit, keys left out of `$data`
     * keep their current values. Saving an activity PALD returned clears
     * the return: the office has acted on it.
     *
     * @param  array{organizational_unit_id?: int, title?: string, responsible?: ?string, participants?: ?string, venue?: ?string, mode?: ActivityMode|string, remarks?: ?string, year?: ?int, month?: ?int, starts_on?: ?string, ends_on?: ?string, starts_at?: ?string, ends_at?: ?string, all_day?: bool, unplanned?: bool}  $data
     *
     * @throws MoveReasonRequired when a dated activity's date or time changes without a reason
     * @throws InvalidArgumentException when a required field is missing or the dates are out of order
     * @throws DomainException when a dated activity would lose its date
     */
    public function save(array $data, User $by, ?Activity $activity = null, ?string $moveReason = null): Activity
    {
        return DB::transaction(function () use ($data, $by, $activity, $moveReason): Activity {
            $isNew = $activity === null;
            $activity ??= new Activity(['created_by' => $by->id, 'status' => ActivityStatus::Planned]);

            $activity->fill($this->details($data, $activity, $isNew));

            $when = array_merge($this->currentWhen($activity), Arr::only($data, self::WHEN));
            $year = (int) ($data['year'] ?? $activity->year);
            $month = (int) ($data['month'] ?? $activity->month);
            $this->applyWhen($activity, $when, $moveReason, $by, $year, $month);

            $ticked = (bool) ($data['unplanned'] ?? false);
            $activity->unplanned = $isNew
                ? $ticked || today()->gt(ActivityPlanCutoff::for($activity->year))
                : $activity->unplanned || $ticked;

            if (! $isNew) {
                $this->detach($activity);
            }
            if ($activity->returned_at !== null) {
                $activity->returned_note = null;
                $activity->returned_at = null;
            }

            $activity->updated_by = $by->id;
            $activity->save();

            return $activity->fresh();
        });
    }

    /**
     * Move an activity to another date or time, with the reason. A
     * month-only activity given its date this way is simply scheduled.
     *
     * @param  array{starts_on: string, ends_on?: ?string, starts_at?: ?string, ends_at?: ?string, all_day?: bool}  $when
     *
     * @throws MoveReasonRequired when the reason is blank
     * @throws DomainException when the activity is cancelled or done
     */
    public function move(Activity $activity, array $when, string $reason, User $by): Activity
    {
        if (in_array($activity->status, [ActivityStatus::Cancelled, ActivityStatus::Done], true)) {
            throw new DomainException('A cancelled or done activity can\'t be moved.');
        }
        if (blank($when['starts_on'] ?? null)) {
            throw new InvalidArgumentException('Give the new date of the activity.');
        }

        return DB::transaction(function () use ($activity, $when, $reason, $by): Activity {
            $when = array_merge(
                ['ends_on' => null, 'starts_at' => null, 'ends_at' => null, 'all_day' => false],
                Arr::only($when, self::WHEN),
            );
            $this->applyWhen($activity, $when, $reason, $by, $activity->year, $activity->month);

            $this->detach($activity);
            $activity->updated_by = $by->id;
            $activity->save();

            return $activity->fresh();
        });
    }

    /**
     * Cancel an activity. Its dates are kept, so the sheet still shows
     * when it would have been held.
     */
    public function cancel(Activity $activity, string $reason, User $by): Activity
    {
        $reason = $this->required($reason, 'Give the reason for cancelling this activity.');

        if ($activity->status === ActivityStatus::Done) {
            throw new DomainException('This activity was already held; it can no longer be cancelled.');
        }

        return DB::transaction(function () use ($activity, $reason, $by): Activity {
            $activity->forceFill([
                'status' => ActivityStatus::Cancelled,
                'cancel_reason' => $reason,
                'updated_by' => $by->id,
            ])->save();

            return $activity->fresh();
        });
    }

    /**
     * Mark an activity as held. Only an activity with a date can be done.
     */
    public function markDone(Activity $activity, User $by): Activity
    {
        if ($activity->starts_on === null) {
            throw new DomainException('Give this activity its date before marking it done.');
        }
        if ($activity->status === ActivityStatus::Cancelled) {
            throw new DomainException('A cancelled activity can\'t be marked done.');
        }

        return DB::transaction(function () use ($activity, $by): Activity {
            $activity->forceFill([
                'status' => ActivityStatus::Done,
                'updated_by' => $by->id,
            ])->save();

            return $activity->fresh();
        });
    }

    /**
     * PALD hands an activity back to its office with a note. It stays on
     * the calendar and is detached from its series.
     */
    public function returnToOffice(Activity $activity, string $note, User $by): Activity
    {
        $note = $this->required($note, 'Say what the office should change.');

        return DB::transaction(function () use ($activity, $note, $by): Activity {
            $this->detach($activity);
            $activity->forceFill([
                'returned_note' => $note,
                'returned_at' => now(),
                'updated_by' => $by->id,
            ])->save();

            return $activity->fresh();
        });
    }

    /**
     * PALD removes an activity: the row is soft-deleted with the reason,
     * so the office can still see what happened to it.
     */
    public function remove(Activity $activity, string $reason, User $by): void
    {
        $reason = $this->required($reason, 'Give the reason for removing this activity.');

        DB::transaction(function () use ($activity, $reason, $by): void {
            $activity->forceFill([
                'removed_reason' => $reason,
                'updated_by' => $by->id,
            ])->save();

            $activity->delete();
        });
    }

    /**
     * Take an occurrence out of its series, remembering which series it
     * left so a "this and the following" edit keeps its original date.
     */
    private function detach(Activity $activity): void
    {
        if ($activity->series_id !== null) {
            $activity->detached_from_series_id = $activity->series_id;
            $activity->series_id = null;
        }
    }

    /**
     * The descriptive fields to fill, trimmed, with the title and mode
     * checked. On an edit only the keys given are changed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function details(array $data, Activity $activity, bool $isNew): array
    {
        $details = Arr::only($data, self::DETAILS);

        foreach (['title', 'responsible', 'participants', 'venue', 'remarks'] as $field) {
            if (array_key_exists($field, $details)) {
                $value = trim((string) $details[$field]);
                $details[$field] = $value === '' ? null : $value;
            }
        }

        if ($isNew && blank($details['organizational_unit_id'] ?? null)) {
            throw new InvalidArgumentException('Choose the office posting this activity.');
        }
        if (($isNew || array_key_exists('title', $details)) && ($details['title'] ?? null) === null) {
            throw new InvalidArgumentException('Give the title of the activity.');
        }
        if ($isNew || array_key_exists('mode', $details)) {
            $mode = $details['mode'] ?? null;
            $mode = $mode instanceof ActivityMode ? $mode : ActivityMode::tryFrom((string) $mode);

            if ($mode === null) {
                throw new InvalidArgumentException('Choose whether the activity is face-to-face, hybrid or online.');
            }
            $details['mode'] = $mode;
        }

        return $details;
    }

    /**
     * The activity's current date and time, in the normalized form.
     *
     * @return array{starts_on: ?string, ends_on: ?string, starts_at: ?string, ends_at: ?string, all_day: bool}
     */
    private function currentWhen(Activity $activity): array
    {
        return [
            'starts_on' => $activity->starts_on?->toDateString(),
            'ends_on' => $activity->ends_on?->toDateString(),
            'starts_at' => $this->time($activity->starts_at),
            'ends_at' => $this->time($activity->ends_at),
            'all_day' => (bool) $activity->all_day,
        ];
    }

    /**
     * Put the activity on its new date and time, recording a move when an
     * activity that already had a date changes, and set its year, month
     * and planned/scheduled status. Cancelled and done keep their status.
     *
     * @param  array<string, mixed>  $when
     */
    private function applyWhen(Activity $activity, array $when, ?string $reason, User $by, ?int $year, ?int $month): void
    {
        $new = $this->normalizeWhen($when);
        $old = $activity->exists ? $this->normalizeWhen($this->currentWhen($activity), checkOrder: false) : null;

        if ($new['starts_on'] === null) {
            if ($old !== null && $old['starts_on'] !== null) {
                throw new DomainException('A dated activity can\'t go back to month only; move it to its new date instead.');
            }
            if ($year === null || $month === null || $year < 1 || $month < 1 || $month > 12) {
                throw new InvalidArgumentException('Give the month of the activity.');
            }
        } else {
            $start = Carbon::parse($new['starts_on']);
            $year = $start->year;
            $month = $start->month;
        }

        if ($old !== null && $old['starts_on'] !== null && $old != $new) {
            $reason = trim((string) $reason);

            if ($reason === '') {
                throw new MoveReasonRequired;
            }

            ActivityMove::query()->create([
                'activity_id' => $activity->id,
                'from_starts_on' => $old['starts_on'],
                'from_ends_on' => $old['ends_on'],
                'from_starts_at' => $old['starts_at'],
                'from_ends_at' => $old['ends_at'],
                'to_starts_on' => $new['starts_on'],
                'to_ends_on' => $new['ends_on'],
                'to_starts_at' => $new['starts_at'],
                'to_ends_at' => $new['ends_at'],
                'reason' => mb_substr($reason, 0, 255),
                'moved_by' => $by->id,
            ]);
        }

        $activity->forceFill([...$new, 'year' => $year, 'month' => $month]);

        if (! in_array($activity->status, [ActivityStatus::Cancelled, ActivityStatus::Done], true)) {
            $activity->status = $new['starts_on'] === null ? ActivityStatus::Planned : ActivityStatus::Scheduled;
        }
    }

    /**
     * Dates as Y-m-d and times as H:i:s; `ends_on` defaults to `starts_on`;
     * an all-day or month-only activity has no times. Checks the order of
     * the dates and, on a single day, of the times, unless `$checkOrder` is
     * off (the stored row, normalized only so it compares like for like).
     *
     * @param  array<string, mixed>  $when
     * @return array{starts_on: ?string, ends_on: ?string, starts_at: ?string, ends_at: ?string, all_day: bool}
     */
    private function normalizeWhen(array $when, bool $checkOrder = true): array
    {
        $startsOn = blank($when['starts_on'] ?? null) ? null : Carbon::parse($when['starts_on'])->toDateString();

        if ($startsOn === null) {
            return ['starts_on' => null, 'ends_on' => null, 'starts_at' => null, 'ends_at' => null, 'all_day' => false];
        }

        $endsOn = blank($when['ends_on'] ?? null) ? $startsOn : Carbon::parse($when['ends_on'])->toDateString();
        $allDay = (bool) ($when['all_day'] ?? false);
        $startsAt = $allDay ? null : $this->time($when['starts_at'] ?? null);
        $endsAt = $allDay ? null : $this->time($when['ends_at'] ?? null);

        if ($checkOrder && $endsOn < $startsOn) {
            throw new InvalidArgumentException('The end date can\'t be before the start date.');
        }
        if ($checkOrder && $startsOn === $endsOn && $startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
            throw new InvalidArgumentException('The end time must be after the start time.');
        }

        return ['starts_on' => $startsOn, 'ends_on' => $endsOn, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'all_day' => $allDay];
    }

    /** A time as H:i:s, or null when blank. */
    private function time(mixed $value): ?string
    {
        return blank($value) ? null : Carbon::parse((string) $value)->format('H:i:s');
    }

    /** A trimmed reason or note that must not be blank. */
    private function required(string $value, string $message): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException($message);
        }

        return mb_substr($value, 0, 255);
    }
}
