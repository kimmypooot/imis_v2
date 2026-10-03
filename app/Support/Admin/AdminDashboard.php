<?php

namespace App\Support\Admin;

use App\Enums\PermissionName;
use App\Enums\UserStatus;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\OrganizationalUnit;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * The legacy admin dashboard's numbers (admin/index.php + dashboard.js):
 * metric cards, the login trend, users per IMIS role / office, access per
 * system, top systems, users without access, and recent admin activity.
 *
 * Every section is computed only if the viewer may see the underlying
 * module, and every figure is a real count — a section the viewer can't
 * see is null, not zero.
 */
class AdminDashboard
{
    private const TREND_DAYS = 14;

    public function __construct(private readonly User $viewer) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $users = $this->can(PermissionName::UsersView);
        $systems = $this->can(PermissionName::ApplicationsView);
        $access = $systems || $this->can(PermissionName::ApplicationsManageAccess);
        $logs = $this->can(PermissionName::AuditLogsView);
        $sessions = $this->can(PermissionName::SessionsView);

        return [
            'generatedAt' => now()->toIso8601String(),
            'metrics' => [
                'users' => $users ? $this->userMetrics() : null,
                'systems' => $systems ? $this->systemMetrics() : null,
                'access' => $access && $users ? $this->accessMetrics() : null,
                'logins' => $logs || $sessions ? [
                    'today' => $logs ? LoginHistory::query()->where('logged_in_at', '>=', today())->count() : null,
                    'activeSessions' => $sessions ? $this->activeSessionCount() : null,
                ] : null,
            ],
            'loginTrend' => $logs ? $this->loginTrend() : null,
            'roleCounts' => $users ? $this->roleCounts() : null,
            'usersByOffice' => $users ? $this->usersByOffice() : null,
            'accessBySystem' => $access ? $this->accessBySystem() : null,
            'usersWithoutAccess' => $access && $users ? $this->usersWithoutAccess() : null,
            'recentActivity' => $logs ? $this->recentActivity() : null,
        ];
    }

    private function can(PermissionName $permission): bool
    {
        return $this->viewer->hasPermission($permission->value);
    }

    /**
     * @return array<string, int>
     */
    private function userMetrics(): array
    {
        $counts = User::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'active' => (int) ($counts[UserStatus::Active->value] ?? 0),
            'inactive' => (int) ($counts[UserStatus::Inactive->value] ?? 0),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function systemMetrics(): array
    {
        return [
            'total' => Application::query()->count(),
            'active' => Application::query()->active()->count(),
            'underMaintenance' => Application::query()->where('under_maintenance', true)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function accessMetrics(): array
    {
        $activeUsers = User::query()->where('status', UserStatus::Active->value)->count();
        $withAccess = User::query()->where('status', UserStatus::Active->value)->has('applicationAccess')->count();

        return [
            'grants' => ApplicationAccess::query()->count(),
            'usersWithAccess' => $withAccess,
            'usersWithoutAccess' => $activeUsers - $withAccess,
        ];
    }

    private function activeSessionCount(): ?int
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        return DB::table(config('session.table', 'sessions'))
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
            ->count();
    }

    /**
     * Sign-ins per day for the last 14 days, zero-filled.
     *
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function loginTrend(): array
    {
        $start = today()->subDays(self::TREND_DAYS - 1);

        $counts = LoginHistory::query()
            ->where('logged_in_at', '>=', $start)
            ->get(['logged_in_at'])
            ->countBy(fn (LoginHistory $login) => $login->logged_in_at->toDateString());

        $days = collect(CarbonPeriod::create($start, today()));

        return [
            'labels' => $days->map(fn ($day) => $day->format('M j'))->all(),
            'values' => $days->map(fn ($day) => $counts[$day->toDateString()] ?? 0)->all(),
        ];
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function roleCounts(): array
    {
        $roles = Role::query()->withCount('users')->orderByDesc('users_count')->orderBy('display_name')->get(['id', 'display_name']);
        $withoutRole = User::query()->doesntHave('roles')->count();

        $rows = $roles->map(fn (Role $role) => [$role->display_name, $role->users_count])
            ->push(['No IMIS role', $withoutRole])
            ->filter(fn (array $row) => $row[1] > 0)
            ->values();

        return ['labels' => $rows->pluck(0)->all(), 'values' => $rows->pluck(1)->all()];
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function usersByOffice(): array
    {
        $counts = User::query()
            ->select('organizational_unit_id', DB::raw('count(*) as total'))
            ->groupBy('organizational_unit_id')
            ->pluck('total', 'organizational_unit_id');
        $names = OrganizationalUnit::query()->whereKey($counts->keys()->filter()->all())->get(['id', 'name', 'short_name'])->keyBy('id');

        $rows = $counts
            ->map(fn ($total, $unitId) => [$unitId ? ($names[$unitId]?->short_name ?: $names[$unitId]?->name) : 'Not set', (int) $total])
            ->sortByDesc(1)
            ->values();

        return ['labels' => $rows->pluck(0)->all(), 'values' => $rows->pluck(1)->all()];
    }

    /**
     * Grants per system (top 12), plus the ranked table rows.
     *
     * @return array{labels: array<int, string>, values: array<int, int>, top: array<int, array<string, mixed>>}
     */
    private function accessBySystem(): array
    {
        $systems = Application::query()
            ->withCount('accessGrants')
            ->orderByDesc('access_grants_count')
            ->orderBy('name')
            ->limit(12)
            ->get(['id', 'name', 'short_name', 'status', 'under_maintenance']);

        return [
            'labels' => $systems->map(fn (Application $system) => $system->short_name ?: $system->name)->all(),
            'values' => $systems->pluck('access_grants_count')->all(),
            'top' => $systems->take(8)->map(fn (Application $system) => [
                'id' => $system->id,
                'name' => $system->name,
                'status' => $system->under_maintenance ? 'maintenance' : $system->status->value,
                'assigned' => $system->access_grants_count,
            ])->values()->all(),
        ];
    }

    /**
     * Active accounts with no system access at all (the legacy "no
     * access" table), so onboarding gaps are visible.
     *
     * @return array{total: int, users: array<int, array<string, mixed>>}
     */
    private function usersWithoutAccess(): array
    {
        $query = User::query()->where('status', UserStatus::Active->value)->doesntHave('applicationAccess');

        return [
            'total' => (clone $query)->count(),
            'users' => $query
                ->with(['organizationalUnit:id,name,short_name', 'roles:id,display_name'])
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name', 'position', 'organizational_unit_id'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'position' => $user->position,
                    'office' => $user->organizationalUnit?->short_name ?: $user->organizationalUnit?->name,
                    'roles' => $user->roles->pluck('display_name')->all(),
                ])
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(): array
    {
        return AuditLog::query()
            ->latest('created_at')
            ->latest('id')
            ->limit(8)
            ->get(['id', 'event', 'actor_label', 'description', 'created_at'])
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event->label(),
                'actor' => $log->actor_label ?? 'System',
                'description' => $log->description,
                'created_at' => $log->created_at->toIso8601String(),
            ])
            ->all();
    }
}
