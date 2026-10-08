<?php

namespace App\Support\Legacy;

use App\Enums\AnnouncementStatus;
use App\Enums\RoleName;
use App\Enums\Status;
use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Enums\Visibility;
use App\Models\Announcement;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\Changelog;
use App\Models\Committee;
use App\Models\LoginHistory;
use App\Models\OrganizationalUnit;
use App\Models\Role;
use App\Models\SystemAccessLog;
use App\Models\User;
use App\Support\PermissionCache;
use App\Support\ProfilePhotos;
use App\Support\Usernames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Carries the legacy IMIS database (old-imis/db_imis.sql) into this
 * platform: offices and committees (dashboard divisions), systems and their
 * roles, user accounts, system access, announcements, release notes, and
 * the sign-in and system-access history.
 *
 * Safe to re-run: records are matched (users by username, systems by code,
 * releases by version, history by user + time), so nothing is duplicated.
 * The legacy data is authoritative for the systems it defines; accounts
 * that already exist here keep their password, status and roles.
 *
 * Legacy DATETIME columns hold Asia/Manila wall-clock time (the legacy app
 * set it in PHP); TIMESTAMP columns are true UTC when read over a UTC
 * session. Both are stored here in the app's time zone (Asia/Manila).
 */
class LegacyImporter
{
    private const LEGACY_TIMEZONE = 'Asia/Manila';

    /**
     * Legacy systems (by code name) built into IMIS and deleted here, so a
     * re-import never brings their launchers back. Their grants are skipped.
     */
    public const RETIRED_SYSTEMS = ['PRISM'];

    /**
     * Bootstrap Icons used by the legacy systems → the closest Icon.vue name.
     */
    private const ICONS = [
        'person' => 'user', 'gender' => 'users', 'journal' => 'clipboard', 'book' => 'clipboard',
        'file' => 'clipboard', 'pc-display' => 'desktop', 'motherboard' => 'desktop',
        'calendar' => 'calendar', 'briefcase' => 'building', 'cash' => 'database',
        'graph' => 'activity', 'search' => 'search', 'fuel' => 'cog',
    ];

    /** @var array<int, int> legacy user id → user id */
    private array $users = [];

    /** @var array<int, Application> legacy project id → system */
    private array $systems = [];

    /** @var array<int, ApplicationRole> legacy role id → system role */
    private array $roles = [];

    /** @var array<int, OrganizationalUnit|Committee> legacy division id → office or committee */
    private array $divisions = [];

    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(
        private readonly ConnectionInterface $legacy,
        private readonly string $legacyUrl,
        private readonly ?string $photoDirectory = null,
        private readonly bool $withHistory = true,
    ) {}

    /**
     * @return array<string, int> what was created or updated, by kind
     */
    public function run(): array
    {
        // What comes from the legacy database is never echoed back to it.
        LegacyBridge::withoutSyncing(fn () => DB::transaction(function () {
            $this->importDivisions();
            $this->importSystems();
            $this->importUsers();
            $this->importAccess();
            $this->importAnnouncements();
            $this->importChangelogs();

            if ($this->withHistory) {
                $this->importLoginHistory();
                $this->importSystemAccessLogs();
            }
        }));

        // Files aren't transactional, so photos go in once the rows are committed.
        $this->importPhotos();
        PermissionCache::flush();

        return $this->counts;
    }

    /**
     * Dashboard divisions: "Regional Support Units" cards are the offices
     * already seeded (matched by the code in parentheses, then by name);
     * "Committees / Groups" cards become committees.
     */
    private function importDivisions(): void
    {
        $categoryTypes = $this->legacy->table('imis_dash_categories')->pluck('type', 'id');

        foreach ($this->legacy->table('imis_dash_divisions')->orderBy('id')->get() as $division) {
            [$name, $code] = $this->splitCode($division->name);
            $status = $division->is_active ? Status::Active : Status::Inactive;
            $description = $this->text($division->description);

            if (($categoryTypes[$division->category_id] ?? null) === 'committee') {
                $committee = Committee::query()
                    ->where(fn ($query) => $query->where('name', $name)->when($code, fn ($query) => $query->orWhere('short_name', $code)))
                    ->first();

                if (! $committee) {
                    $committee = Committee::query()->create([
                        'name' => $name, 'short_name' => $code, 'description' => $description, 'status' => $status,
                    ]);
                    $this->count('committees_created');
                }

                $this->divisions[$division->id] = $committee;

                continue;
            }

            $unit = OrganizationalUnit::query()
                ->where(fn ($query) => $query->where('name', $name)->when($code, fn ($query) => $query->orWhere('code', $code)))
                ->first();

            if (! $unit) {
                $unit = OrganizationalUnit::query()->create([
                    'code' => $code, 'name' => $name, 'short_name' => $code,
                    'slug' => OrganizationalUnit::generateUniqueSlug($name),
                    'unit_type' => UnitType::RegionalSupportUnit, 'description' => $description, 'status' => $status,
                ]);
                $this->count('offices_created');
            } elseif ($unit->description === null && $description !== null) {
                $unit->update(['description' => $description]);
            }

            $this->divisions[$division->id] = $unit;
        }
    }

