<?php

namespace App\Support\Executive\Sources;

use App\Enums\PermissionName;
use App\Enums\UserStatus;
use App\Models\LoginHistory;
use App\Models\SystemAccessLog;
use App\Models\User;

/**
 * How the IMIS itself is used over the last 30 days: sign-ins, how many
 * people signed in, and systems opened from the portal (by system).
 * Starter figures.
 */
class PortalUsageSource implements MetricSource
{
    public const DAYS = 30;

    public function key(): string
    {
        return 'portal';
    }

    public function label(): string
    {
        return 'IMIS portal usage';
    }

    public function kind(): string
    {
        return 'module';
    }

    public function collect(): array
    {
        $since = now()->subDays(self::DAYS);
        $period = 'Last '.self::DAYS.' days';
        $active = User::query()->where('status', UserStatus::Active)->count();
        $signedIn = LoginHistory::query()->where('logged_in_at', '>=', $since)->whereNotNull('user_id')->distinct()->count('user_id');
        $launches = SystemAccessLog::query()->where('accessed_at', '>=', $since)
            ->selectRaw('application_name, COUNT(*) as launches')->groupBy('application_name')
            ->orderByDesc('launches')->limit(50)->get();

        return [
            [
                'key' => 'portal.users_signed_in',
                'label' => 'Active accounts that signed in',
                'value' => $active ? round($signedIn / $active * 100, 1) : null,
                'unit' => 'percent',
                'period' => $period,
                'note' => "{$signedIn} of {$active} active accounts",
            ],
            [
                'key' => 'portal.sign_ins',
                'label' => 'Sign-ins',
                'value' => LoginHistory::query()->where('logged_in_at', '>=', $since)->count(),
                'unit' => 'count',
                'period' => $period,
                'href' => '/admin/login-history',
                'permission' => PermissionName::AuditLogsView->value,
            ],
            [
                'key' => 'portal.system_launches',
                'label' => 'Systems opened from the portal',
                'value' => (int) $launches->sum('launches'),
                'unit' => 'count',
                'period' => $period,
                'breakdown' => $launches->map(fn ($row) => ['label' => $row->application_name, 'value' => (int) $row->launches])->all(),
                'href' => '/admin/access-logs',
                'permission' => PermissionName::AuditLogsView->value,
            ],
        ];
    }
}
