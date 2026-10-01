<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Http\Requests\User\AssignRolesRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Models\Application;
use App\Models\Role;
use App\Models\User;
use App\Support\Usernames;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with('roles:id,name,display_name')
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
            ->get(['id', 'name', 'username', 'email', 'status']);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $request->only(['search', 'status', 'role_id']),
            'statusOptions' => [
                ['value' => UserStatus::Active->value, 'label' => 'Active'],
                ['value' => UserStatus::Inactive->value, 'label' => 'Inactive'],
            ],
            'roleOptions' => $this->assignableRoles($request),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Admin/Users/Create', [
            'roleOptions' => $this->assignableRoles($request, onlyActive: true),
        ]);
    }

    /**
     * Creates the account with an unusable random password and
     * immediately sends a password-set link through the existing
     * password-reset flow — an admin never chooses or sees a new user's
     * password.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            // Left blank → generated from the name, the legacy convention.
            'username' => $request->validated('username') ?: Usernames::generate($request->validated('name')),
            'email' => $request->validated('email'),
            'password' => Str::random(40),
            'status' => UserStatus::Active,
        ]);

        $roleIds = $request->validated('role_ids') ?? [];

        if ($roleIds) {
            $user->roles()->sync($roleIds);
        }

        Password::sendResetLink(['email' => $user->email]);

        $this->audit()->record(AuditEvent::UserCreated, "Created user {$user->name}", $user, [
            'username' => $user->username,
            'email' => $user->email,
            'roles' => Role::query()->whereKey($roleIds)->pluck('name')->all(),
        ]);

        return to_route('users.show', $user);
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

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);

        return Inertia::render('Admin/Users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'status' => $user->status,
            ],
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->update($request->validated());

        $this->audit()->recordChanges(AuditEvent::UserUpdated, "Updated user {$user->name}", $user);

        return to_route('users.show', $user);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): RedirectResponse
    {
        $user->update($request->validated());

        $this->audit()->recordChanges(
            AuditEvent::UserStatusChanged,
            "{$this->activationVerb($user->status)} user {$user->name}",
            $user,
        );

        return to_route('users.show', $user);
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

        return to_route('users.show', $user);
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
}
