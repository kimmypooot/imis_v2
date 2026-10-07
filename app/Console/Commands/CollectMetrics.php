<?php

namespace App\Console\Commands;

use App\Models\ExecutiveSnapshot;
use App\Support\Executive\MetricCollector;
use Illuminate\Console\Command;

/**
 * Exec-1/2: collects every Executive Dashboard source — IMIS's modules,
 * its health, and connected systems' summary feeds — then thins old
 * history. Scheduled every 30 minutes in routes/console.php. Fails (exit
 * code 1) only when every source failed.
 */
class CollectMetrics extends Command
{
    protected $signature = 'imis:collect-metrics {--no-prune : Keep history as it is}';

    protected $description = 'Collect the Executive Dashboard figures from IMIS and connected systems';

    public function handle(MetricCollector $collector): int
    {
        $snapshots = $collector->collect();

        foreach ($snapshots as $snapshot) {
            $snapshot->succeeded()
                ? $this->info("{$snapshot->source}: ".count($snapshot->metrics ?? []).' figures')
                : $this->warn("{$snapshot->source}: failed — {$snapshot->error}");
        }

        if (! $this->option('no-prune')) {
            $this->line('History: '.$collector->prune().' old snapshots removed');
        }

        $failed = collect($snapshots)->reject(fn (ExecutiveSnapshot $snapshot) => $snapshot->succeeded())->count();

        return $snapshots !== [] && $failed === count($snapshots) ? self::FAILURE : self::SUCCESS;
    }
}
