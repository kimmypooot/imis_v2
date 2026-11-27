<?php

namespace App\Http\Controllers;

use App\Enums\ActivityMode;
use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\UserStatus;
use App\Http\Requests\Activities\SaveActivityRequest;
use App\Models\Activity;
use App\Models\ActivityMove;
use App\Models\ActivitySeries;
use App\Models\Holiday;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Notifications\ActivityUpdate;
use App\Support\Activities\Activities;
use App\Support\Activities\ActivityClashes;
use App\Support\Activities\ActivitySeriesBuilder;
use App\Support\Activities\MonthSheet;
use App\Support\Activities\MoveReasonRequired;
use App\Support\HR\Leave\HolidayCalendar;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Calendar of Activities in the portal: every staff account sees the month;
 * an office's poster adds and keeps their own office's activities; PALD
 * keeps any office's and returns or removes them (the office's posters and
 * the activity's creator get a bell notification). Saving warns about
 * clashes (`clashes` in the session) without blocking. The rules live in
 * Activities, ActivitySeriesBuilder and ActivityClashes.
 */
class ActivityController extends Controller
{
    /** The fields a series' template keeps for each occurrence. */
    private const SERIES_TEMPLATE = ['title', 'responsible', 'participants', 'venue', 'mode', 'remarks', 'starts_at', 'ends_at', 'all_day', 'unplanned'];

    /** The fields of a series' rule that say how it repeats; a new repeat block replaces them together. */
    private const SERIES_PATTERN = ['frequency', 'interval', 'weekday', 'day_of_month', 'nth_weekday'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Activity::class);

        $user = $request->user();
        $month = $this->month($request->string('month')->toString());
        $view = $request->input('view') === 'list' ? 'list' : 'grid';
        $mode = ActivityMode::tryFrom((string) $request->input('mode'));

        // "mine" is the viewer's office (ignored when the account has none); a number is that office.
        $office = $request->input('office');
        $office = $office === 'mine' && $user->organizational_unit_id !== null ? 'mine' : (is_numeric($office) ? (string) (int) $office : null);
        $officeId = match (true) {
            $office === 'mine' => (int) $user->organizational_unit_id,
            $office !== null => (int) $office,
            default => null,
        };

        $activities = Activity::query()
            ->with(['office', 'moves'])
            ->inMonth($month->year, $month->month)
            ->when($officeId !== null, fn ($query) => $query->where('organizational_unit_id', $officeId))
            ->when($mode !== null, fn ($query) => $query->where('mode', $mode->value))
            ->orderByRaw('starts_on is null')->orderBy('starts_on')->orderBy('starts_at')->orderBy('id')
            ->get();

