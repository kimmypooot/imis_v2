<?php

namespace App\Models;

use App\Enums\OfficeDesignation;
use App\Enums\RoleName;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'name', 'first_name', 'middle_name', 'last_name', 'name_extension', 'sex', 'birthday', 'position', 'organizational_unit_id',
    'office_designation', 'username', 'email', 'password', 'status',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'office_designation' => OfficeDesignation::class,
            'birthday' => 'date',
            'last_login_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'notification_preferences' => 'array',
            'previous_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * Keeps the stored display name in step with the name parts, in the
     * legacy format: "First M. Last" (middle initial with a period), plus
     * the name extension when there is one ("First M. Last Jr.").
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->first_name !== null && $user->isDirty(['first_name', 'middle_name', 'last_name', 'name_extension'])) {
                $user->name = self::composeName($user->first_name, $user->middle_name, $user->last_name, $user->name_extension);
            }

            // Division Chief / Field Director belongs to the office: moving
            // (e.g. a reassignment) ends it unless it's set again with the move.
            if ($user->exists && $user->isDirty('organizational_unit_id') && ! $user->isDirty('office_designation')) {
                $user->office_designation = null;
            }

            // Account lifecycle: a reactivated account counts as active from now.
            if ($user->exists && $user->isDirty('status')) {
                $user->status_changed_at = now();
            }

            // Password expiry counts from here (System Settings → Security).
            if ($user->isDirty('password')) {
                $user->password_changed_at = now();

                // Choosing a password of their own clears a Super
                // Administrator's override; the override sets both at once.
                if (! $user->isDirty('must_change_password')) {
                    $user->must_change_password = false;
                }
            }
        });

        // Keep the replaced hash so it can't be chosen again
        // (password_policy_history). Only the newest MAX are kept.
        static::updated(function (User $user) {
            $previous = $user->getOriginal('password');

            if (! $user->wasChanged('password') || blank($previous)) {
                return;
            }

            $user->passwordHistories()->create(['password' => $previous]);

            $keep = $user->passwordHistories()->latest('id')->limit(PasswordHistory::MAX)->pluck('id');
            $user->passwordHistories()->whereNotIn('id', $keep)->delete();
        });
    }

    public function passwordHistories(): HasMany
    {
        return $this->hasMany(PasswordHistory::class);
    }

    public static function composeName(?string $first, ?string $middle, ?string $last, ?string $extension = null): string
    {
        $initial = filled($middle) ? mb_strtoupper(mb_substr(trim($middle), 0, 1)).'.' : null;

        return collect([trim((string) $first), $initial, trim((string) $last), trim((string) $extension)])->filter()->implode(' ');
    }

    /**
     * The office (Regional Support Unit / field office) the user belongs
     * to — the legacy `fo_rsu`.
     */
    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    /**
     * HR-1: this person's employee record, if HR has set one up.
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * Surname first, for lists sorted by surname: "Dela Cruz, Juan T. Jr."
     * Falls back to the display name when the name parts aren't set.
     */
    protected function listName(): Attribute
    {
        return Attribute::get(function () {
            if (blank($this->last_name)) {
                return $this->name;
            }

            $given = self::composeName($this->first_name, $this->middle_name, null, $this->name_extension);

            return collect([trim($this->last_name), $given])->filter()->implode(', ');
        });
    }

    /**
     * Public URL of the profile photo, or null for the default avatar.
     */
    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->profile_photo_path
            ? Storage::disk('public')->url($this->profile_photo_path)
            : null);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Shifts the current sign-in time to "previous", so the account menu
     * can show when the user last signed in before this session.
     */
    public function recordLogin(): void
    {
        $this->forceFill([
            'previous_login_at' => $this->last_login_at,
            'last_login_at' => now(),
        ])->saveQuietly();
    }

    /**
     * The roles assigned to this user, active or not — what the assignment
     * screens show. Authorization reads activeRoles().
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    /**
     * The assigned roles that are active: the only ones that grant
     * anything. Deactivating a role (Roles & Permissions) takes its
     * permissions away from everyone holding it, without unassigning it.
     * The Super Administrator role can't be deactivated (UpdateRoleRequest).
     */
    public function activeRoles(): BelongsToMany
    {
        return $this->roles()->where('roles.status', RoleStatus::Active->value);
    }

    /**
     * This user's per-system access grants (one role per system).
     */
    public function applicationAccess(): HasMany
    {
        return $this->hasMany(ApplicationAccess::class);
    }

    /**
     * All permissions granted to this user through its active roles.
     *
     * @return Collection<int, Permission>
     */
    public function permissions(): Collection
    {
        return $this->loadMissing('activeRoles.permissions')
            ->activeRoles
            ->flatMap(fn (Role $role) => $role->permissions)
            ->unique('id')
            ->values();
    }

    public function hasRole(RoleName|string $role): bool
    {
        $role = $role instanceof RoleName ? $role->value : $role;

        return $this->loadMissing('activeRoles')->activeRoles->contains('name', $role);
    }

    /**
     * @param  array<int, RoleName|string>  $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        return collect($roles)->contains(fn (RoleName|string $role) => $this->hasRole($role));
    }

    /**
     * @param  array<int, RoleName|string>  $roles
     */
    public function hasAllRoles(array $roles): bool
    {
        return collect($roles)->every(fn (RoleName|string $role) => $this->hasRole($role));
    }

    public function hasPermission(string $permission): bool
    {
        return $this->hasRole(RoleName::SuperAdmin) || $this->permissions()->contains('name', $permission);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        return collect($permissions)->contains(fn (string $permission) => $this->hasPermission($permission));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        return collect($permissions)->every(fn (string $permission) => $this->hasPermission($permission));
    }
}