    private function importSystems(): void
    {
        $roles = $this->legacy->table('imis_access_roles')->orderBy('id')->get()->groupBy('project_id');
        $placements = $this->legacy->table('imis_dash_division_systems')
            ->orderBy('display_order')->orderBy('id')->get()->groupBy('project_id');

        foreach ($this->legacy->table('imis_projects')->orderBy('id')->get()->values() as $index => $project) {
            if (in_array($project->code_name, self::RETIRED_SYSTEMS, true)) {
                $this->count('systems_retired');

                continue;
            }

            $system = Application::query()->where('short_name', $project->code_name)->first() ?? new Application;
            $this->count($system->exists ? 'systems_updated' : 'systems_created');

            $active = collect($placements[$project->id] ?? [])->where('is_active', 1);
            $places = $active->map(fn ($placement) => $this->divisions[$placement->division_id] ?? null)->filter()->values();
            $managingUnit = $places->first(fn ($place) => $place instanceof OrganizationalUnit);

            $system->fill([
                'name' => $project->project_name,
                'short_name' => $project->code_name,
                'description' => $this->text($project->description),
                'icon' => $this->icon($project->icon),
                // Where a person with no role lands; null = no guest access.
                'url' => $this->absoluteUrl($project->guest_url),
                'external' => true,
                'featured' => false,
                'status' => $project->is_active ? Status::Active : Status::Inactive,
                'visibility' => Visibility::Authenticated,
                // Non-public legacy systems show as "No Access" to people without a role.
                'requires_access' => ! $project->is_public,
                'sort_order' => $index + 1,
                'organizational_unit_id' => $managingUnit?->id,
            ])->save();

            $system->listedUnits()->sync(
                $places->filter(fn ($place) => $place instanceof OrganizationalUnit && $place->isNot($managingUnit))->pluck('id'),
            );
            $system->listedCommittees()->sync($places->filter(fn ($place) => $place instanceof Committee)->pluck('id'));

            if (collect($placements[$project->id] ?? [])->contains('is_maintenance', 1) && ! $system->under_maintenance) {
                $system->startMaintenance(null);
            }

            $this->importRoles($system, collect($roles[$project->id] ?? []));
            $this->systems[$project->id] = $system;
        }
    }

    /**
     * @param  Collection<int, object>  $legacyRoles
     */
    private function importRoles(Application $system, Collection $legacyRoles): void
    {
        foreach ($legacyRoles->values() as $order => $legacyRole) {
            $role = $system->roles()->where('name', $legacyRole->name)->first() ?? $system->roles()->make();
            $role->fill([
                'name' => $legacyRole->name,
                'launch_url' => $this->absoluteUrl($legacyRole->project_url),
                'status' => Status::Active,
                'sort_order' => $order,
            ])->save();

            $this->roles[$legacyRole->id] = $role;
        }

        // Roles the legacy system doesn't define are retired, never deleted
        // (Phase 15); one somebody still holds is left alone.
        $system->roles()
            ->whereNotIn('id', collect($this->roles)->pluck('id'))
            ->whereDoesntHave('accessGrants')
            ->update(['status' => Status::Inactive]);
    }

