<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One source's figures at one collection (`imis:collect-metrics`): an IMIS
 * module, IMIS's own health, or a connected system's summary feed. Written
 * only by App\Support\Executive\MetricCollector; `metrics` is the list in
 * the feed format (MetricFeed), totals only — never a person's record.
 */
class ExecutiveSnapshot extends Model
{
    public const OK = 'ok';

    public const FAILED = 'failed';

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'metrics' => 'array',
        ];
    }

    public function scopeForSource(Builder $query, string $source): void
    {
        $query->where('source', $source);
    }

    public function succeeded(): bool
    {
        return $this->status === self::OK;
    }
}
