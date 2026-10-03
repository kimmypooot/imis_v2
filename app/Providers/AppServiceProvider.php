<?php

namespace App\Providers;

use App\Enums\RoleName;
use App\Models\Announcement;
use App\Models\Application;
use App\Models\Changelog;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDocument;
use App\Models\FwaArrangement;
use App\Models\FwaReport;
use App\Models\Holiday;
use App\Models\LeaveApplication;
use App\Models\LeaveDesignation;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveMonetization;
use App\Models\LeaveType;
use App\Models\OrganizationalUnit;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\Pds\PdsSubmission;
use App\Models\Permission;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\Position;
use App\Models\Role;
use App\Models\SalarySchedule;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Policies\PdsLearningDevelopmentPolicy;
use App\Policies\PdsSubmissionPolicy;
use App\Support\Audit\AuthenticationAuditSubscriber;
use App\Support\Legacy\LegacyBridge;
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
        'applications.manage_access', 'sessions.view', 'backups.create',
        'employees.view', 'employees.view_unit', 'pds.view', 'pds.verify', 'employee_documents.view',
        'ld.view', 'ld.manage', 'ld.approve', 'plantilla.view', 'plantilla.manage',
        'service_records.view', 'leave.view', 'leave.manage',
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

        // Mirror account and access changes to the legacy subsystems' database.
        $this->app->make(LegacyBridge::class)->registerListeners();
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
            'employee' => Employee::class,
            'employee_document' => EmployeeDocument::class,
            'employee_assignment' => EmployeeAssignment::class,
            'plantilla_item' => PlantillaItem::class,
            'position' => Position::class,
            'plantilla_appointment' => PlantillaAppointment::class,
            'salary_schedule' => SalarySchedule::class,
            'leave_type' => LeaveType::class,
            'holiday' => Holiday::class,
            'leave_ledger_entry' => LeaveLedgerEntry::class,
            'leave_application' => LeaveApplication::class,
            'leave_designation' => LeaveDesignation::class,
            'leave_monetization' => LeaveMonetization::class,
            'fwa_arrangement' => FwaArrangement::class,
            'fwa_report' => FwaReport::class,
            'ld_record' => PdsLearningDevelopment::class,
            'pds_submission' => PdsSubmission::class,
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

        // Models under App\Models\Pds sit outside policy auto-discovery.
        Gate::policy(PdsSubmission::class, PdsSubmissionPolicy::class);
        Gate::policy(PdsLearningDevelopment::class, PdsLearningDevelopmentPolicy::class);

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