    private function importUsers(): void
    {
        $legacyUsers = $this->legacy->table('users_cscro8')->orderBy('id')->get();
        $units = OrganizationalUnit::query()->pluck('id', 'name');
        $superAdmin = Role::query()->where('name', RoleName::SuperAdmin->value)->value('id');
        $emailOwners = $this->emailOwners($legacyUsers);

        foreach ($legacyUsers as $legacyUser) {
            $username = Usernames::normalize($legacyUser->username);
            $user = User::query()->where('username', $username)->first();
            $emailKey = Str::lower(trim($legacyUser->email));
            $email = (int) ($emailOwners[$emailKey] ?? 0) === (int) $legacyUser->id
                && ! User::query()->where('email', trim($legacyUser->email))->when($user, fn ($query) => $query->whereKeyNot($user->id))->exists()
                ? trim($legacyUser->email)
                : null;

            $profile = [
                'first_name' => $this->text($legacyUser->fname),
                'middle_name' => $this->text($legacyUser->mname),
                'last_name' => $this->text($legacyUser->lname),
                'sex' => match ($legacyUser->sex) {
                    'Male' => 'male', 'Female' => 'female', default => null
                },
                'birthday' => $this->date($legacyUser->birthday),
                'position' => $this->text($legacyUser->position),
                'organizational_unit_id' => $units[trim($legacyUser->fo_rsu)] ?? null,
            ];

            if ($user) {
                // An account made here first keeps its own details; only gaps are filled.
                $user->forceFill(array_filter(
                    $profile + ['email' => $email],
                    fn ($value, $field) => $value !== null && blank($user->getAttribute($field)),
                    ARRAY_FILTER_USE_BOTH,
                ))->save();
                $this->count('users_matched');
            } else {
                if (! preg_match(Usernames::PATTERN, $username) || strlen($username) < 3) {
                    $username = Usernames::generateFromParts((string) $profile['first_name'], $profile['middle_name'], (string) $profile['last_name']);
                }

                $user = (new User)->forceFill($profile + [
                    'username' => $username,
                    'email' => $email,
                    'status' => strcasecmp(trim($legacyUser->status), 'Active') === 0 ? UserStatus::Active : UserStatus::Inactive,
                    'last_login_at' => $this->manila($legacyUser->last_login),
                ]);
                // Legacy bcrypt hashes verify as-is; set raw so the hashed cast never re-hashes them.
                $user->setRawAttributes(['password' => $legacyUser->password] + $user->getAttributes());
                $user->save();
                $this->count('users_created');

                if ($email === null) {
                    $this->count('users_without_email');
                }
            }

            // The subsystems know this person by the legacy id (LegacyBridge, LegacySso).
            if ($user->legacy_id !== (int) $legacyUser->id && ! User::query()->where('legacy_id', $legacyUser->id)->whereKeyNot($user->id)->exists()) {
                $user->forceFill(['legacy_id' => (int) $legacyUser->id])->save();
            }

            if ($legacyUser->role === 'superadmin' && $superAdmin) {
                $user->roles()->syncWithoutDetaching([$superAdmin]);
            }

            $this->users[(int) $legacyUser->id] = $user->id;
        }
    }

    /**
     * Who keeps each email address the legacy accounts share: the account
     * that signed in most recently.
     *
     * @param  Collection<int, object>  $legacyUsers
     * @return array<string, int> lowercased email → legacy user id
     */
    private function emailOwners(Collection $legacyUsers): array
    {
        $lastSignIn = $this->legacy->table('imis_history_login_logs')
            ->selectRaw('user_id, MAX(login_time) as last_sign_in')
            ->groupBy('user_id')
            ->pluck('last_sign_in', 'user_id');

        return $legacyUsers
            ->filter(fn ($user) => trim($user->email) !== '')
            ->groupBy(fn ($user) => Str::lower(trim($user->email)))
            ->map(fn (Collection $owners) => $owners
                ->sortByDesc(fn ($user) => max((string) ($lastSignIn[$user->id] ?? ''), (string) $user->last_login))
                ->first()->id)
            ->all();
    }

    private function importAccess(): void
    {
        foreach ($this->legacy->table('imis_system_access')->orderBy('id')->get() as $grant) {
            $userId = $this->user($grant->user_id);
            $system = $this->systems[$grant->project_id] ?? null;
            $role = $this->roles[$grant->role_id] ?? null;

            if (! $userId || ! $system || ! $role || $role->application_id !== $system->id) {
                $this->count('access_skipped');

                continue;
            }

            $access = ApplicationAccess::query()->where('application_id', $system->id)->where('user_id', $userId)->first()
                ?? (new ApplicationAccess)->forceFill([
                    'application_id' => $system->id,
                    'user_id' => $userId,
                    'created_at' => $this->utc($grant->granted_at),
                ]);

            $access->forceFill([
                'application_role_id' => $role->id,
                'granted_by' => $this->user($grant->granted_by),
            ])->save();
            $this->count('access_grants');
        }
    }

