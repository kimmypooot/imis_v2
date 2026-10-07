<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\Status;
use App\Http\Requests\Executive\SaveTargetRequest;
use App\Http\Requests\Executive\UpdateFeedRequest;
use App\Models\Application;
use App\Models\ExecutiveSnapshot;
use App\Models\ExecutiveTarget;
use App\Support\Executive\ExecutiveDashboard;
use App\Support\Executive\MetricCollector;
use App\Support\Executive\Sources\SystemFeedSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Executive Dashboard → Settings (`executive.manage`):
 * - Exec-2: each system's summary feed — the address IMIS reads its
 *   figures from, and the key IMIS sends (shown once, when generated);
 * - Exec-3: the target for each figure;
 * - collecting the figures now instead of waiting for the schedule.
 * Every change is audited; a feed key never is, and is never shown again.
 */
class ExecutiveSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manage', ExecutiveSnapshot::class);

        $dashboard = app(ExecutiveDashboard::class, ['viewer' => $request->user()])->build();
        $figures = collect($dashboard['sections'])->flatMap(fn (array $section) => collect($section['metrics'])->map(fn (array $metric) => [
            'id' => $metric['id'],
            'source' => $section['label'],
            'label' => $metric['label'],
            'unit' => $metric['unit'],
            'value' => $metric['value'],
            'status' => $metric['status'],
        ]))->values();
        $targets = ExecutiveTarget::query()->with('updater:id,name')->get()->keyBy('metric');

        return Inertia::render('Executive/Settings', [
            'feeds' => $this->feeds(),
            'figures' => $figures->map(fn (array $figure) => [...$figure, 'target' => $this->target($targets->get($figure['id']))])->all(),
            // Targets for figures no source sends any more, so they can be removed.
            'orphanTargets' => $targets->except($figures->pluck('id')->all())->map(fn (ExecutiveTarget $target) => $this->target($target))->values()->all(),
            'newKey' => $request->session()->get('feed_key'),
            'lastCollectedAt' => $dashboard['lastCollectedAt'],
        ]);
    }

    public function updateFeed(UpdateFeedRequest $request, Application $application): RedirectResponse
    {
        $url = $request->validated('feed_url') ?: null;
        $application->forceFill(['feed_url' => $url, 'feed_key' => $url ? $application->feed_key : null])->save();

        $this->audit()->record(
            AuditEvent::ExecutiveFeedUpdated,
            $url ? "Set the summary feed of {$application->name}" : "Disconnected the summary feed of {$application->name}",
            $application,
            ['feed_url' => $url],
        );

        return back()->with('status', $url ? "Summary feed saved for {$application->name}." : "{$application->name} disconnected.");
    }

    /**
     * A new key replaces the old one at once; the system needs it in its
     * own settings before its next collection. Shown here only this once.
     */
    public function generateKey(Request $request, Application $application): RedirectResponse
    {
        $this->authorize('manage', ExecutiveSnapshot::class);
        abort_if(blank($application->feed_url), 422, 'Set the feed address first.');

        $key = Str::random(48);
        $application->forceFill(['feed_key' => $key])->save();

        $this->audit()->record(AuditEvent::ExecutiveFeedKeyGenerated, "Generated a new summary feed key for {$application->name}", $application);

        return back()
            ->with('feed_key', ['application_id' => $application->id, 'name' => $application->name, 'key' => $key])
            ->with('status', "New key for {$application->name}. Copy it now: it won't be shown again.");
    }

    public function saveTarget(SaveTargetRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $target = ExecutiveTarget::query()->firstOrNew(['metric' => $data['metric']]);
        $target->fill([...$data, 'note' => $data['note'] ?? null, 'updated_by' => $request->user()->id])->save();

        $this->audit()->record(AuditEvent::ExecutiveTargetSaved, "Set the target for {$data['metric']}", $target, [
            'target' => (float) $data['target'], 'direction' => $data['direction'], 'tolerance' => (int) $data['tolerance'],
        ]);

        return back()->with('status', 'Target saved.');
    }

    public function destroyTarget(ExecutiveTarget $target): RedirectResponse
    {
        $this->authorize('manage', ExecutiveSnapshot::class);

        $target->delete();
        $this->audit()->record(AuditEvent::ExecutiveTargetRemoved, "Removed the target for {$target->metric}", null, ['metric' => $target->metric]);

        return back()->with('status', 'Target removed.');
    }

    public function collect(MetricCollector $collector): RedirectResponse
    {
        $this->authorize('manage', ExecutiveSnapshot::class);

        $snapshots = collect($collector->collect());
        $failed = $snapshots->reject(fn (ExecutiveSnapshot $snapshot) => $snapshot->succeeded());

        $this->audit()->record(AuditEvent::ExecutiveCollected, 'Collected the Executive Dashboard figures', null, [
            'sources' => $snapshots->count(), 'failed' => $failed->pluck('source')->all(),
        ]);

        return back()->with('status', $failed->isEmpty()
            ? 'Figures collected from every source.'
            : "Figures collected; {$failed->count()} of {$snapshots->count()} sources failed (see their status).");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function feeds(): array
    {
        $applications = Application::query()->where('status', Status::Active)->orderBy('sort_order')->orderBy('name')->get();
        $latest = ExecutiveSnapshot::query()
            ->whereIn('id', ExecutiveSnapshot::query()->selectRaw('MAX(id)')->where('source', 'like', 'system:%')->groupBy('source'))
            ->get()->keyBy('source');

        return $applications->map(function (Application $application) use ($latest) {
            $snapshot = $latest->get(SystemFeedSource::keyFor($application));

            return [
                'id' => $application->id,
                'name' => $application->name,
                'feed_url' => $application->feed_url,
                'has_key' => filled($application->feed_key),
                'last' => $application->feed_url && $snapshot ? [
                    'status' => $snapshot->status,
                    'at' => $snapshot->collected_at->toIso8601String(),
                    'error' => $snapshot->error,
                ] : null,
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function target(?ExecutiveTarget $target): ?array
    {
        return $target ? [
            'id' => $target->id,
            'metric' => $target->metric,
            'value' => $target->target,
            'direction' => $target->direction,
            'tolerance' => $target->tolerance,
            'note' => $target->note,
            'updatedBy' => $target->updater?->name,
            'updatedAt' => $target->updated_at?->toIso8601String(),
        ] : null;
    }
}
