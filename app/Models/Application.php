<?php

namespace App\Models;

use App\Enums\PermissionName;
use App\Enums\Status;
use App\Enums\Visibility;
use App\Models\Concerns\HasPortalVisibility;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * A directory entry for a (typically external) CSC RO VIII ITG system —
 * OTRS, ORS/HRMPSB Connect, PROCTAD, etc. This model deliberately holds no
 * business logic belonging to those systems: it is only a pointer
 * (name/description/url/icon) the portal displays. Actual application
 * functionality is never absorbed into this codebase.
 */
#[Fillable([
    'name', 'short_name', 'description', 'icon', 'url', 'category',
    'external', 'featured', 'status', 'visibility', 'requires_access', 'sort_order', 'organizational_unit_id',
])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory, HasPortalVisibility;

    /**
     * The summary feed (`feed_url`, `feed_key`) is set only on Executive
     * Dashboard → Settings, never by the general edit form; the key is
     * encrypted with APP_KEY and never serialized.
     */
    protected $hidden = ['feed_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feed_key' => 'encrypted',
            'status' => Status::class,
            'visibility' => Visibility::class,
            'external' => 'boolean',
            'featured' => 'boolean',
            'requires_access' => 'boolean',
            'under_maintenance' => 'boolean',
            'maintenance_started_at' => 'datetime',
        ];
    }

    /**
     * Maintenance is deliberately not mass-assignable: it only changes
     * through these two transitions (its own route and permission), never
     * as a side effect of the general edit form.
     */
    public function startMaintenance(?string $message): void
    {
        $this->forceFill([
            'under_maintenance' => true,
            'maintenance_message' => $message,
            'maintenance_started_at' => $this->under_maintenance ? $this->maintenance_started_at : now(),
        ])->save();
    }

    public function endMaintenance(): void
    {
        $this->forceFill([
            'under_maintenance' => false,
            'maintenance_message' => null,
            'maintenance_started_at' => null,
        ])->save();
    }

    /**
     * Who may still launch a system under maintenance — the people who can
     * put it there (so they can verify the work), plus Super Administrators
     * through hasPermission().
     */
    public function maintenanceBypassedBy(?User $user): bool
    {
        return $user?->hasPermission(PermissionName::ApplicationsMaintenance->value) ?? false;
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    /**
     * Units this system is *also* listed under in the dashboard directory,
     * besides the unit that manages it (organizationalUnit).
     */
    public function listedUnits(): MorphToMany
    {
        return $this->morphedByMany(OrganizationalUnit::class, 'listable', 'application_listings')->withTimestamps();
    }

    /**
     * Committees this system is listed under in the dashboard directory.
     */
    public function listedCommittees(): MorphToMany
    {
        return $this->morphedByMany(Committee::class, 'listable', 'application_listings')->withTimestamps();
    }

    /**
     * The roles defined inside this system — not IMIS platform roles.
     */
    public function roles(): HasMany
    {
        return $this->hasMany(ApplicationRole::class)->orderBy('sort_order')->orderBy('name');
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(ApplicationAccess::class);
    }

    /**
     * Eager-loads only the given user's grant (with its role), so a
     * portal listing can resolve each card's access/launch URL without a
     * query per application.
     */
    public function scopeWithAccessFor(Builder $query, ?User $user): Builder
    {
        return $query->with(self::accessGrantsFor($user));
    }

    /**
     * The single-record counterpart of withAccessFor().
     */
    public function loadAccessFor(?User $user): static
    {
        return $this->load(self::accessGrantsFor($user));
    }

    /**
     * @return array<string, \Closure>
     */
    private static function accessGrantsFor(?User $user): array
    {
        return ['accessGrants' => fn ($query) => $query
            ->where('user_id', $user?->getKey())
            ->with('role:id,name,code,launch_url')];
    }

    public function accessFor(?User $user): ?ApplicationAccess
    {
        if (! $user) {
            return null;
        }

        if ($this->relationLoaded('accessGrants')) {
            return $this->accessGrants->firstWhere('user_id', $user->getKey());
        }

        return $this->accessGrants()->with('role:id,name,code,launch_url')->where('user_id', $user->getKey())->first();
    }

    /**
     * Where this user launches into: their role's own entry point if it
     * has one, else the system's URL. No URL at all while the system is
     * under maintenance (unless the user may bypass it), or when it
     * requires access the user doesn't have — the link is withheld from
     * the page props, not merely hidden in the UI.
     */
    public function launchUrlFor(?User $user): ?string
    {
        if ($this->under_maintenance && ! $this->maintenanceBypassedBy($user)) {
            return null;
        }

        $access = $this->accessFor($user);

        if ($access) {
            return $access->role->launch_url ?: $this->url;
        }

        return $this->requires_access ? null : $this->url;
    }
}
