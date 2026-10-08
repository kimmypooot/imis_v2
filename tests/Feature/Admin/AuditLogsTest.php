<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/audit-logs')->assertRedirect('/login');
    }

    public function test_user_without_permission_cannot_view_logs(): void
    {
        $log = AuditLog::factory()->create();
        $user = $this->userWithPermissions('users.view', 'announcements.update');

        $this->actingAs($user)->get('/admin/audit-logs')->assertForbidden();
        $this->actingAs($user)->get("/admin/audit-logs/{$log->id}")->assertForbidden();
    }

    public function test_authorized_user_can_list_and_view_logs(): void
    {
        $user = $this->userWithPermissions('audit_logs.view');
        $log = AuditLog::factory()->create([
            'event' => AuditEvent::RoleCreated,
            'action' => 'created',
            'metadata' => ['note' => 'hello'],
        ]);

        $this->actingAs($user)->get('/admin/audit-logs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AuditLogs/Index')
                ->has('logs.data', 1)
                ->where('logs.data.0.event', 'role.created'));

        $this->actingAs($user)->get("/admin/audit-logs/{$log->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AuditLogs/Show')
                ->where('log.metadata.note', 'hello'));
    }

    public function test_audit_log_view_permission_grants_admin_landing(): void
    {
        $this->actingAs($this->userWithPermissions('audit_logs.view'))->get('/admin')->assertOk();
    }

    public function test_viewing_logs_does_not_itself_create_entries(): void
    {
        $user = $this->userWithPermissions('audit_logs.view');

        $this->actingAs($user)->get('/admin/audit-logs');

        $this->assertSame(0, AuditLog::count());
    }

    public function test_logs_are_paginated_newest_first(): void
    {
        $user = $this->userWithPermissions('audit_logs.view');
        AuditLog::factory()->count(30)->create(['created_at' => now()->subDay()]);
        $newest = AuditLog::factory()->create(['created_at' => now()]);

        $this->actingAs($user)->get('/admin/audit-logs')
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 25)
                ->where('logs.data.0.id', $newest->id)
                ->where('logs.last_page', 2));
    }

    public function test_filter_by_event(): void
    {
        $user = $this->userWithPermissions('audit_logs.view');
        AuditLog::factory()->create(['event' => AuditEvent::AuthLogin, 'action' => 'login']);
        AuditLog::factory()->create(['event' => AuditEvent::RoleCreated, 'action' => 'created']);

        $this->actingAs($user)->get('/admin/audit-logs?event=role.created')
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.event', 'role.created'));
    }

    public function test_filter_by_actor(): void
    {
        $user = $this->userWithPermissions('audit_logs.view');
        $actor = User::factory()->create();
        AuditLog::factory()->create(['actor_id' => $actor->id]);
        AuditLog::factory()->create(['actor_id' => null]);

        $this->actingAs($user)->get("/admin/audit-logs?actor_id={$actor->id}")
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.actor_id', $actor->id)
                ->where('actorOptions.0.value', $actor->id));
    }

    public function test_filter_by_target(): void
    {
        $user = $this->userWithPermissions('audit_logs.view');
        AuditLog::factory()->create(['target_type' => 'service', 'target_id' => 7]);
        AuditLog::factory()->create(['target_type' => 'service', 'target_id' => 8]);
        AuditLog::factory()->create(['target_type' => 'role', 'target_id' => 7]);

        $this->actingAs($user)->get('/admin/audit-logs?target_type=service')
            ->assertInertia(fn ($page) => $page->has('logs.data', 2));

        $this->actingAs($user)->get('/admin/audit-logs?target_type=service&target_id=7')
            ->assertInertia(fn ($page) => $page->has('logs.data', 1));
    }

    public function test_unknown_target_type_filter_is_ignored(): void
    {
        $user = $this->userWithPermissions('audit_logs.view');
        AuditLog::factory()->count(2)->create();

        $this->actingAs($user)->get('/admin/audit-logs?target_type=App%5CModels%5CUser')
            ->assertInertia(fn ($page) => $page->has('logs.data', 2));
    }

    public function test_filter_by_date_range(): void
    {
        $user = $this->userWithPermissions('audit_logs.view');
        AuditLog::factory()->create(['created_at' => '2026-09-01 12:00:00']);
        $inRange = AuditLog::factory()->create(['created_at' => '2026-09-15 12:00:00']);
        AuditLog::factory()->create(['created_at' => '2026-09-30 12:00:00']);

        $query = http_build_query(['from' => '2026-09-10T00:00:00Z', 'to' => '2026-09-20T23:59:59Z']);

        $this->actingAs($user)->get("/admin/audit-logs?{$query}")
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.id', $inRange->id));
    }

    public function test_date_range_honours_timezone_offsets(): void
    {
        $user = $this->userWithPermissions('audit_logs.view');
        // 04:00 on Sep 16 in Manila is still Sep 15 (20:00) in UTC.
        AuditLog::factory()->create(['created_at' => '2026-09-16 04:00:00']);

        $query = http_build_query(['from' => '2026-09-15T16:00:00Z', 'to' => '2026-09-16T15:59:59Z']);

        $this->actingAs($user)->get("/admin/audit-logs?{$query}")
            ->assertInertia(fn ($page) => $page->has('logs.data', 1));
    }

    public function test_search_matches_description_and_labels(): void
    {
        $user = $this->userWithPermissions('audit_logs.view');
        AuditLog::factory()->create(['description' => 'Created service Payroll']);
        AuditLog::factory()->create(['description' => 'Other', 'target_label' => 'Payroll Unit']);
        AuditLog::factory()->create(['description' => 'Unrelated']);

        $this->actingAs($user)->get('/admin/audit-logs?search=Payroll')
            ->assertInertia(fn ($page) => $page->has('logs.data', 2));
    }

    public function test_there_are_no_routes_to_modify_or_delete_logs(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::where('name', 'super-admin')->firstOrFail());
        $log = AuditLog::factory()->create(['description' => 'Original']);

        $this->actingAs($superAdmin)->post('/admin/audit-logs', ['description' => 'Forged'])->assertStatus(405);
        $this->actingAs($superAdmin)->put("/admin/audit-logs/{$log->id}", ['description' => 'Changed'])->assertStatus(405);
        $this->actingAs($superAdmin)->patch("/admin/audit-logs/{$log->id}", ['description' => 'Changed'])->assertStatus(405);
        $this->actingAs($superAdmin)->delete("/admin/audit-logs/{$log->id}")->assertStatus(405);

        $this->assertSame('Original', $log->fresh()->description);
        $this->assertSame(1, AuditLog::count());
    }

    public function test_entries_cannot_be_updated_through_the_model(): void
    {
        $log = AuditLog::factory()->create(['description' => 'Original']);

        $this->expectException(LogicException::class);

        $log->forceFill(['description' => 'Changed'])->save();
    }

    public function test_entries_cannot_be_deleted_through_the_model(): void
    {
        $log = AuditLog::factory()->create();

        try {
            $log->delete();
            $this->fail('Deleting an audit entry should throw.');
        } catch (LogicException) {
            $this->assertSame(1, AuditLog::count());
        }
    }

    public function test_entries_are_not_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);

        AuditLog::create(['description' => 'Forged', 'actor_id' => 1]);
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(
                Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]),
            );
        }

        $user->roles()->attach($role);

        return $user;
    }
}
