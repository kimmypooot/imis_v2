<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\Activity;
use App\Models\ActivityPlanCutoff;
use App\Models\User;
use App\Support\Activities\ActivityChanges;
use App\Support\Activities\ActivityClashes;
use App\Support\Activities\PlanVersusActual;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calendar of Activities: PALD's management page. What changed in the
 * last 7 or 30 days (from the audit log), clashes in a month, plan versus
 * actual for a year by office, the year's plan cut-off, and the removed
 * activities. The figures come from ActivityChanges, ActivityClashes and
 * PlanVersusActual.
 */
class ActivityManageController extends Controller
{
    public function index(Request $request, ActivityClashes $clashes): Response
    {
        $this->authorize('manage', Activity::class);

        $days = (int) $request->input('days') === 30 ? 30 : 7;
        $year = $this->year($request->input('year'));
        $month = $this->month($request->string('month')->toString());

        return Inertia::render('Activities/Manage', [
            'days' => $days,
            'year' => $year,
            'month' => $month->format('Y-m'),
            'changes' => ActivityChanges::since($days),
            'clashes' => $this->clashRows($clashes->forMonth($month->year, $month->month)),
            'planVersusActual' => PlanVersusActual::for($year),
            'cutoff' => ActivityPlanCutoff::for($year)->toDateString(),
            'cutoffIsDefault' => ! ActivityPlanCutoff::query()->whereKey($year)->exists(),
            'removed' => $this->removed($year),
        ]);
    }

    /**
     * PALD sets a year's plan cut-off. Activities added after it are
     * flagged unplanned; ones already saved keep their flag.
     */
    public function saveCutoff(Request $request): RedirectResponse
    {
        $this->authorize('manage', Activity::class);

        $year = $request->integer('year');
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'cuts_off_on' => ['required', 'date_format:Y-m-d', "after_or_equal:{$year}-01-01", "before_or_equal:{$year}-12-31"],
        ], [
            'cuts_off_on.after_or_equal' => 'The cut-off must fall within the year.',
            'cuts_off_on.before_or_equal' => 'The cut-off must fall within the year.',
        ]);

        $cutoff = ActivityPlanCutoff::query()->updateOrCreate(
            ['year' => (int) $data['year']],
            ['cuts_off_on' => $data['cuts_off_on'], 'updated_by' => $request->user()->id],
        );
        $date = Carbon::parse($data['cuts_off_on'])->format('j F Y');
        $this->audit()->record(AuditEvent::ActivityCutoffSaved, "Set the {$data['year']} plan cut-off to {$date}", $cutoff, [
            'year' => (int) $data['year'],
            'cuts_off_on' => $data['cuts_off_on'],
        ]);

        return back()->with('status', "The {$data['year']} plan cut-off is now {$date}.");
    }

    /**
     * @param  Collection<int, array{activity: Activity, with: Collection<int, Activity>}>  $pairs
     * @return array<int, array{activity: array<string, mixed>, with: array<int, array<string, mixed>>}>
     */
    private function clashRows(Collection $pairs): array
    {
        EloquentCollection::make($pairs->flatMap(fn (array $pair) => [$pair['activity'], ...$pair['with']->all()])->all())
            ->loadMissing('office:id,name,short_name');

        return $pairs->map(fn (array $pair) => [
            'activity' => $this->clashActivity($pair['activity']),
            'with' => $pair['with']->map(fn (Activity $other) => $this->clashActivity($other))->values()->all(),
        ])->values()->all();
    }

    /**
     * @return array{id: int, title: string, starts_on: ?string, ends_on: ?string, starts_at: ?string, ends_at: ?string, venue: ?string, office: ?string}
     */
    private function clashActivity(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'title' => $activity->title,
            'starts_on' => $activity->starts_on?->toDateString(),
            'ends_on' => $activity->ends_on?->toDateString(),
            'starts_at' => blank($activity->starts_at) ? null : Carbon::parse($activity->starts_at)->format('H:i'),
            'ends_at' => blank($activity->ends_at) ? null : Carbon::parse($activity->ends_at)->format('H:i'),
            'venue' => $activity->venue,
            'office' => $activity->office?->short_name ?: $activity->office?->name,
        ];
    }

    /**
     * The year's removed activities, latest first, with the reason and who removed them.
     *
     * @return array<int, array{id: int, title: string, office: ?string, date: ?string, reason: ?string, removed_at: ?string, removed_by: ?string}>
     */
    private function removed(int $year): array
    {
        $removed = Activity::onlyTrashed()
            ->with('office:id,name,short_name')
            ->where('year', $year)
            ->latest('deleted_at')->latest('id')
            ->get();
        $removers = User::query()->whereKey($removed->pluck('updated_by')->filter()->unique()->all())->pluck('name', 'id');

        return $removed->map(fn (Activity $activity) => [
            'id' => $activity->id,
            'title' => $activity->title,
            'office' => $activity->office?->short_name ?: $activity->office?->name,
            'date' => $activity->starts_on?->toDateString() ?? sprintf('%04d-%02d', $activity->year, $activity->month),
            'reason' => $activity->removed_reason,
            'removed_at' => $activity->deleted_at?->toIso8601String(),
            'removed_by' => $removers[$activity->updated_by] ?? null,
        ])->values()->all();
    }

    /** The year asked for, or this year. */
    private function year(mixed $value): int
    {
        $year = is_numeric($value) ? (int) $value : 0;

        return $year >= 2000 && $year <= 2100 ? $year : today()->year;
    }

    /** The month asked for (`YYYY-MM`), or this month. */
    private function month(string $value): Carbon
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $match) === 1 && (int) $match[2] >= 1 && (int) $match[2] <= 12 && (int) $match[1] >= 2000 && (int) $match[1] <= 2100) {
            return Carbon::create((int) $match[1], (int) $match[2], 1)->startOfDay();
        }

        return today()->startOfMonth();
    }
}
