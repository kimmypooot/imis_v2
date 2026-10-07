<?php

namespace App\Support\Executive;

use App\Enums\Status;
use App\Models\Application;
use App\Models\ExecutiveSnapshot;
use App\Models\ExecutiveTarget;
use App\Support\Executive\Sources\HumanResourcesSource;
use App\Support\Executive\Sources\ImisHealthSource;
use App\Support\Executive\Sources\LearningSource;
use App\Support\Executive\Sources\LeaveSource;
use App\Support\Executive\Sources\MetricSource;
use App\Support\Executive\Sources\PayrollSource;
use App\Support\Executive\Sources\PortalUsageSource;
use App\Support\Executive\Sources\SystemFeedSource;
use App\Support\Settings\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Exec-1/2: collects every source's figures and saves them as snapshots
 * (`imis:collect-metrics`, every 30 minutes). The Executive Dashboard
 * only reads snapshots, so it loads fast and a system that is down shows
 * its last figures with their age instead of breaking the page.
 *
 * One source failing never stops the others; it is saved as a failed
 * snapshot with the reason. History is thinned to one snapshot a day
 * after two days, and deleted after the retention period (System
 * Settings → Executive Dashboard).
 */
class MetricCollector
{
    /** Snapshots newer than this are all kept; older ones, one a day. */
    public const FULL_HISTORY_DAYS = 2;

    /** IMIS's own sources, in the order the dashboard shows them. */
    private const MODULES = [
        HumanResourcesSource::class,
        LeaveSource::class,
        LearningSource::class,
        PayrollSource::class,
        PortalUsageSource::class,
        ImisHealthSource::class,
    ];

    public function __construct(private readonly MetricAlerts $alerts) {}

    /**
     * @return array<int, MetricSource>
     */
    public function sources(): array
    {
        return [...$this->modules(), ...$this->systems()];
    }

    /**
     * @return array<int, MetricSource>
     */
    public function modules(): array
    {
        return array_map(fn (string $class) => app($class), self::MODULES);
    }

    /**
     * Active systems with a summary feed set.
     *
     * @return array<int, SystemFeedSource>
     */
    public function systems(): array
    {
        return Application::query()->where('status', Status::Active)->whereNotNull('feed_url')
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (Application $application) => new SystemFeedSource($application))
            ->all();
    }

    /**
     * @return array<int, ExecutiveSnapshot>
     */
    public function collect(): array
    {
        $targets = ExecutiveTarget::query()->get()->keyBy('metric');

        return array_map(fn (MetricSource $source) => $this->collectSource($source, $targets), $this->sources());
    }

    /**
     * @param  Collection<string, ExecutiveTarget>|null  $targets
     */
    public function collectSource(MetricSource $source, $targets = null): ExecutiveSnapshot
    {
        $previous = ExecutiveSnapshot::query()->forSource($source->key())->latest('collected_at')->latest('id')->first();

        try {
            $metrics = MetricFeed::clean($source->collect(), allowLinks: $source->kind() === 'module');
            $attributes = ['status' => ExecutiveSnapshot::OK, 'metrics' => $metrics];
        } catch (Throwable $e) {
            report($e);
            $attributes = ['status' => ExecutiveSnapshot::FAILED, 'error' => Str::limit($e->getMessage(), 250)];
        }

        $snapshot = ExecutiveSnapshot::query()->create([
            'source' => $source->key(),
            'collected_at' => now(),
            ...$attributes,
        ]);

        $this->alerts->compare($source, $previous, $snapshot, $targets ?? ExecutiveTarget::query()->get()->keyBy('metric'));

        return $snapshot;
    }

    /**
     * Thins and expires history. Returns how many snapshots were deleted.
     */
    public function prune(): int
    {
        $deleted = 0;
        $cutoff = now()->subDays(self::FULL_HISTORY_DAYS);

        // Older than two days: keep each source's last good snapshot of each day.
        $keep = ExecutiveSnapshot::query()
            ->where('collected_at', '<', $cutoff)->where('status', ExecutiveSnapshot::OK)
            ->selectRaw('MAX(id) as id')
            ->groupBy('source', DB::raw('DATE(collected_at)'))
            ->pluck('id');

        $deleted += ExecutiveSnapshot::query()->where('collected_at', '<', $cutoff)->whereNotIn('id', $keep)->delete();

        $months = (int) Settings::get('retention_executive_history_months');
        if ($months > 0) {
            $deleted += ExecutiveSnapshot::query()->where('collected_at', '<', now()->subMonths($months))->delete();
        }

        return $deleted;
    }
}
