<?php

namespace App\Models;

use App\Enums\RoleName;
use App\Enums\RoleStatus;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'display_name', 'description', 'status'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RoleStatus::class,
        ];
    }

    /**
     * The users assigned this role.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user');
    }

    /**
     * The permissions granted to this role.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    /**
     * The baseline "User" role: every account holds at least one role, and
     * this is what an account gets when none is chosen. Created on first use.
     */
    public static function baseline(): self
    {
        return static::query()->firstOrCreate(
            ['name' => RoleName::User->value],
            [
                'display_name' => 'User',
                'description' => 'Standard staff account: the portal, own profile, PDS, leave and payslips. No administrative permissions.',
                'status' => RoleStatus::Active,
            ],
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RoleStatus::Active);
    }
}
