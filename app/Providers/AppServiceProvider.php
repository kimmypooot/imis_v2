<?php

namespace App\Providers;

use App\Enums\RoleName;
use App\Models\Announcement;
use App\Models\Application;
use App\Models\Changelog;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audit\AuthenticationAuditSubscriber;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The cache key under which the list of known permission names is
     * stored, so every request doesn't re-query the permissions table to
     * register Gate abilities. Invalidated by PermissionCache::flush()
     * whenever a permission is created, renamed, or removed.
     */
    public const PERMISSION_CACHE_KEY = 'authorization.permission-names';

    /**
     * The permissions that qualify a user as an "administrative" user for
     * portal purposes — showing the Administration nav entry, reaching
     * `/admin`, and seeing `administrative`-visibility directory entries.
     * Centralized here so that concept has exactly one definition instead
     * of being re-derived per controller/component.
     */
    public const ADMIN_ACCESS_PERMISSIONS = [
        'roles.view', 'permissions.view', 'users.view', 'organizational_units.view', 'services.view',
        'applications.view', 'committees.view', 'announcements.view',
        'audit_logs.view', 'settings.view', 'changelogs.view',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(function () {
            $rule = Password::min(8)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // API Resources are used here purely to shape Inertia props, never
        // as a versioned JSON API — the default {"data": [...]} envelope
        // would only complicate every prop on the frontend for no benefit.
        JsonResource::withoutWrapping();

        $this->configureAuthorization();
        $this->configureAuditing();
    }

    /**
     * Short, stable `audit_logs.target_type` values instead of class names,
     * so renaming/moving a model class never orphans its audit history.
     * Not enforced (no other morph relations exist to constrain), so this
     * only affects how these models identify themselves.
     */
    private function configureAuditing(): void
    {
        Relation::morphMap([
            'user' => User::class,
            'role' => Role::class,
            'organizational_unit' => OrganizationalUnit::class,
            'service' => Service::class,
            'application' => Application::class,
            'committee' => Committee::class,
            'committee_member' => CommitteeMember::class,
            'announcement' => Announcement::class,
            'setting' => Setting::class,
            'changelog' => Changelog::class,
        ]);

        Event::subscribe(AuthenticationAuditSubscriber::class);
    }

    /**
     * Wire the role/permission system into Laravel's native Gate.
     *
     * Super Administrators bypass every ability check via Gate::before —
     * identified only by the `super-admin` role, never by email or user
     * ID. Every other permission name becomes its own Gate ability, so
     * `$user->can('users.view')` works without a bespoke authorization API.
     */
    private function configureAuthorization(): void
    {
        Gate::before(fn (User $user) => $user->hasRole(RoleName::SuperAdmin) ? true : null);

        Gate::define(
            'access-admin',
            fn (User $user) => $user->hasAnyPermission(self::ADMIN_ACCESS_PERMISSIONS),
        );

        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach ($this->permissionNames() as $name) {
            Gate::define($name, fn (User $user) => $user->hasPermission($name));
        }
    }

    /**
     * @return array<int, string>
     */
    private function permissionNames(): array
    {
        return Cache::rememberForever(
            self::PERMISSION_CACHE_KEY,
            fn () => Permission::query()->pluck('name')->all(),
        );
    }
}
