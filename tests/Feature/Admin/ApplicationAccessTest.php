<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApplicationAccessTest extends TestCase
{
    use RefreshDatabase;

    // ── Access page ──────────────────────────────────────────────────

    public function test_access_page_requires_applications_view(): void
    {
        $application = Application::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/admin/applications/{$application->id}/access")
            ->assertForbidden();
    }

    public function test_access_page_lists_roles_and_grants(): void
    {
        $actor = $this->userWithPermissions('applications.view');
        $role = ApplicationRole::factory()->create(['name' => 'Administrator']);
        $grant = ApplicationAccess::factory()->forRole($role)->create();

        $this->actingAs($actor)
            ->get("/admin/applications/{$role->application_id}/access")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Applications/Access')
                ->has('roles', 1, fn (Assert $role) => $role
                    ->where('name', 'Administrator')
                    ->where('access_grants_count', 1)
                    ->etc())
                ->has('grants', 1, fn (Assert $row) => $row
                    ->where('user.id', $grant->user_id)
                    ->where('role.name', 'Administrator')
                    ->etc())
                ->where('userOptions', fn ($options) => ! collect($options)->contains('id', $grant->user_id)));
    }

    // ── Defining roles ───────────────────────────────────────────────

    public function test_unauthorized_user_cannot_add_a_role(): void
    {
        $actor = $this->userWithPermissions('applications.view', 'applications.manage_access');
        $application = Application::factory()->create();

        $this->actingAs($actor)
            ->post("/admin/applications/{$application->id}/roles", ['name' => 'Admin', 'sort_order' => 0])
            ->assertForbidden();

        $this->assertDatabaseCount('application_roles', 0);
    }

    public function test_authorized_user_can_add_a_role_and_it_is_audited(): void
    {
        $actor = $this->userWithPermissions('applications.update');
        $application = Application::factory()->create();

        $this->actingAs($actor)
            ->post("/admin/applications/{$application->id}/roles", [
                'name' => 'Administrator',
                'description' => 'Manages the system.',
                'launch_url' => 'https://example.test/admin',
                'sort_order' => 1,
            ])
            ->assertRedirect("/admin/applications/{$application->id}/access");

        $this->assertDatabaseHas('application_roles', [
            'application_id' => $application->id,
            'name' => 'Administrator',
            'launch_url' => 'https://example.test/admin',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'application.role_created',
            'target_type' => 'application',
            'target_id' => $application->id,
        ]);
    }

    public function test_role_names_are_unique_per_system_only(): void
    {
        $actor = $this->userWithPermissions('applications.update');
        $existing = ApplicationRole::factory()->create(['name' => 'Admin']);
        $other = Application::factory()->create();

        $this->actingAs($actor)
            ->post("/admin/applications/{$existing->application_id}/roles", ['name' => 'Admin', 'sort_order' => 0])
            ->assertSessionHasErrors('name');

        $this->actingAs($actor)
            ->post("/admin/applications/{$other->id}/roles", ['name' => 'Admin', 'sort_order' => 0])
            ->assertSessionHasNoErrors();
    }

    public function test_role_launch_url_must_be_http(): void
    {
        $actor = $this->userWithPermissions('applications.update');
        $application = Application::factory()->create();

        $this->actingAs($actor)
            ->post("/admin/applications/{$application->id}/roles", [
                'name' => 'Admin',
                'launch_url' => 'javascript:alert(1)',
                'sort_order' => 0,
            ])
            ->assertSessionHasErrors('launch_url');
    }

    public function test_a_role_cannot_be_reached_through_another_system(): void
    {
        $actor = $this->userWithPermissions('applications.update');
        $role = ApplicationRole::factory()->create(['name' => 'Admin']);
        $other = Application::factory()->create();

        $this->actingAs($actor)
            ->put("/admin/applications/{$other->id}/roles/{$role->id}", ['name' => 'Hijacked', 'sort_order' => 0])
            ->assertNotFound();

        $this->assertSame('Admin', $role->fresh()->name);
    }

    public function test_a_held_role_cannot_be_deactivated(): void
    {
        $actor = $this->userWithPermissions('applications.update');
        $role = ApplicationRole::factory()->create();
        ApplicationAccess::factory()->forRole($role)->create();

        $this->actingAs($actor)
            ->patch("/admin/applications/{$role->application_id}/roles/{$role->id}/status", ['status' => 'inactive'])
            ->assertSessionHasErrors('status');

        $this->assertSame('active', $role->fresh()->status->value);
    }

    public function test_an_unheld_role_can_be_deactivated(): void
    {
        $actor = $this->userWithPermissions('applications.update');
        $role = ApplicationRole::factory()->create();

        $this->actingAs($actor)
            ->patch("/admin/applications/{$role->application_id}/roles/{$role->id}/status", ['status' => 'inactive'])
            ->assertSessionHasNoErrors();

        $this->assertSame('inactive', $role->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', ['event' => 'application.role_status_changed']);
    }

    // ── Granting, changing, revoking ─────────────────────────────────

    public function test_granting_requires_manage_access(): void
    {
        $actor = $this->userWithPermissions('applications.view', 'applications.update');
        $role = ApplicationRole::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($actor)
            ->post("/admin/applications/{$role->application_id}/access", [
                'user_id' => $target->id,
                'application_role_id' => $role->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('application_access', 0);
    }

    public function test_authorized_user_can_grant_access_and_it_is_audited(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $role = ApplicationRole::factory()->create(['name' => 'Evaluator']);
        $target = User::factory()->create();

        $this->actingAs($actor)
            ->post("/admin/applications/{$role->application_id}/access", [
                'user_id' => $target->id,
                'application_role_id' => $role->id,
            ])
            ->assertRedirect("/admin/applications/{$role->application_id}/access");

        $this->assertDatabaseHas('application_access', [
            'application_id' => $role->application_id,
            'user_id' => $target->id,
            'application_role_id' => $role->id,
            'granted_by' => $actor->id,
        ]);

        $log = AuditLog::query()->where('event', 'application.access_granted')->sole();
        $this->assertSame($role->application_id, $log->target_id);
        $this->assertSame($target->id, $log->metadata['user_id']);
        $this->assertSame('Evaluator', $log->metadata['role']);
    }

    public function test_granted_by_cannot_be_mass_assigned(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $role = ApplicationRole::factory()->create();
        $target = User::factory()->create();
        $someoneElse = User::factory()->create();

        $this->actingAs($actor)->post("/admin/applications/{$role->application_id}/access", [
            'user_id' => $target->id,
            'application_role_id' => $role->id,
            'granted_by' => $someoneElse->id,
        ]);

        $this->assertDatabaseHas('application_access', ['user_id' => $target->id, 'granted_by' => $actor->id]);
    }

    public function test_cannot_grant_a_role_of_another_system(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $application = Application::factory()->create();
        $foreignRole = ApplicationRole::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($actor)
            ->post("/admin/applications/{$application->id}/access", [
                'user_id' => $target->id,
                'application_role_id' => $foreignRole->id,
            ])
            ->assertSessionHasErrors('application_role_id');

        $this->assertDatabaseCount('application_access', 0);
    }

    public function test_cannot_grant_an_inactive_role(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $role = ApplicationRole::factory()->inactive()->create();

        $this->actingAs($actor)
            ->post("/admin/applications/{$role->application_id}/access", [
                'user_id' => User::factory()->create()->id,
                'application_role_id' => $role->id,
            ])
            ->assertSessionHasErrors('application_role_id');
    }

    public function test_a_user_holds_at_most_one_role_per_system(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $existing = ApplicationAccess::factory()->create();
        $otherRole = ApplicationRole::factory()->create(['application_id' => $existing->application_id]);

        $this->actingAs($actor)
            ->post("/admin/applications/{$existing->application_id}/access", [
                'user_id' => $existing->user_id,
                'application_role_id' => $otherRole->id,
            ])
            ->assertSessionHasErrors('user_id');

        $this->assertDatabaseCount('application_access', 1);
    }

    public function test_cannot_grant_access_to_an_inactive_account(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $role = ApplicationRole::factory()->create();
        $target = User::factory()->create(['status' => 'inactive']);

        $this->actingAs($actor)
            ->post("/admin/applications/{$role->application_id}/access", [
                'user_id' => $target->id,
                'application_role_id' => $role->id,
            ])
            ->assertSessionHasErrors('user_id');
    }

    public function test_non_super_admin_cannot_grant_themselves_access(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $role = ApplicationRole::factory()->create();

        $this->actingAs($actor)
            ->post("/admin/applications/{$role->application_id}/access", [
                'user_id' => $actor->id,
                'application_role_id' => $role->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('application_access', 0);
    }

    public function test_non_super_admin_cannot_change_their_own_role_or_revoke_it(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $grant = ApplicationAccess::factory()->create(['user_id' => $actor->id]);
        $higher = ApplicationRole::factory()->create(['application_id' => $grant->application_id]);

        $this->actingAs($actor)
            ->put("/admin/applications/{$grant->application_id}/access/{$grant->id}", ['application_role_id' => $higher->id])
            ->assertForbidden();
        $this->actingAs($actor)
            ->delete("/admin/applications/{$grant->application_id}/access/{$grant->id}")
            ->assertForbidden();

        $this->assertSame($grant->application_role_id, $grant->fresh()->application_role_id);
    }

    public function test_super_admin_may_grant_themselves_access(): void
    {
        $actor = $this->superAdmin();
        $role = ApplicationRole::factory()->create();

        $this->actingAs($actor)
            ->post("/admin/applications/{$role->application_id}/access", [
                'user_id' => $actor->id,
                'application_role_id' => $role->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('application_access', ['user_id' => $actor->id]);
    }

    public function test_non_super_admin_cannot_change_a_super_admins_access(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $role = ApplicationRole::factory()->create();
        $target = $this->superAdmin();

        $this->actingAs($actor)
            ->post("/admin/applications/{$role->application_id}/access", [
                'user_id' => $target->id,
                'application_role_id' => $role->id,
            ])
            ->assertForbidden();
    }

    public function test_authorized_user_can_change_a_role(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $grant = ApplicationAccess::factory()->create();
        $newRole = ApplicationRole::factory()->create(['application_id' => $grant->application_id, 'name' => 'Approver']);

        $this->actingAs($actor)
            ->put("/admin/applications/{$grant->application_id}/access/{$grant->id}", ['application_role_id' => $newRole->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($newRole->id, $grant->fresh()->application_role_id);
        $log = AuditLog::query()->where('event', 'application.access_changed')->sole();
        $this->assertSame('Approver', $log->metadata['to_role']);
    }

    public function test_authorized_user_can_revoke_access(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $grant = ApplicationAccess::factory()->create();

        $this->actingAs($actor)
            ->delete("/admin/applications/{$grant->application_id}/access/{$grant->id}")
            ->assertRedirect("/admin/applications/{$grant->application_id}/access");

        $this->assertModelMissing($grant);
        $this->assertDatabaseHas('audit_logs', ['event' => 'application.access_revoked', 'target_id' => $grant->application_id]);
    }

    public function test_a_grant_cannot_be_reached_through_another_system(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $grant = ApplicationAccess::factory()->create();
        $other = Application::factory()->create();

        $this->actingAs($actor)
            ->delete("/admin/applications/{$other->id}/access/{$grant->id}")
            ->assertNotFound();

        $this->assertModelExists($grant);
    }

    public function test_database_rejects_a_grant_whose_role_belongs_to_another_system(): void
    {
        $foreignRole = ApplicationRole::factory()->create();
        $application = Application::factory()->create();

        $this->expectException(QueryException::class);

        ApplicationAccess::query()->forceCreate([
            'application_id' => $application->id,
            'user_id' => User::factory()->create()->id,
            'application_role_id' => $foreignRole->id,
        ]);
    }

    // ── Per-user screen ──────────────────────────────────────────────

    public function test_user_page_lists_systems_with_the_users_current_role(): void
    {
        $actor = $this->userWithPermissions('users.view', 'applications.manage_access');
        $grant = ApplicationAccess::factory()->create();
        ApplicationRole::factory()->create();

        $this->actingAs($actor)
            ->get("/users/{$grant->user_id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('systemAccess', 2)
                ->where('canManageSystemAccess', true)
                ->where('systemAccess', fn ($systems) => collect($systems)
                    ->firstWhere('id', $grant->application_id)['role_id'] === $grant->application_role_id));
    }

    public function test_per_user_save_grants_changes_and_revokes_leaving_unlisted_systems_alone(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $target = User::factory()->create();
        $toChange = ApplicationAccess::factory()->create(['user_id' => $target->id]);
        $toRevoke = ApplicationAccess::factory()->create(['user_id' => $target->id]);
        $untouched = ApplicationAccess::factory()->create(['user_id' => $target->id]);
        $changedRole = ApplicationRole::factory()->create(['application_id' => $toChange->application_id]);
        $newRole = ApplicationRole::factory()->create();

        $this->actingAs($actor)
            ->put("/users/{$target->id}/system-access", ['access' => [
                ['application_id' => $toChange->application_id, 'application_role_id' => $changedRole->id],
                ['application_id' => $toRevoke->application_id, 'application_role_id' => null],
                ['application_id' => $newRole->application_id, 'application_role_id' => $newRole->id],
            ]])
            ->assertRedirect("/users/{$target->id}");

        $this->assertSame($changedRole->id, $toChange->fresh()->application_role_id);
        $this->assertModelMissing($toRevoke);
        $this->assertModelExists($untouched);
        $this->assertDatabaseHas('application_access', ['user_id' => $target->id, 'application_role_id' => $newRole->id]);
        $this->assertSame(3, AuditLog::query()->where('event', 'like', 'application.access_%')->count());
    }

    public function test_per_user_save_rejects_a_role_from_another_system(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $target = User::factory()->create();
        $application = Application::factory()->create();
        $foreignRole = ApplicationRole::factory()->create();

        $this->actingAs($actor)
            ->put("/users/{$target->id}/system-access", ['access' => [
                ['application_id' => $application->id, 'application_role_id' => $foreignRole->id],
            ]])
            ->assertSessionHasErrors('access.0.application_role_id');

        $this->assertDatabaseCount('application_access', 0);
    }

    public function test_per_user_save_applies_nothing_when_any_entry_is_invalid(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $target = User::factory()->create();
        $valid = ApplicationRole::factory()->create();
        $inactive = ApplicationRole::factory()->inactive()->create();

        $this->actingAs($actor)
            ->put("/users/{$target->id}/system-access", ['access' => [
                ['application_id' => $valid->application_id, 'application_role_id' => $valid->id],
                ['application_id' => $inactive->application_id, 'application_role_id' => $inactive->id],
            ]])
            ->assertSessionHasErrors('access.1.application_role_id');

        $this->assertDatabaseCount('application_access', 0);
    }

    public function test_per_user_save_is_forbidden_for_self_and_for_protected_targets(): void
    {
        $actor = $this->userWithPermissions('applications.manage_access');
        $role = ApplicationRole::factory()->create();
        $payload = ['access' => [['application_id' => $role->application_id, 'application_role_id' => $role->id]]];

        $this->actingAs($actor)->put("/users/{$actor->id}/system-access", $payload)->assertForbidden();
        $this->actingAs($actor)->put("/users/{$this->superAdmin()->id}/system-access", $payload)->assertForbidden();

        $this->assertDatabaseCount('application_access', 0);
    }

    public function test_per_user_save_requires_manage_access(): void
    {
        $actor = $this->userWithPermissions('users.update');
        $role = ApplicationRole::factory()->create();

        $this->actingAs($actor)
            ->put("/users/{$this->userWithPermissions()->id}/system-access", ['access' => [
                ['application_id' => $role->application_id, 'application_role_id' => $role->id],
            ]])
            ->assertForbidden();
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

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->firstOrCreate(
            ['name' => RoleName::SuperAdmin->value],
            ['display_name' => 'Super Administrator'],
        ));

        return $user;
    }
}
