<?php

namespace App\Support\Executive\Sources;

use App\Enums\PermissionName;
use App\Support\System\HealthChecks;

/**
 * IMIS's own health, from the same checks as Admin → System Health:
 * how many fail and how many need attention, naming the failing checks
 * (labels only — never a detail that could hold a secret).
 */
class ImisHealthSource implements MetricSource
{
    public function __construct(private readonly HealthChecks $health) {}

    public function key(): string
    {
        return 'imis';
    }

    public function label(): string
    {
        return 'IMIS';
    }

    public function kind(): string
    {
        return 'module';
    }

    public function collect(): array
    {
        $checks = collect($this->health->run());
        $failing = $checks->where('status', 'fail');
        $link = ['href' => '/admin/system-health', 'permission' => PermissionName::SettingsView->value];

        return [
            [
                'key' => 'health.failing',
                'label' => 'Health checks failing',
                'value' => $failing->count(),
                'unit' => 'count',
                'note' => $failing->isEmpty() ? 'All checks pass' : $failing->pluck('label')->take(5)->join(', '),
                ...$link,
            ],
            ['key' => 'health.warnings', 'label' => 'Health checks needing attention', 'value' => $checks->where('status', 'warn')->count(), 'unit' => 'count', ...$link],
        ];
    }
}
