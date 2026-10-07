<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\System\HealthChecks;
use Database\Seeders\HrRoleSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Hardening: System Health (page and `imis:health`), the scheduler's
 * last-run record, and the starter HR roles.
 */
class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_health_page_needs_settings_access(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/system-health')->assertForbidden();

        $this->actingAs($this->userWith(['settings.view']))->get('/admin/system-health')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/SystemHealth')
            ->where('checks', fn ($checks) => collect($checks)->firstWhere('label', 'Database connection')['status'] === 'ok'
                && collect($checks)->firstWhere('label', 'Migrations')['status'] === 'ok')
            ->has('overall'));
    }

    public function test_the_scheduler_check_follows_recorded_runs(): void
    {
        $this->assertSame('fail', $this->check('Scheduler (cron)')['status']);

        $task = $this->task('imis:expire-sessions');
        Event::dispatch(new ScheduledTaskFinished($task, 0.1));

        $this->assertSame('ok', $this->check('Scheduler (cron)')['status']);
        $this->assertSame('ok', $this->check('imis:expire-sessions')['status']);

        Event::dispatch(new ScheduledTaskFailed($task, new RuntimeException('boom')));
        $this->assertSame('fail', $this->check('imis:expire-sessions')['status']);

        // Twenty minutes without any job: cron has stopped.
        $this->travel(20)->minutes();
        $this->assertSame('fail', $this->check('Scheduler (cron)')['status']);
    }

    public function test_the_command_exits_non_zero_when_a_check_fails(): void
    {
        // Nothing has run on the scheduler in a fresh test database.
        $this->artisan('imis:health')->expectsOutputToContain('Database connection')->assertFailed();
    }

    public function test_the_hr_roles_are_created_once_and_never_overwritten(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(HrRoleSeeder::class);

        $hr = Role::query()->where('name', 'hr-officer')->sole();
        $this->assertTrue($hr->permissions()->where('name', 'leave.certify')->exists());
        $this->assertFalse($hr->permissions()->where('name', 'leave.approve')->exists());
        $this->assertSame(['leave.approve'], Role::query()->where('name', 'head-of-agency')->sole()->permissions()->pluck('name')->all());

        // An admin's later change survives re-seeding.
        $hr->permissions()->detach(Permission::query()->where('name', 'pds.verify')->value('id'));
        $this->seed(HrRoleSeeder::class);
        $this->assertFalse($hr->permissions()->where('name', 'pds.verify')->exists());
        $this->assertSame(1, Role::query()->where('name', 'hr-officer')->count());
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function check(string $label): array
    {
        return collect(app(HealthChecks::class)->run())->firstWhere('label', $label);
    }

    private function task(string $command): \Illuminate\Console\Scheduling\Event
    {
        app(ConsoleKernel::class)->bootstrap();

        return collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, $command));
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }

        $user->roles()->attach($role);

        return $user;
    }
}