    private function importAnnouncements(): void
    {
        foreach ($this->legacy->table('imis_announcements')->orderBy('id')->get() as $legacyAnnouncement) {
            $publishAt = $this->manila($legacyAnnouncement->scheduled_at) ?? $this->utc($legacyAnnouncement->created_at);

            if (Announcement::query()->where('title', $legacyAnnouncement->title)->where('publish_at', $publishAt)->exists()) {
                continue;
            }

            $announcement = new Announcement([
                'title' => $legacyAnnouncement->title,
                'body' => str_replace("\r\n", "\n", $legacyAnnouncement->message),
                'publish_at' => $publishAt,
                'expires_at' => $this->manila($legacyAnnouncement->expires_at),
                'featured' => $legacyAnnouncement->type === 'critical',
            ]);
            $announcement->forceFill([
                'slug' => Announcement::generateUniqueSlug($legacyAnnouncement->title),
                'status' => $legacyAnnouncement->is_active ? AnnouncementStatus::Published : AnnouncementStatus::Archived,
                'author_id' => $this->user($legacyAnnouncement->created_by),
                'created_at' => $this->utc($legacyAnnouncement->created_at),
                'updated_at' => $this->utc($legacyAnnouncement->updated_at),
            ])->save();
            $this->count('announcements');
        }
    }

    private function importChangelogs(): void
    {
        $items = $this->legacy->table('imis_changelog_items')->orderBy('sort_order')->orderBy('id')->get()->groupBy('changelog_id');

        foreach ($this->legacy->table('imis_changelog')->orderBy('id')->get() as $release) {
            if (Changelog::query()->where('version', $release->version)->exists()) {
                continue;
            }

            $changelog = new Changelog([
                'version' => $release->version,
                'title' => $release->title,
                'release_date' => $this->date($release->release_date),
            ]);
            $changelog->forceFill([
                'status' => $release->is_published ? Changelog::STATUS_PUBLISHED : Changelog::STATUS_DRAFT,
                'published_at' => $release->is_published ? $this->utc($release->updated_at) : null,
                'created_by' => $this->user($release->created_by),
                'created_at' => $this->utc($release->created_at),
                'updated_at' => $this->utc($release->updated_at),
            ])->save();

            foreach (collect($items[$release->id] ?? [])->values() as $order => $item) {
                $changelog->items()->create(['type' => $item->item_type, 'description' => $item->description, 'sort_order' => $order]);
            }

            $this->count('changelogs');
        }
    }

    /**
     * Legacy session ids aren't carried over: they can't match a session
     * here, and a closed legacy session must never look live.
     */
    private function importLoginHistory(): void
    {
        $existing = LoginHistory::query()->get(['username', 'logged_in_at'])
            ->mapWithKeys(fn ($row) => [$row->username.'|'.$row->logged_in_at->toDateTimeString() => true]);

        $this->legacy->table('imis_history_login_logs')->orderBy('id')->chunk(500, function ($rows) use ($existing) {
            $insert = [];

            foreach ($rows as $row) {
                $username = Str::limit(Usernames::normalize($row->username), 64, '');
                $loggedInAt = $this->manila($row->login_time);

                if (! $loggedInAt || isset($existing[$username.'|'.$loggedInAt->toDateTimeString()])) {
                    continue;
                }

                $insert[] = [
                    'user_id' => $this->user($row->user_id),
                    'username' => $username,
                    'session_id' => null,
                    'ip_address' => $this->text($row->ip_address),
                    'user_agent' => $this->text(Str::limit((string) $row->user_agent, 500, '')),
                    'logged_in_at' => $loggedInAt,
                    // A timed-out or abandoned legacy session has no sign-out; it reads as expired.
                    'logged_out_at' => $this->manila($row->logout_time),
                    'ended_by' => $row->status === 'logged_out' ? 'user' : null,
                ];
            }

            LoginHistory::query()->insert($insert);
            $this->count('login_history', count($insert));
        });
    }

