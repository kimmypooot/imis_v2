<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\OfficeDesignation;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Http\Requests\User\AssignRolesRequest;
use App\Http\Requests\User\SetUserPasswordRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserPhotoRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Models\Application;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\Role;
use App\Models\User;
use App\Support\HR\Plantilla\Plantilla;
use App\Support\Legacy\LegacyBridge;
use App\Support\ProfilePhotos;
use App\Support\Security\AccountLifecycle;
use App\Support\Security\SecurityPolicy;
use App\Support\SystemAccess\OfficeAdminAccess;
use App\Support\Users\AccountCreator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
            ->with(['roles:id,name,display_name', 'organizationalUnit:id,name,short_name', 'employee.headDesignations.toUnit:id,name,short_name'])
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

        // The item each person holds now, keyed by their account.
        $heldItems = PlantillaAppointment::query()->whereNull('plantilla_appointments.date_to')
            ->join('employees', 'employees.id', '=', 'plantilla_appointments.employee_id')
            ->pluck('plantilla_appointments.plantilla_item_id', 'employees.user_id');

        return Inertia::render('Admin/Users/Index', [
            'users' => $users->map(fn (User $user) => [
                ...$user->only(['id', 'name', 'honorific', 'first_name', 'middle_name', 'last_name', 'name_extension', 'username', 'email', 'sex', 'position', 'organizational_unit_id']),
                'plantilla_item_id' => $heldItems[$user->id] ?? null,
                'status' => $user->status->value,
                'office_designation' => $user->office_designation?->value,
                'designation_label' => $user->office_designation?->label(),
                'birthday' => $user->birthday?->toDateString(),
                'office' => $user->organizationalUnit?->short_name ?: $user->organizationalUnit?->name,
                'office_name' => $user->organizationalUnit?->name,
                // Offices they also head by designation (concurrent capacity).
                'concurrent_offices' => $user->employee?->concurrentOffices() ?? [],
                'profile_photo_url' => $user->profile_photo_url,
                'roles' => $user->roles->map->only(['id', 'name', 'display_name'])->values(),
                'systems_count' => $user->application_access_count,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                // Per-row abilities (protected Super Administrators).
                'can' => [
                    'update' => $actor->can('update', $user),
                    'status' => $actor->can('updateStatus', $user),
                    'roles' => $actor->can('assignRoles', $user),
                    'set_password' => ! $actor->is($user) && $actor->can('setPassword', $user),
                    // Division Chief / Field Director grants admin access, so it follows system-access rights.
                    'designation' => $actor->can('manageSystemAccess', $user),
                ],
            ]),
            'filters' => $request->only(['search', 'status', 'role_id']),
            'statusOptions' => [
                ['value' => UserStatus::Active->value, 'label' => 'Active'],
                ['value' => UserStatus::Inactive->value, 'label' => 'Inactive'],
            ],
            'roleOptions' => $this->assignableRoles($request),
            'officeOptions' => $this->officeOptions(),
            'designationOptions' => OfficeDesignation::options(),
            'honorificOptions' => User::HONORIFICS,
            'plantillaItemOptions' => $this->plantillaItemOptions($heldItems->values()),
            'canAppoint' => $actor->hasPermission(PermissionName::PlantillaManage->value),
            'canDesignate' => $actor->hasPermission(PermissionName::ApplicationsManageAccess->value),
            'passwordRequirements' => SecurityPolicy::passwordRequirements(),
        ]);
    }

    /**
     * Creates the account with an unusable random password and
     * immediately sends a password-set link through the existing
     * password-reset flow — an admin never chooses or sees a new user's
     * password. (The legacy system set it to the user's birthday; that is
     * deliberately not carried over.)
     */
    public function store(StoreUserRequest $request, ProfilePhotos $photos, AccountCreator $accounts, Plantilla $plantilla): RedirectResponse
    {
        $profile = $request->safe()->only([
            'honorific', 'first_name', 'middle_name', 'last_name', 'name_extension', 'sex', 'birthday', 'organizational_unit_id', 'office_designation', 'email',
        ]);

        ['user' => $user, 'office' => $office] = $accounts->create($profile, $request->validated('username'), $request->validated('role_ids') ?? []);
        $this->linkPlantillaItem($request, $user, $plantilla);

        if ($request->hasFile('profile_photo')) {
            $user->forceFill(['profile_photo_path' => $photos->store($request->file('profile_photo'))])->save();
        }

        $admin = $this->describeOfficeAdmin($office);

        return back()->with('status', "{$user->name} was added. A link to set their password was emailed to {$user->email}.{$admin}");
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
            'roles' => $roles = $this->assignableRoles($request),
            // Admin-3 permission preview: each role's permissions by name, so
            // the page can show what ticking a role opens before saving. An
            // inactive role grants nothing, so it previews as nothing.
            'rolePermissions' => Role::query()->active()->with('permissions:id,name')->whereIn('id', $roles->pluck('id'))->get()
                ->mapWithKeys(fn (Role $role) => [$role->id => $role->permissions->pluck('name')])->all(),
            'permissionLabels' => Permission::query()->pluck('display_name', 'name'),
            'holdsSuperAdmin' => $user->hasRole(RoleName::SuperAdmin),
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
        $viaDesignation = $user->applicationAccess()->where('via_designation', true)->pluck('application_id')->flip();

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
                // Given by their Division Chief / Field Director designation.
                'via_designation' => $viaDesignation->has($application->id),
            ])
            ->all();
    }

    public function update(UpdateUserRequest $request, User $user, OfficeAdminAccess $offices, Plantilla $plantilla): RedirectResponse
    {
        $user->fill(Arr::except($request->validated(), ['plantilla_item_id', 'position']));
        // Moved to an office the designation doesn't fit: it lapses.
        if ($user->office_designation && ! $offices->designationFits($user->office_designation, $user->organizationalUnit()->first())) {
            $user->office_designation = null;
        }
        $user->save();
        $this->linkPlantillaItem($request, $user, $plantilla);

        $this->audit()->recordChanges(AuditEvent::UserUpdated, "Updated user {$user->name}", $user);
        $admin = $this->syncOfficeAdmin($user);

        return back()->with('status', "{$user->name}'s account was saved.{$admin}");
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

        // Deactivated: signed out everywhere at once (and the office designation's
        // system access dropped). Reactivated: the designation's access comes back.
        if ($user->status === UserStatus::Inactive) {
            $ended = app(AccountLifecycle::class)->ended($user);

            return back()->with('status', "{$user->name} was deactivated".($ended ? " and signed out of {$ended} ".Str::plural('session', $ended) : '').'.');
        }

        $this->syncOfficeAdmin($user);

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
     * the usual way to help someone who can't sign in. The administrator
     * never sees or sets the password.
     */
    public function sendPasswordReset(User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        Password::sendResetLink(['email' => $user->email]);

        $this->audit()->record(AuditEvent::UserPasswordResetSent, "Sent a password reset link to {$user->name}", $user);

        return back()->with('status', 'Password reset link sent.');
    }

    /**
     * The Super Administrator override: sets the password directly, for
     * when a reset link can't be used (no working email, locked out). By
     * default the user must choose their own at the next sign-in. Their
     * open sessions and "remember me" end. The password is never logged;
     * the legacy systems get the new hash through the usual sync.
     */
    public function setPassword(SetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $mustChange = $request->boolean('must_change_password');

        $user->forceFill([
            'password' => $request->validated('password'),
            'must_change_password' => $mustChange,
            'remember_token' => Str::random(60),
        ])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        $this->audit()->record(AuditEvent::UserPasswordSet, "Set the password of {$user->name}", $user, ['must_change_password' => $mustChange]);

        return back()->with('status', "{$user->name}'s password was set."
            .($mustChange ? ' They must choose a new one when they sign in.' : ''));
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
            ->get(['id', 'name', 'display_name', 'status']);
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
                    // The designation this office takes (Division Chief / Field Director), if any.
                    ->map(fn (OrganizationalUnit $unit) => ['value' => $unit->id, 'label' => $unit->name, 'designation' => OfficeDesignation::forUnitType($type)?->value])
                    ->values()->all(),
            ])
            ->filter(fn (array $group) => $group['options'] !== [])
            ->values()
            ->all();
    }

    /**
     * Active plantilla items for the Position select, flagged vacant unless
     * someone holds them now (the form offers vacant ones and the user's own).
     *
     * @param  Collection<int, int>  $held
     * @return array<int, array<string, mixed>>
     */
    private function plantillaItemOptions(Collection $held): array
    {
        return PlantillaItem::query()->active()->with('organizationalUnit:id,name,short_name')
            ->orderBy('position_title')->orderBy('item_number')->get()
            ->map(fn (PlantillaItem $item) => [
                'value' => $item->id,
                'label' => ($item->item_number ?: 'No item no.').' — '.$item->position_title
                    .($item->organizationalUnit ? ' ('.($item->organizationalUnit->short_name ?: $item->organizationalUnit->name).')' : ''),
                'vacant' => ! $held->contains($item->id),
            ])->all();
    }

    /**
     * A position picked on the form is a plantilla item: the person is
     * linked to it, incomplete until HR adds the step, nature and date.
     */
    private function linkPlantillaItem(Request $request, User $user, Plantilla $plantilla): void
    {
        $itemId = $request->integer('plantilla_item_id');
        $employee = $user->employee;

        if ($itemId && $employee && $plantilla->linkToItem($employee, PlantillaItem::query()->findOrFail($itemId), $request->user())) {
            $this->audit()->record(AuditEvent::UserUpdated, "Linked {$user->name} to plantilla item #{$itemId} from the user form", $user);
        }
    }

    /**
     * Applies the user's Division Chief / Field Director designation to
     * their office's systems; returns a sentence for the flash message.
     */
    private function syncOfficeAdmin(User $user): string
    {
        return $this->describeOfficeAdmin(app(OfficeAdminAccess::class)->sync($user));
    }

    /**
     * @param  array<string, int>  $counts  from OfficeAdminAccess::sync
     */
    private function describeOfficeAdmin(array $counts): string
    {
        $parts = array_filter([
            $counts['granted'] ? "granted admin in {$counts['granted']} ".Str::plural('system', $counts['granted']) : null,
            $counts['raised'] ? "raised to admin in {$counts['raised']} ".Str::plural('system', $counts['raised']) : null,
            $counts['removed'] ? "designation access removed from {$counts['removed']} ".Str::plural('system', $counts['removed']) : null,
        ]);

        return $parts ? ' Office systems: '.implode('; ', $parts).'.' : '';
    }
}
