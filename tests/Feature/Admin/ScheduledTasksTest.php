<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ScheduledTaskPause;
use App\Models\User;
use App\Support\System\HealthChecks;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Admin-3: Admin → Scheduled Tasks — seeing every task, and pausing,
 * resuming and running one now.
 */
class ScheduledTasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_viewers_see_the_tasks_in_plain_words(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/scheduled-tasks')->assertForbidden();

        $this->actingAs($this->userWith(['settings.view']))->get('/admin/scheduled-tasks')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/ScheduledTasks')
            ->where('canManage', false)
            ->where('tasks', function ($tasks) {
                $tasks = collect($tasks)->keyBy('name');

                return $tasks['imis:expire-sessions']['when'] === 'Every 5 minutes'
                    && $tasks['imis:backup --if-enabled']['when'] === 'Daily at 2:00 AM'
                    && $tasks['imis:executive-digest']['when'] === 'Mondays at 7:00 AM'
                    && $tasks['imis:leave-accrual']['description'] !== null;
            }));
    }

    public function test_the_controls_need_their_own_permission(): void
    {
        $viewer = $this->userWith(['settings.view']);

        $this->actingAs($viewer)->post('/admin/scheduled-tasks/pause', ['task' => 'imis:expire-sessions'])->assertForbidden();
        $this->actingAs($viewer)->post('/admin/scheduled-tasks/run', ['task' => 'imis:expire-sessions'])->assertForbidden();
    }

    public function test_a_paused_task_is_skipped_until_resumed(): void
    {
        $admin = $this->userWith(['settings.view', 'scheduled_tasks.manage']);
        $task = $this->event('imis:expire-sessions');

        $this->assertTrue($task->filtersPass($this->app));

        $this->actingAs($admin)->post('/admin/scheduled-tasks/pause', ['task' => 'imis:expire-sessions', 'reason' => 'Testing'])->assertSessionHasNoErrors();
        $this->assertFalse($task->filtersPass($this->app));
        $this->assertSame('Testing', ScheduledTaskPause::query()->sole()->reason);
        $this->assertSame('warn', collect(app(HealthChecks::class)->run())->firstWhere('label', 'imis:expire-sessions')['status']);

        $this->actingAs($admin)->post('/admin/scheduled-tasks/resume', ['task' => 'imis:expire-sessions']);
        $this->assertTrue($task->filtersPass($this->app));

        $this->assertSame(2, AuditLog::query()->whereIn('event', ['system.task_paused', 'system.task_resumed'])->count());
    }

    public function test_running_a_task_now(): void
    {
        $admin = $this->userWith(['settings.view', 'scheduled_tasks.manage']);

        $this->actingAs($admin)->post('/admin/scheduled-tasks/run', ['task' => 'imis:expire-sessions'])
            ->assertSessionHas('task_run', fn ($run) => $run['ok'] === true && $run['by'] === $admin->name);

        $this->actingAs($admin)->get('/admin/scheduled-tasks')->assertInertia(fn (Assert $page) => $page
            ->where('tasks', fn ($tasks) => collect($tasks)->firstWhere('name', 'imis:expire-sessions')['manual']['ok'] === true));
        $this->assertTrue(AuditLog::query()->where('event', 'system.task_run')->exists());

        // Closures run too.
        $this->actingAs($admin)->post('/admin/scheduled-tasks/run', ['task' => 'prune-sso-tokens'])->assertSessionHas('task_run', fn ($run) => $run['ok']);
    }

    public function test_unknown_tasks_are_refused(): void
    {
        $admin = $this->userWith(['settings.view', 'scheduled_tasks.manage']);

        $this->actingAs($admin)->post('/admin/scheduled-tasks/run', ['task' => 'migrate:fresh --force'])->assertNotFound();
        $this->actingAs($admin)->post('/admin/scheduled-tasks/pause', ['task' => 'nothing'])->assertNotFound();
    }

    private function event(string $name): Event
    {
        $this->app->make(ConsoleKernel::class)->bootstrap();

        return collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, $name));
    }

    /**
     * @param  array<int, string>  $permissions
     */
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