        return Inertia::render('Activities/Index', [
            'month' => $month->format('Y-m'),
            'view' => $view,
            'filters' => ['office' => $office, 'mode' => $mode?->value],
            'officeId' => $officeId,
            'myOfficeId' => $user->organizational_unit_id,
            'activities' => $activities->map(fn (Activity $activity) => $this->present($activity, $user))->values(),
            'sheet' => $view === 'list' ? MonthSheet::build($month->year, $month->month, $officeId, $mode) : null,
            'holidays' => app(HolidayCalendar::class)->between($month, $month->copy()->endOfMonth()->startOfDay())
                ->map(fn (Holiday $holiday) => ['date' => $holiday->date->toDateString(), 'name' => $holiday->name, 'day_off' => $holiday->type->isDayOff()])
                ->values(),
            'offices' => $this->officeOptions(OrganizationalUnit::query()->workingOffices()->inOrder()->get(['id', 'name', 'short_name'])),
            'postOffices' => $this->officeOptions($this->postingOffices($user)),
            'modeOptions' => ActivityMode::options(),
            'canPost' => $user->can('create', Activity::class),
            'canManage' => $user->can('manage', Activity::class),
            'clashes' => fn () => $request->session()->get('clashes', []),
        ]);
    }

    /**
     * @param  EloquentCollection<int, OrganizationalUnit>  $units
     * @return array<int, array{id: int, name: string, short_name: ?string}>
     */
    private function officeOptions(EloquentCollection $units): array
    {
        return $units->map(fn (OrganizationalUnit $unit) => ['id' => $unit->id, 'name' => $unit->name, 'short_name' => $unit->short_name])->values()->all();
    }

    /**
     * The details modal.
     */
    public function show(Request $request, Activity $activity): JsonResponse
    {
        $this->authorize('view', $activity);

        $activity->load(['office', 'moves', 'creator', 'series']);
        $movers = User::query()->whereIn('id', $activity->moves->pluck('moved_by')->filter()->unique())->pluck('name', 'id');

        return response()->json([
            'activity' => [
                ...$this->present($activity, $request->user()),
                'created_by' => $activity->creator?->name,
                // The series' repeat rule, so a "this and the following" edit can start from it.
                'series' => $activity->series ? $this->seriesRule($activity->series) : null,
                'moves' => $activity->moves->map(fn (ActivityMove $move) => [
                    'from_starts_on' => $move->from_starts_on?->toDateString(),
                    'from_ends_on' => $move->from_ends_on?->toDateString(),
                    'from_starts_at' => $this->time($move->from_starts_at),
                    'from_ends_at' => $this->time($move->from_ends_at),
                    'to_starts_on' => $move->to_starts_on?->toDateString(),
                    'to_ends_on' => $move->to_ends_on?->toDateString(),
                    'to_starts_at' => $this->time($move->to_starts_at),
                    'to_ends_at' => $this->time($move->to_ends_at),
                    'reason' => $move->reason,
                    'moved_by' => $movers[$move->moved_by] ?? null,
                    'moved_at' => $move->created_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    public function store(SaveActivityRequest $request, Activities $activities, ActivitySeriesBuilder $builder, ActivityClashes $clashes): RedirectResponse
    {
        $user = $request->user();
        $data = $request->activityData();
        $this->authorize('postFor', [Activity::class, (int) $data['organizational_unit_id']]);

        if (($repeat = $request->repeat()) !== null) {
            $series = $this->attempt(fn () => $builder->create(
                [...$repeat, 'starts_on' => $data['starts_on']],
                Arr::only($data, self::SERIES_TEMPLATE),
                (int) $data['organizational_unit_id'],
                $user,
            ));
            $count = $series->activities()->count();
            $this->audit()->record(AuditEvent::ActivitySeriesSaved, "Added the repeating activity \"{$data['title']}\" ({$count} dates)", $series);

            return back()
                ->with('status', "\"{$data['title']}\" was added on {$count} ".str('date')->plural($count).'.')
                ->with('clashes', $this->seriesClashes($series, $clashes));
        }

        $activity = $this->attempt(fn () => $activities->save($data, $user));
        $this->audit()->record(AuditEvent::ActivitySaved, "Added the activity \"{$activity->title}\"", $activity);

        return back()
            ->with('status', "\"{$activity->title}\" was added.")
            ->with('clashes', $this->clashes($activity, $clashes));
    }

    public function update(SaveActivityRequest $request, Activity $activity, Activities $activities, ActivitySeriesBuilder $builder, ActivityClashes $clashes): RedirectResponse
    {
        $user = $request->user();
        $data = $request->activityData();

        if (isset($data['organizational_unit_id']) && (int) $data['organizational_unit_id'] !== $activity->organizational_unit_id) {
            $this->authorize('postFor', [Activity::class, (int) $data['organizational_unit_id']]);
        }

        if ($request->validated('scope') === 'following' && $activity->series_id !== null && $activity->starts_on !== null) {
            /** @var ActivitySeries $series */
            $series = $activity->series;
            $rule = $this->followingRule($series, $request->repeat());
            $template = [
                ...Arr::only($series->template ?? [], self::SERIES_TEMPLATE),
                ...Arr::only($data, self::SERIES_TEMPLATE),
            ];

            $series = $this->attempt(fn () => $builder->updateFollowing($series, $activity->starts_on->copy(), $rule, $template, $user));
            $title = $template['title'] ?? $activity->title;
            $this->audit()->record(AuditEvent::ActivitySeriesSaved, "Changed the repeating activity \"{$title}\" from {$activity->starts_on->toDateString()} on", $series);

            return back()
                ->with('status', "\"{$title}\" was saved for this and the following dates.")
                ->with('clashes', $this->seriesClashes($series, $clashes, $activity->starts_on));
        }

        $saved = $this->attempt(fn () => $activities->save($data, $user, $activity, $request->validated('move_reason')));
        $this->audit()->record(AuditEvent::ActivitySaved, "Updated the activity \"{$saved->title}\"", $saved);

        return back()
            ->with('status', "\"{$saved->title}\" was saved.")
            ->with('clashes', $this->clashes($saved, $clashes));
    }

    public function move(Request $request, Activity $activity, Activities $activities, ActivityClashes $clashes): RedirectResponse
    {
        $this->authorize('update', $activity);

        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i'],
            'all_day' => ['boolean'],
            'move_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $moved = $this->attempt(fn () => $activities->move($activity, Arr::except($data, ['move_reason']), (string) ($data['move_reason'] ?? ''), $request->user()));
        $this->audit()->record(AuditEvent::ActivityMoved, "Moved the activity \"{$moved->title}\" to {$moved->starts_on->toDateString()}", $moved, [
            'reason' => $data['move_reason'] ?? null,
        ]);

        return back()
            ->with('status', "\"{$moved->title}\" was moved.")
            ->with('clashes', $this->clashes($moved, $clashes));
    }

    public function cancel(Request $request, Activity $activity, Activities $activities): RedirectResponse
    {
        $this->authorize('update', $activity);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $cancelled = $this->attempt(fn () => $activities->cancel($activity, $data['reason'], $request->user()), 'reason');
        $this->audit()->record(AuditEvent::ActivityCancelled, "Cancelled the activity \"{$cancelled->title}\"", $cancelled, ['reason' => $data['reason']]);

        return back()->with('status', "\"{$cancelled->title}\" was cancelled.");
    }

    public function done(Request $request, Activity $activity, Activities $activities): RedirectResponse
    {
        $this->authorize('update', $activity);

        $done = $this->attempt(fn () => $activities->markDone($activity, $request->user()));
        $this->audit()->record(AuditEvent::ActivityDone, "Marked the activity \"{$done->title}\" done", $done);

        return back()->with('status', "\"{$done->title}\" was marked done.");
    }

    /**
     * PALD hands an activity back to its office with a note.
     */
    public function returnToOffice(Request $request, Activity $activity, Activities $activities): RedirectResponse
    {
        $this->authorize('manage', Activity::class);

        $data = $request->validate(['note' => ['required', 'string', 'max:255']]);

        $returned = $this->attempt(fn () => $activities->returnToOffice($activity, $data['note'], $request->user()), 'note');
        $this->audit()->record(AuditEvent::ActivityReturned, "Returned the activity \"{$returned->title}\" to its office", $returned, ['note' => $data['note']]);
        $this->notifyOffice($returned, 'Activity returned by PALD', "PALD returned \"{$returned->title}\": {$returned->returned_note}");

        return back()->with('status', "\"{$returned->title}\" was returned to its office.");
    }

    /**
     * PALD removes an activity (a soft delete with the reason).
     */
    public function remove(Request $request, Activity $activity, Activities $activities): RedirectResponse
    {
        $this->authorize('manage', Activity::class);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->attempt(fn () => $activities->remove($activity, $data['reason'], $request->user()), 'reason');
        $this->audit()->record(AuditEvent::ActivityRemoved, "Removed the activity \"{$activity->title}\"", $activity, ['reason' => $data['reason']]);
        $this->notifyOffice($activity, 'Activity removed by PALD', "PALD removed \"{$activity->title}\": {$activity->removed_reason}");

        return back()->with('status', "\"{$activity->title}\" was removed.");
    }

    /**
     * Run a service call, turning its domain errors into validation errors.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function attempt(Closure $call, string $key = 'activity'): mixed
    {
        try {
            return $call();
        } catch (MoveReasonRequired $e) {
            throw ValidationException::withMessages(['move_reason' => $e->getMessage()]);
        } catch (DomainException|InvalidArgumentException $e) {
            throw ValidationException::withMessages([$key => $e->getMessage()]);
        }
    }

    /**
     * Bell the office's active posters and the activity's creator, once each.
     */
    private function notifyOffice(Activity $activity, string $title, string $message): void
    {
        $recipients = User::query()
            ->where('status', UserStatus::Active->value)
            ->where('organizational_unit_id', $activity->organizational_unit_id)
            ->whereHas('activeRoles.permissions', fn ($query) => $query->where('name', PermissionName::ActivitiesPost->value))
            ->get();

        $creator = $activity->created_by ? User::query()->find($activity->created_by) : null;

        if ($creator !== null && $creator->isActive()) {
            $recipients->push($creator);
        }

        $url = '/activities?month='.sprintf('%04d-%02d', $activity->year, $activity->month);

        $recipients->unique('id')->each(fn (User $user) => $user->notify(new ActivityUpdate($title, $message, $url)));
    }

    /**
     * Activities clashing with a saved one, for the warning.
     *
     * @return array<int, array{id: int, title: string, date: string, office: ?string}>
     */
    private function clashes(Activity $activity, ActivityClashes $clashes): array
    {
        return $this->clashRows($clashes->for($this->slot($activity), $activity->id));
    }

    /**
     * Activities clashing with a series' occurrences (from a date on),
     * leaving out the series' own.
     *
     * @return array<int, array{id: int, title: string, date: string, office: ?string}>
     */
    private function seriesClashes(ActivitySeries $series, ActivityClashes $clashes, ?Carbon $from = null): array
    {
        $own = $series->activities()->pluck('id');

        $found = $series->activities()
            ->when($from !== null, fn ($query) => $query->whereDate('starts_on', '>=', $from->toDateString()))
            ->get()
            ->flatMap(fn (Activity $occurrence) => $clashes->for($this->slot($occurrence), $occurrence->id))
            ->reject(fn (Activity $other) => $own->contains($other->id))
            ->unique('id');

        return $this->clashRows($found);
    }

    /**
     * @param  Collection<int, Activity>  $found
     * @return array<int, array{id: int, title: string, date: string, office: ?string}>
     */
    private function clashRows(Collection $found): array
    {
        $found = EloquentCollection::make($found->all())->loadMissing('office');

        return $found->map(fn (Activity $other) => [
            'id' => $other->id,
            'title' => $other->title,
            'date' => $other->starts_on->toDateString(),
            'office' => $other->office?->short_name ?? $other->office?->name,
        ])->values()->all();
    }

    /**
     * A saved activity's full when, where and who, as ActivityClashes takes it.
     *
     * @return array<string, mixed>
     */
    private function slot(Activity $activity): array
    {
        return [
            'organizational_unit_id' => $activity->organizational_unit_id,
            'starts_on' => $activity->starts_on?->toDateString(),
            'ends_on' => $activity->ends_on?->toDateString(),
            'starts_at' => $activity->starts_at,
            'ends_at' => $activity->ends_at,
            'all_day' => $activity->all_day,
            'venue' => $activity->venue,
        ];
    }

    /**
     * The rule for a "this and the following" edit. A repeat block in the
     * request replaces the whole pattern (pattern fields it leaves out are
     * cleared, so an old day of the month can't outrank a new weekday);
     * only the dates and holiday choice fall back to the series.
     *
     * @param  array<string, mixed>|null  $repeat
     * @return array<string, mixed>
     */
    private function followingRule(ActivitySeries $series, ?array $repeat): array
    {
        $rule = $this->seriesRule($series);

        if ($repeat === null) {
            return $rule;
        }

        foreach (self::SERIES_PATTERN as $field) {
            $rule[$field] = $repeat[$field] ?? null;
        }

        return [...$rule, ...Arr::only($repeat, ['starts_on', 'until', 'skip_holidays'])];
    }

    /**
     * @return array<string, mixed>
     */
    private function seriesRule(ActivitySeries $series): array
    {
        return [
            'frequency' => $series->frequency->value,
            'interval' => $series->interval,
            'weekday' => $series->weekday,
            'day_of_month' => $series->day_of_month,
            'nth_weekday' => $series->nth_weekday,
            'starts_on' => $series->starts_on->toDateString(),
            'until' => $series->until->toDateString(),
            'skip_holidays' => $series->skip_holidays,
        ];
    }

    /**
     * @return EloquentCollection<int, OrganizationalUnit>
     */
    private function postingOffices(User $user): EloquentCollection
    {
        return OrganizationalUnit::query()
            ->workingOffices()
            ->inOrder()
            ->when(! $user->can('manage', Activity::class), fn ($query) => $query->whereKey($user->can('create', Activity::class) ? $user->organizational_unit_id : null))
            ->get(['id', 'name', 'short_name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Activity $activity, User $user): array
    {
        return [
            'id' => $activity->id,
            'office' => $activity->office ? ['id' => $activity->office->id, 'name' => $activity->office->name, 'short_name' => $activity->office->short_name] : null,
            'title' => $activity->title,
            'responsible' => $activity->responsible,
            'participants' => $activity->participants,
            'venue' => $activity->venue,
            'mode' => $activity->mode->value,
            'mode_label' => $activity->mode->label(),
            'remarks' => $activity->remarks,
            'year' => $activity->year,
            'month' => $activity->month,
            'starts_on' => $activity->starts_on?->toDateString(),
            'ends_on' => $activity->ends_on?->toDateString(),
            'starts_at' => $this->time($activity->starts_at),
            'ends_at' => $this->time($activity->ends_at),
            'all_day' => $activity->all_day,
            'status' => $activity->status->value,
            'status_label' => $activity->status->label(),
            'cancel_reason' => $activity->cancel_reason,
            'unplanned' => $activity->unplanned,
            'series_id' => $activity->series_id,
            'has_moved' => $activity->has_moved,
            'returned_note' => $activity->returned_note,
            'returned_at' => $activity->returned_at?->toIso8601String(),
            'can_update' => $user->can('update', $activity),
        ];
    }

    /** A stored time as H:i, or null. */
    private function time(mixed $value): ?string
    {
        return blank($value) ? null : Carbon::parse((string) $value)->format('H:i');
    }

    /**
     * The month asked for (`YYYY-MM`), or this month.
     */
    private function month(string $value): Carbon
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $match) === 1 && (int) $match[2] >= 1 && (int) $match[2] <= 12 && (int) $match[1] >= 2000 && (int) $match[1] <= 2100) {
            return Carbon::create((int) $match[1], (int) $match[2], 1)->startOfDay();
        }

        return today()->startOfMonth();
    }
}
