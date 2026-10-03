<?php

namespace App\Support\SystemAccess;

use App\Enums\UserStatus;
use App\Models\Application;
use App\Models\ApplicationRole;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The two access grids from the legacy admin, as plain arrays for the
 * modals:
 *
 * - forApplication(): every user and their role in one system (legacy
 *   "Roles and Permissions" → System Access).
 * - forUser(): every system and the user's role in it (legacy
 *   "User Access").
 *
 * Rows the actor may not change are marked `locked` with the reason, so
 * the UI can show why instead of failing on save. The server re-checks
 * everything on save regardless (SyncApplicationAccessRequest,
 * UpdateSystemAccessRequest).
 */
class AccessMatrix
{
    public function __construct(private readonly User $actor) {}

    /**
     * @return array{roles: array<int, array<string, mixed>>, users: array<int, array<string, mixed>>}
     */
    public function forApplication(Application $application): array
    {
        $current = $application->accessGrants()->pluck('application_role_id', 'user_id');

        $users = User::query()
            ->with(['roles:id,name', 'organizationalUnit:id,name,short_name'])
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'position', 'status', 'organizational_unit_id', 'profile_photo_path']);

        return [
            'roles' => $this->assignableRoles($application, $current->values()),
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'position' => $user->position,
                'office' => $user->organizationalUnit?->short_name ?: $user->organizationalUnit?->name,
                'status' => $user->status->value,
                'profile_photo_url' => $user->profile_photo_url,
                'role_id' => $current[$user->id] ?? null,
                'locked' => $this->lockReason($user),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forUser(User $user): array
    {
        $current = $user->applicationAccess()->pluck('application_role_id', 'application_id');
        $locked = $this->lockReason($user);

        return Application::query()
            ->with(['roles' => fn ($query) => $query->select(['id', 'application_id', 'name', 'status'])])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'short_name', 'status', 'icon'])
            ->map(fn (Application $application) => [
                'id' => $application->id,
                'name' => $application->name,
                'short_name' => $application->short_name,
                'icon' => $application->icon,
                'status' => $application->status->value,
                'roles' => $application->roles
                    ->filter(fn (ApplicationRole $role) => $role->status->value === 'active' || $role->id === ($current[$application->id] ?? null))
                    ->map->only(['id', 'name'])
                    ->values()
                    ->all(),
                'role_id' => $current[$application->id] ?? null,
                'locked' => $locked,
            ])
            ->all();
    }

    /**
     * Why the actor can't change this user's system access, or null.
     */
    public function lockReason(User $user): ?string
    {
        if ($this->actor->can('manageSystemAccess', $user)) {
            return $user->status === UserStatus::Inactive ? 'inactive' : null;
        }

        return $this->actor->is($user) ? 'self' : 'protected';
    }

    /**
     * Active roles, plus any inactive role someone still holds (so the
     * current value always shows).
     *
     * @param  Collection<int, int>  $heldRoleIds
     * @return array<int, array{id: int, name: string}>
     */
    private function assignableRoles(Application $application, Collection $heldRoleIds): array
    {
        return $application->roles()
            ->where(fn ($query) => $query->active()->orWhereKey($heldRoleIds->unique()->all()))
            ->get(['id', 'name'])
            ->map->only(['id', 'name'])
            ->all();
    }
}