    private function importSystemAccessLogs(): void
    {
        $existing = SystemAccessLog::query()->get(['username', 'application_name', 'accessed_at'])
            ->mapWithKeys(fn ($row) => [$row->username.'|'.$row->application_name.'|'.$row->accessed_at->toDateTimeString() => true]);

        $this->legacy->table('imis_history_access_logs as access')
            ->leftJoin('imis_history_login_logs as login', 'login.id', '=', 'access.login_log_id')
            ->leftJoin('users_cscro8 as account', 'account.id', '=', 'access.user_id')
            ->select([
                'access.id', 'access.user_id', 'access.project_id', 'access.project_code', 'access.role_name', 'access.accessed_at',
                'login.ip_address', 'login.user_agent', 'login.username as session_username', 'account.username',
            ])
            ->orderBy('access.id')
            ->chunk(500, function ($rows) use ($existing) {
                $insert = [];

                foreach ($rows as $row) {
                    $username = Str::limit(Usernames::normalize($row->username ?? $row->session_username), 64, '');
                    $system = $this->systems[$row->project_id] ?? null;
                    $applicationName = $system?->name ?? $row->project_code;
                    $accessedAt = $this->manila($row->accessed_at);

                    if (! $accessedAt || isset($existing[$username.'|'.$applicationName.'|'.$accessedAt->toDateTimeString()])) {
                        continue;
                    }

                    $insert[] = [
                        'user_id' => $this->user($row->user_id),
                        'username' => $username,
                        'application_id' => $system?->id,
                        'application_name' => $applicationName,
                        'role_name' => $this->text(Str::limit((string) $row->role_name, 100, '')),
                        'ip_address' => $this->text($row->ip_address),
                        'user_agent' => $this->text(Str::limit((string) $row->user_agent, 500, '')),
                        'accessed_at' => $accessedAt,
                    ];
                }

                SystemAccessLog::query()->insert($insert);
                $this->count('system_access_logs', count($insert));
            });
    }

    /**
     * Legacy profile pictures (admin/uploads), re-encoded like any upload.
     * Accounts that already have a photo keep it.
     */
    private function importPhotos(): void
    {
        if (! $this->photoDirectory || ! is_dir($this->photoDirectory)) {
            return;
        }

        $photos = app(ProfilePhotos::class);
        $files = $this->legacy->table('users_cscro8')->where('profile', '!=', '')->pluck('profile', 'id');

        foreach ($files as $legacyId => $file) {
            $user = User::query()->find($this->user($legacyId));
            // basename(): the column is only ever a file name; never follow a path out of the folder.
            $path = $this->photoDirectory.DIRECTORY_SEPARATOR.basename($file);

            if (! $user || $user->profile_photo_path || ! is_file($path)) {
                continue;
            }

            try {
                $user->forceFill(['profile_photo_path' => $photos->store(new UploadedFile($path, basename($path), null, null, true))])->saveQuietly();
                $this->count('photos');
            } catch (ValidationException) {
                $this->count('photos_unreadable');
            }
        }
    }

    /**
     * "Human Resource Division (HRD)" → ["Human Resource Division", "HRD"].
     *
     * @return array{0: string, 1: ?string}
     */
    private function splitCode(string $name): array
    {
        return preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/', trim($name), $match)
            ? [$match[1], $match[2]]
            : [trim($name), null];
    }

    private function icon(?string $bootstrapIcon): string
    {
        $name = Str::after((string) $bootstrapIcon, 'bi-');

        foreach (self::ICONS as $prefix => $icon) {
            if (str_starts_with($name, $prefix)) {
                return $icon;
            }
        }

        return 'grid';
    }

    /**
     * Legacy launch paths are relative to the legacy host; links here must be absolute.
     */
    private function absoluteUrl(?string $url): ?string
    {
        $url = $this->text($url);

        if ($url === null || preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        return rtrim($this->legacyUrl, '/').'/'.ltrim($url, '/');
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function date(?string $value): ?string
    {
        return $value && ! str_starts_with($value, '0000') ? substr($value, 0, 10) : null;
    }

    private function manila(?string $value): ?Carbon
    {
        return $value && ! str_starts_with($value, '0000') ? Carbon::parse($value, self::LEGACY_TIMEZONE)->setTimezone(config('app.timezone')) : null;
    }

    private function utc(?string $value): ?Carbon
    {
        return $value && ! str_starts_with($value, '0000') ? Carbon::parse($value, 'UTC')->setTimezone(config('app.timezone')) : null;
    }

    private function user(int|string|null $legacyId): ?int
    {
        return $legacyId === null ? null : ($this->users[(int) $legacyId] ?? null);
    }

    private function count(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }
}
