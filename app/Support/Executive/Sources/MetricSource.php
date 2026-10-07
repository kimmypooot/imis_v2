<?php

namespace App\Support\Executive\Sources;

/**
 * One place the Executive Dashboard gets figures from: an IMIS module,
 * IMIS's own health, or a connected system's summary feed. `collect()`
 * returns figures in the MetricFeed shape (totals only) and may throw;
 * the collector records that as a failed collection for this source.
 */
interface MetricSource
{
    /** Stable key the snapshots are stored under, e.g. "hr" or "system:5". */
    public function key(): string;

    public function label(): string;

    /** "module" (inside IMIS) or "system" (a connected system). */
    public function kind(): string;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function collect(): array;
}
