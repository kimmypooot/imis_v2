<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Http\Requests\User\AssignRolesRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserPhotoRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Models\Application;
use App\Models\OrganizationalUnit;
use App\Models\Role;
use App\Models\User;
use App\Support\HR\EmployeeRecords;
use App\Support\Legacy\LegacyBridge;
use App\Support\ProfilePhotos;
use App\Support\Usernames;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * User management, laid out like the legacy IMIS "Manage Users" page: one
 * table, with Add and Edit (Profile / Account / Security) in modals.
 * Every action answers with a redirect back plus a flash message, which
 * the UI shows as a toast.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $actor = $request->user();

        $users = User::query()
            ->with(['roles:id,name,display_name', 'organizationalUnit:id,name,short_name'])
            ->withCount('applicationAccess')
            ->when($request->string('search')->trim()->value(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->string('status')->value(), fn ($query, $status) => $query->where('status', $status))
            ->when(
                $request->integer('role_id') ?: null,
                fn ($query, $roleId) => $query->whereHas('roles', fn ($query) => $query->whereKey($roleId)),
            )
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users->map(fn (User $user) => [
                ...$user->only(['id', 'name', 'first_name', 'middle_name', 'last_name', 'name_extension', 'username', 'email', 'sex', 'position', 'organizational_unit_id']),
                'status' => $user->status->value,
                'birthday' => $user->birthday?->toDateString(),
                'office' => $user->organizationalUnit?->short_name ?: $user->organizationalUnit?->name,
                'office_name' => $user->organizationalUnit?->name,
                'profile_photo_url' => $user->profile_photo_url,
                'roles' => $user->roles->map->only(['id', 'name', 'display_name'])->values(),
                'systems_count' => $user->application_access_count,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                // Per-row abilities (protected Super Administrators).
                'can' => [
                    'update' => $actor->can('update', $user),
                    'status' => $actor->can('updateStatus', $user),
                    'roles' => $actor->can('assignRoles', $user),
                ],
            ]),
            'filters' => $request->only(['search', 'status', 'role_id']),
            'statusOptions' => [
                ['value' => UserStatus::Active->value, 'label' => 'Active'],
                ['value' => UserStatus::Inactive->value, 'label' => 'Inactive'],
            ],
            'roleOptions' => $this->assignableRoles($request),
            'officeOptions' => $this->officeOptions(),
        ]);
    }

    /**
     * Creates the account with an unusable random password and
     * immediately sends a password-set link through the existing
     * password-reset flow — an admin never chooses or sees a new user's
     * password. (The legacy system set it to the user's birthday; that is
     * deliberately not carried over.)
     */
    public function store(StoreUserRequest $request, ProfilePhotos $photos, EmployeeRecords $records): RedirectResponse
    {
        $profile = $request->safe()->only([
            'first_name', 'middle_name', 'last_name', 'name_extension', 'sex', 'birthday', 'position', 'organizational_unit_id', 'email',
        ]);

        $user = DB::transaction(function () use ($request, $profile, $records) {
            $user = User::create([
                ...$profile,
                'name' => User::composeName($profile['first_name'], $profile['middle_name'] ?? null, $profile['last_name'], $profile['name_extension'] ?? null),
                // Left blank → generated the legacy way (initials + surname).
                'username' => $request->validated('username')
                    ?: Usernames::generateFromParts($profile['first_name'], $profile['middle_name'] ?? null, $profile['last_name']),
                'password' => Str::random(40),
                'status' => UserStatus::Active,
            ]);

            if ($roleIds = $request->validated('role_ids') ?? []) {
                $user->roles()->sync($roleIds);
            }

            // Every new account is a staff member: their employee record
            // (HR-1) comes with it, employment status "Not set" for HR, so
            // they can fill in their PDS right away.
            $records->setUp($user, how: 'Automatically set up');

            return $user;
        });

        if ($request->hasFile('profile_photo')) {
            $user->forceFill(['profile_photo_path' => $photos->store($request->file('profile_photo'))])->save();
        }

        Password::sendResetLink(['email' => $user->email]);

        $this->audit()->record(AuditEvent::UserCreated, "Created user {$user->name}", $user, [
            'username' => $user->username,
            'email' => $user->email,
            'roles' => $user->roles()->pluck('name')->all(),
        ]);

        return back()->with('status', "{$user->name} was added. A link to set their password was emailed to {$user->email}.");
    }

    public function show(Request $request, User $user): Response
    {
        $this->authorize('view', $user);

        return Inertia::render('Admin/Users/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'status' => $user->status,
            ],
            'assignedRoleIds' => $user->roles()->pluck('roles.id'),
            'roles' => $this->assignableRoles($request),
            'systemAccess' => $this->systemAccess($user),
            'canManageSystemAccess' => $request->user()->can('manageSystemAccess', $user),
        ]);
    }

    /**
     * Every system the user could be given a role in (it has an active
     * role) or already has one in, with the user's current role per system.
     *
     * @return array<int, array<string, mixed>>
     */
    private function systemAccess(User $user): array
    {
        $current = $user->applicationAccess()->pluck('application_role_id', 'application_id');

        return Application::query()
            ->where(fn ($query) => $query
                ->whereHas('roles', fn ($query) => $query->active())
                ->orWhereKey($current->keys()->all()))
            ->with(['roles' => fn ($query) => $query
                ->where(fn ($query) => $query->active()->orWhereKey($current->values()->all()))
                ->select(['id', 'application_id', 'name', 'description'])])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'short_name', 'status'])
            ->map(fn (Application $application) => [
                ...$application->only(['id', 'name', 'short_name', 'status']),
                'roles' => $application->roles->map->only(['id', 'name', 'description'])->values(),
                'role_id' => $current[$application->id] ?? null,
            ])
            ->all();
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->update($request->validated());

        $this->audit()->recordChanges(AuditEvent::UserUpdated, "Updated user {$user->name}", $user);

        return back()->with('status', "{$user->name}'s account was saved.");
    }

    public function updatePhoto(UpdateUserPhotoRequest $request, User $user, ProfilePhotos $photos): RedirectResponse
    {
        $user->forceFill([
            'profile_photo_path' => $photos->store($request->file('profile_photo'), $user->profile_photo_path),
        ])->save();

        $this->audit()->record(AuditEvent::UserUpdated, "Changed the profile photo of {$user->name}", $user);

        return back()->with('status', 'Profile photo updated.');
    }

    public function destroyPhoto(User $user, ProfilePhotos $photos): RedirectResponse
    {
        $this->authorize('update', $user);

        $photos->delete($user->profile_photo_path);
        $user->forceFill(['profile_photo_path' => null])->save();

        $this->audit()->record(AuditEvent::UserUpdated, "Removed the profile photo of {$user->name}", $user);

        return back()->with('status', 'Profile photo removed.');
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): RedirectResponse
    {
        $user->update($request->validated());

        $verb = $this->activationVerb($user->status);

        $this->audit()->recordChanges(AuditEvent::UserStatusChanged, "{$verb} user {$user->name}", $user);

        return back()->with('status', "{$user->name} was ".Str::lower($verb).'.');
    }

    public function assignRoles(AssignRolesRequest $request, User $user): RedirectResponse
    {
        $result = $user->roles()->sync($request->validated('role_ids'));

        $this->recordPivotChange(
            AuditEvent::UserRolesChanged,
            "Changed roles of user {$user->name}",
            $user,
            Role::class,
            $result,
        );

        // Super Administrator is the legacy "superadmin" role; pivot changes fire no model event.
        app(LegacyBridge::class)->queue($user->id);

        return back()->with('status', "{$user->name}'s IMIS roles were saved.");
    }

    /**
     * Sends a password-set link via the existing password-reset flow —
     * this is the only account action in this controller that touches a
     * password, and even this never sees or sets one directly.
     */
    public function sendPasswordReset(User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        Password::sendResetLink(['email' => $user->email]);

        $this->audit()->record(AuditEvent::UserPasswordResetSent, "Sent a password reset link to {$user->name}", $user);

        return back()->with('status', 'Password reset link sent.');
    }

    /**
     * The roles offered to a given actor. The super-admin role is left
     * out entirely for a non-super-admin actor — not because they'd be
     * blocked server-side if they tried (`AssignRolesRequest`/
     * `StoreUserRequest` already guarantee that), but so the UI never
     * dangles a checkbox that would only ever end in a rejected request.
     */
    private function assignableRoles(Request $request, bool $onlyActive = false): Collection
    {
        return Role::query()
            ->when($onlyActive, fn ($query) => $query->active())
            ->when(
                ! $request->user()->hasRole(RoleName::SuperAdmin),
                fn ($query) => $query->where('name', '!=', RoleName::SuperAdmin->value),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'display_name']);
    }

    /**
     * Offices grouped by type, for the "Division / Office" select (the
     * legacy fo_rsu list).
     *
     * @return array<int, array{label: string, options: array<int, array{value: int, label: string}>}>
     */
    private function officeOptions(): array
    {
        $units = OrganizationalUnit::query()->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'short_name', 'unit_type']);

        return collect(UnitType::cases())
            ->map(fn (UnitType $type) => [
                'label' => $type->pluralLabel(),
                'options' => $units->where('unit_type', $type)
                    ->map(fn (OrganizationalUnit $unit) => ['value' => $unit->id, 'label' => $unit->name])
                    ->values()->all(),
            ])
            ->filter(fn (array $group) => $group['options'] !== [])
            ->values()
            ->all();
    }
}
