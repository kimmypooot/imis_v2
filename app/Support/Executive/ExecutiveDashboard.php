<?php

namespace App\Support\Executive;

use App\Enums\Status;
use App\Models\Application;
use App\Models\ExecutiveSnapshot;
use App\Models\ExecutiveTarget;
use App\Models\User;
use App\Support\Executive\Sources\MetricSource;
use App\Support\Executive\Sources\SystemFeedSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the Executive Dashboard shows, built only from saved snapshots
 * (MetricCollector) plus each system's maintenance flag:
 * - `systems`: one status tile per active system, IMIS first;
 * - `sections`: each source's figures with their trend over the last
 *   30 days, the change since then, and where each stands against its
 *   target.
 * Links into a module are kept only for viewers with that module's
 * permission. Totals only, so viewing isn't audited.
 */
class ExecutiveDashboard
{
    public const TREND_DAYS = 30;

    /** Figures older than this are flagged as not current (collection runs every 30 minutes). */
    public const STALE_MINUTES = 120;

    /** @var Collection<string, Collection<int, ExecutiveSnapshot>>|null */
    private ?Collection $history = null;

    /** @var Collection<string, ExecutiveSnapshot>|null */
    private ?Collection $latest = null;

    public function __construct(
        private readonly User $viewer,
        private readonly MetricCollector $collector,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $targets = ExecutiveTarget::query()->get()->keyBy('metric');
        $sources = $this->collector->sources();

        $built = [
            'systems' => $this->systems(),
            'sections' => collect($sources)
                ->map(fn (MetricSource $source) => $this->section($source, $targets))
                ->values()->all(),
            'lastCollectedAt' => $this->latest()->max('collected_at')?->toIso8601String(),
        ];

        return [...$built, 'summary' => self::summarize($built)];
    }

    /**
     * Counts for the top of the page and the weekly summary.
     *
     * @param  array<string, mixed>  $built  what build() returned
     * @return array<string, int>
     */
    public static function summarize(array $built): array
    {
        $metrics = collect($built['sections'])->flatMap(fn (array $section) => $section['metrics']);

        return [
            'systems' => count($built['systems']),
            'systemsNeedingAttention' => collect($built['systems'])->whereNotIn('state', ['ok', 'not_connected'])->count(),
            'onTrack' => $metrics->where('status', TargetStatus::ON_TRACK)->count(),
            'atRisk' => $metrics->where('status', TargetStatus::AT_RISK)->count(),
            'behind' => $metrics->where('status', TargetStatus::BEHIND)->count(),
            'withTargets' => $metrics->whereNotNull('target')->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function systems(): array
    {
        $tiles = [$this->imisTile()];

        $applications = Application::query()->where('status', Status::Active)
            ->orderBy('sort_order')->orderBy('name')->get();

        foreach ($applications as $application) {
            $tiles[] = $this->systemTile($application);
        }

        return $tiles;
    }

    /**
     * @return array<string, mixed>
     */
    private function imisTile(): array
    {
        $snapshot = $this->latest()->get('imis');
        $metrics = collect($snapshot?->succeeded() ? $snapshot->metrics : [])->keyBy('key');
        $failing = (int) ($metrics['health.failing']['value'] ?? 0);
        $warnings = (int) ($metrics['health.warnings']['value'] ?? 0);

        [$state, $detail] = match (true) {
            $snapshot === null => ['waiting', 'Waiting for the first collection'],
            ! $snapshot->succeeded() => ['failing', 'Health checks could not run'],
            $failing > 0 => ['down', $metrics['health.failing']['note'] ?? "{$failing} checks failing"],
            $warnings > 0 => ['attention', $warnings === 1 ? '1 check needs attention' : "{$warnings} checks need attention"],
            default => ['ok', 'All health checks pass'],
        };

        return $this->tile('imis', 'IMIS', 'shield', $state, $detail, $snapshot?->collected_at);
    }

    /**
     * @return array<string, mixed>
     */
    private function systemTile(Application $application): array
    {
        $snapshot = $application->feed_url ? $this->latest()->get(SystemFeedSource::keyFor($application)) : null;

        [$state, $detail] = match (true) {
            $application->under_maintenance => ['maintenance', $application->maintenance_message ?: 'Under maintenance'],
            ! $application->feed_url => ['not_connected', 'No summary feed yet'],
            $snapshot === null => ['waiting', 'Waiting for the first collection'],
            ! $snapshot->succeeded() => ['failing', $this->failingSince(SystemFeedSource::keyFor($application))],
            $this->isStale($snapshot->collected_at) => ['stale', 'Figures are not current'],
            default => ['ok', 'Figures up to date'],
        };

        return $this->tile("system:{$application->id}", $application->short_name ?: $application->name, $application->icon ?: 'grid', $state, $detail, $snapshot?->collected_at, $application->name);
    }

    /**
     * @return array<string, mixed>
     */
    private function tile(string $key, string $name, string $icon, string $state, string $detail, ?Carbon $updatedAt, ?string $fullName = null): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'fullName' => $fullName ?? $name,
            'icon' => $icon,
            'state' => $state,
            'detail' => $detail,
            'updatedAt' => $updatedAt?->toIso8601String(),
        ];
    }

