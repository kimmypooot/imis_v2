<?php

namespace App\Support\Legacy;

use App\Enums\RoleName;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Keeps the legacy IMIS database in step with this platform while the old
 * PHP subsystems still run: they read people from users_cscro8 and their
 * roles from imis_system_access, keyed by the legacy user id. IMIS is the
 * source of truth; this only writes one way, legacy ← IMIS.
 *
 * A sync mirrors one whole account (profile, password hash, status, and
 * its access to every system the legacy side knows), so it is idempotent
 * and safe to repeat. Model events queue it after the response
 * (registerListeners), and `imis:sync-legacy` reconciles everything on a
 * schedule in case one failed.
 *
 * All legacy database access goes through this class, so where that
 * database lives (same server or not) is one connection setting.
 */
class LegacyBridge
{
    /** Account fields the legacy side keeps; a change to any triggers a sync. */
    private const SYNCED_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'sex', 'birthday', 'position', 'organizational_unit_id',
        'username', 'email', 'password', 'status',
    ];

    private static bool $paused = false;

    public function enabled(): bool
    {
        return (bool) config('legacy.bridge') && ! self::$paused;
    }

    public function connection(): ConnectionInterface
    {
        return DB::connection(config('legacy.connection'));
    }

    /**
     * Runs the callback without syncing anything — for the legacy import,
     * which reads from the legacy side and mustn't echo it back.
     */
    public static function withoutSyncing(callable $callback): mixed
    {
        $previous = self::$paused;
        self::$paused = true;

        try {
            return $callback();
        } finally {
            self::$paused = $previous;
        }
    }

    public function registerListeners(): void
    {
        ApplicationAccess::saved(fn (ApplicationAccess $access) => $this->queue($access->user_id));
        ApplicationAccess::deleted(fn (ApplicationAccess $access) => $this->queue($access->user_id));

        User::saved(function (User $user) {
            if ($user->wasRecentlyCreated || $user->wasChanged(self::SYNCED_FIELDS)) {
                $this->queue($user->id);
            }
        });
    }

    /** Syncs the account after the response, once per request. */
    public function queue(int $userId): void
    {
        if (! $this->enabled()) {
            return;
        }

        defer(function () use ($userId) {
            try {
                if ($user = User::query()->find($userId)) {
                    $this->sync($user);
                }
            } catch (Throwable $e) {
                // Never fails the admin action; `imis:sync-legacy` catches up.
                report($e);
            }
        }, "legacy-sync-{$userId}");
    }

    /**
     * Mirrors the account and its system access. Returns the legacy id.
     */
    public function sync(User $user): int
    {
        $db = $this->connection();

        return $db->transaction(function () use ($db, $user) {
            $legacyId = $this->syncAccount($db, $user);
            $this->syncAccess($db, $user, $legacyId);

            return $legacyId;
        });
    }

    /** The legacy users_cscro8.role for this account. */
    public function legacyRole(User $user, ?string $current): string
    {
        return match (true) {
            $user->hasRole(RoleName::SuperAdmin) => 'superadmin',
            // Legacy "admin" has no IMIS equivalent; it is kept, never granted.
            $current === 'admin' => 'admin',
            default => 'user',
        };
    }

    /** The account's current legacy role, or null if there's no legacy row. */
    public function currentLegacyRole(User $user): ?string
    {
        if (! $this->enabled() || ! $user->legacy_id) {
            return null;
        }

        return $this->connection()->table('users_cscro8')->where('id', $user->legacy_id)->value('role');
    }

    public static function middleInitial(?string $middleName): string
    {
        $middleName = trim((string) $middleName);

        return $middleName === '' ? '' : Str::upper(Str::substr($middleName, 0, 1)).'.';
    }

    private function syncAccount(ConnectionInterface $db, User $user): int
    {
        $existing = $user->legacy_id ? $db->table('users_cscro8')->where('id', $user->legacy_id)->first() : null;
        // An account made here before the bridge, or never imported: matched once by username.
        $existing ??= $db->table('users_cscro8')->where('username', $user->username)->first();
        $unit = $user->organizationalUnit;

        $row = [
            'fname' => $user->first_name ?? $existing->fname ?? $user->name,
            'lname' => $user->last_name ?? $existing->lname ?? '',
            'mname' => $user->middle_name ?? $existing->mname ?? '',
            'minitial' => $user->middle_name !== null ? self::middleInitial($user->middle_name) : ($existing->minitial ?? ''),
            'sex' => match ($user->sex) {
                'male' => 'Male', 'female' => 'Female', default => $existing->sex ?? ''
            },
            'username' => $user->username,
            // Laravel's bcrypt hashes verify with PHP's password_verify().
            'password' => $user->getAuthPassword(),
            // An account without an email here (a shared legacy address) keeps the legacy one.
            'email' => $user->email ?? $existing->email ?? '',
            'position' => $user->position ?? $existing->position ?? '',
            'fo_rsu' => $unit?->name ?? $existing->fo_rsu ?? '',
            'birthday' => $user->birthday?->toDateString() ?? $existing->birthday ?? '0000-00-00',
            'status' => $user->isActive() ? 'Active' : 'Inactive',
            'role' => $this->legacyRole($user, $existing->role ?? null),
        ];

        if ($existing) {
            $db->table('users_cscro8')->where('id', $existing->id)->update($row);
            $legacyId = (int) $existing->id;
        } else {
            $legacyId = (int) $db->table('users_cscro8')->insertGetId($row + [
                'first_day' => '0000-00-00',
                'exam_fo_id' => 0,
                'itg_id' => 0,
                // Legacy office key (OTRS location), e.g. "hrd".
                'type' => Str::limit(Str::lower((string) $unit?->code), 12, ''),
                'profile' => '',
            ]);
        }

        if ($user->legacy_id !== $legacyId) {
            $user->forceFill(['legacy_id' => $legacyId])->saveQuietly();
        }

        return $legacyId;
    }

    /**
     * Makes the account's legacy grants match its grants here, for every
     * system both sides know (matched by code: short_name = code_name).
     * Legacy grants to systems this platform doesn't have are left alone.
     */
    private function syncAccess(ConnectionInterface $db, User $user, int $legacyId): void
    {
        $projects = $db->table('imis_projects')
            ->whereIn('code_name', Application::query()->whereNotNull('short_name')->pluck('short_name'))
            ->pluck('id', 'code_name');

        $wanted = [];
        $grants = $user->applicationAccess()->with(['application:id,short_name,url', 'role:id,name,launch_url', 'grantedBy:id,legacy_id'])->get();

        foreach ($grants as $grant) {
            $projectId = $projects[$grant->application->short_name] ?? null;

            if ($projectId !== null) {
                $wanted[(int) $projectId] = [
                    'role_id' => $this->legacyRoleId($db, (int) $projectId, $grant->role, $grant->application),
                    'granted_by' => $grant->grantedBy?->legacy_id,
                ];
            }
        }

        $current = $db->table('imis_system_access')
            ->where('user_id', $legacyId)
            ->whereIn('project_id', $projects->values())
            ->pluck('role_id', 'project_id');

        $revoked = $current->keys()->map(fn ($id) => (int) $id)->diff(array_keys($wanted));
        if ($revoked->isNotEmpty()) {
            $db->table('imis_system_access')->where('user_id', $legacyId)->whereIn('project_id', $revoked)->delete();
        }

        foreach ($wanted as $projectId => $grant) {
            $currentRole = $current[$projectId] ?? null;

            if ($currentRole === null) {
                $db->table('imis_system_access')->insert(['user_id' => $legacyId, 'project_id' => $projectId] + $grant);
            } elseif ((int) $currentRole !== $grant['role_id']) {
                $db->table('imis_system_access')
                    ->where('user_id', $legacyId)->where('project_id', $projectId)
                    ->update($grant + ['granted_at' => now('Asia/Manila')->toDateTimeString()]);
            }
        }
    }

    /** The legacy role with this name, created if the role was added here. */
    private function legacyRoleId(ConnectionInterface $db, int $projectId, ApplicationRole $role, Application $application): int
    {
        $id = $db->table('imis_access_roles')->where('project_id', $projectId)->where('name', $role->name)->value('id');

        return (int) ($id ?? $db->table('imis_access_roles')->insertGetId([
            'project_id' => $projectId,
            'name' => $role->name,
            'project_url' => (string) ($role->launch_url ?: $application->url),
            'is_external' => 0,
        ]));
    }
}
