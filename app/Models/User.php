<?php

namespace App\Models;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'username', 'email', 'password', 'status'])]
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
            'last_login_at' => 'datetime',
            'previous_login_at' => 'datetime',
        ];
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
     * The roles assigned to this user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    /**
     * This user's per-system access grants (one role per system).
     */
    public function applicationAccess(): HasMany
    {
        return $this->hasMany(ApplicationAccess::class);
    }

    /**
     * All permissions granted to this user through its assigned roles.
     *
     * @return Collection<int, Permission>
     */
    public function permissions(): Collection
    {
        return $this->loadMissing('roles.permissions')
            ->roles
            ->flatMap(fn (Role $role) => $role->permissions)
            ->unique('id')
            ->values();
    }

    public function hasRole(RoleName|string $role): bool
    {
        $role = $role instanceof RoleName ? $role->value : $role;

        return $this->loadMissing('roles')->roles->contains('name', $role);
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