    private function failingSince(string $source): string
    {
        $lastGood = $this->history()->get($source, collect())->last();

        return $lastGood
            ? 'Not answering; last figures from '.$lastGood->collected_at->timezone('Asia/Manila')->format('M j, g:i A')
            : 'Not answering yet';
    }

    /**
     * @param  Collection<string, ExecutiveTarget>  $targets
     * @return array<string, mixed>|null
     */
    private function section(MetricSource $source, Collection $targets): array
    {
        $latest = $this->latest()->get($source->key());
        $good = $this->history()->get($source->key(), collect());
        $current = $good->last();

        if ($latest === null) {
            return [
                'key' => $source->key(), 'label' => $source->label(), 'kind' => $source->kind(),
                'state' => 'waiting', 'collectedAt' => null, 'error' => null, 'metrics' => [],
            ];
        }

        $state = match (true) {
            ! $latest->succeeded() => 'failing',
            $this->isStale($latest->collected_at) => 'stale',
            default => 'ok',
        };

        $series = $this->dailySeries($good);

        return [
            'key' => $source->key(),
            'label' => $source->label(),
            'kind' => $source->kind(),
            'state' => $state,
            'collectedAt' => $current?->collected_at->toIso8601String(),
            'error' => $latest->succeeded() ? null : $latest->error,
            'metrics' => collect($current?->metrics ?? [])
                ->map(fn (array $metric) => $this->metric($source->key(), $metric, $series, $targets))
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $metric
     * @param  Collection<string, ExecutiveSnapshot>  $series  last good snapshot per day
     * @param  Collection<string, ExecutiveTarget>  $targets
     * @return array<string, mixed>
     */
    private function metric(string $source, array $metric, Collection $series, Collection $targets): array
    {
        $key = TargetStatus::key($source, $metric['key']);
        $target = $targets->get($key);
        $trend = $series->map(fn (ExecutiveSnapshot $snapshot, string $day) => [
            'date' => $day,
            'value' => collect($snapshot->metrics)->firstWhere('key', $metric['key'])['value'] ?? null,
        ])->values();
        $first = $trend->first(fn (array $point) => $point['value'] !== null);
        $visible = isset($metric['href']) && ($metric['permission'] === null || $this->viewer->hasPermission($metric['permission']));

        return [
            'id' => $key,
            'key' => $metric['key'],
            'label' => $metric['label'],
            'value' => $metric['value'],
            'unit' => $metric['unit'],
            'period' => $metric['period'],
            'note' => $metric['note'],
            'breakdown' => $metric['breakdown'],
            'href' => $visible ? $metric['href'] : null,
            'trend' => $trend->count() > 1 ? $trend->all() : [],
            'change' => $first && $metric['value'] !== null && $trend->count() > 1
                ? ['since' => $first['date'], 'value' => round($metric['value'] - $first['value'], 2)]
                : null,
            'target' => $target ? [
                'value' => $target->target,
                'direction' => $target->direction,
                'tolerance' => $target->tolerance,
                'note' => $target->note,
            ] : null,
            'status' => TargetStatus::of($metric['value'], $target),
        ];
    }

    /**
     * The last good snapshot of each day, oldest first.
     *
     * @param  Collection<int, ExecutiveSnapshot>  $good
     * @return Collection<string, ExecutiveSnapshot>
     */
    private function dailySeries(Collection $good): Collection
    {
        return $good->groupBy(fn (ExecutiveSnapshot $snapshot) => $snapshot->collected_at->timezone('Asia/Manila')->toDateString())
            ->map(fn (Collection $day) => $day->last());
    }

    private function isStale(Carbon $at): bool
    {
        return $at->lt(now()->subMinutes(self::STALE_MINUTES));
    }

    /**
     * Each source's latest snapshot, good or failed.
     *
     * @return Collection<string, ExecutiveSnapshot>
     */
    private function latest(): Collection
    {
        return $this->latest ??= ExecutiveSnapshot::query()
            ->whereIn('id', ExecutiveSnapshot::query()->selectRaw('MAX(id)')->groupBy('source'))
            ->get()->keyBy('source');
    }

    /**
     * Each source's good snapshots over the trend window, oldest first.
     *
     * @return Collection<string, Collection<int, ExecutiveSnapshot>>
     */
    private function history(): Collection
    {
        return $this->history ??= ExecutiveSnapshot::query()
            ->where('status', ExecutiveSnapshot::OK)
            ->where('collected_at', '>=', now()->subDays(self::TREND_DAYS))
            ->orderBy('collected_at')->orderBy('id')
            ->get()->groupBy('source');
    }
}
