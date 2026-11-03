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
use App\Models\EmployeeLoan;
use App\Models\EmployeePayItem;
use App\Models\Feedback;
use App\Models\FwaArrangement;
use App\Models\FwaReport;
use App\Models\Holiday;
use App\Models\LeaveApplication;
use App\Models\LeaveDesignation;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveMonetization;
use App\Models\LeaveType;
use App\Models\OrganizationalUnit;
use App\Models\PayComponent;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\PayslipRequest;
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
use App\Support\Mail\EmailTemplates;
use App\Support\Security\SecurityPolicy;
use App\Support\System\ScheduleMonitor;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Database\Eloquent\Model as EloquentModel;
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
     * The permissions that qualify a user for system administration — the
     * Administration nav entry and `/admin` (`access-admin`). Centralized
     * here so that concept has exactly one definition instead of being
     * re-derived per controller/component. `administrative`-visibility
     * directory entries need this or HR_ACCESS_PERMISSIONS.
     */
    public const ADMIN_ACCESS_PERMISSIONS = [
        'roles.view', 'permissions.view', 'users.view', 'organizational_units.view', 'services.view',
        'applications.view', 'committees.view', 'announcements.view',
        'audit_logs.view', 'settings.view', 'changelogs.view',
        'applications.manage_access', 'sessions.view', 'backups.create',
    ];

    /**
     * The permissions that open the Human Resources area — its nav entry
     * and dashboard at `/admin/hr` (`access-hr`). Kept apart from system
     * administration: HR staff aren't system administrators, and system
     * administrators don't need HR's personal data.
     */
    public const HR_ACCESS_PERMISSIONS = [
        'employees.view', 'employees.view_unit', 'pds.view', 'pds.verify', 'employee_documents.view',
        'ld.view', 'ld.manage', 'ld.approve', 'plantilla.view', 'plantilla.manage',
        'service_records.view', 'leave.view', 'leave.manage', 'payroll.view', 'payroll.manage',
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
        // System Settings → Security.
        Password::defaults(fn () => SecurityPolicy::passwordRule());
        SecurityPolicy::applySessionConfig();

        // API Resources are used here purely to shape Inertia props, never
        // as a versioned JSON API — the default {"data": [...]} envelope
        // would only complicate every prop on the frontend for no benefit.
        JsonResource::withoutWrapping();

        // Surface N+1 queries: they fail the test suite and are logged in
        // development, but never break a page in production.
        EloquentModel::preventLazyLoading(! $this->app->isProduction());
        if (! $this->app->runningUnitTests()) {
            EloquentModel::handleLazyLoadingViolationUsing(fn (EloquentModel $model, string $relation) => logger()->warning("Lazy loaded {$relation} on ".$model::class));
        }

        $this->configureAuthorization();
        $this->configureAuditing();

        // Admin-3, Admin → Email Templates: the office's wording for the
        // password email. Someone who has never signed in is setting up a
        // new account, so they get the "set your password" email instead.
        ResetPassword::toMailUsing(fn (User $user, string $token) => EmailTemplates::mail(
            $user->last_login_at === null ? 'account_setup' : 'password_reset',
            [
                'first_name' => $user->first_name ?: $user->name,
                'name' => $user->name,
                'username' => $user->username,
                'expires_minutes' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ],
            url(route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false)),
        ));

        // Mirror account and access changes to the legacy subsystems' database.
        $this->app->make(LegacyBridge::class)->registerListeners();

        // System Health: remember when each scheduled job last ran.
        Event::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => app(ScheduleMonitor::class)
            ->record($event->task, in_array($event->task->exitCode, [null, 0], true)));
        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => app(ScheduleMonitor::class)->record($event->task, false));
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
            'feedback' => Feedback::class,
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
            'pay_component' => PayComponent::class,
            'employee_pay_item' => EmployeePayItem::class,
            'employee_loan' => EmployeeLoan::class,
            'payroll_run' => PayrollRun::class,
            'payroll_entry' => PayrollEntry::class,
            'payslip_request' => PayslipRequest::class,
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

        Gate::define(
            'access-hr',
            fn (User $user) => $user->hasAnyPermission(self::HR_ACCESS_PERMISSIONS),
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
