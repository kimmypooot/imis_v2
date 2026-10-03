<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/users')->assertRedirect('/login');
    }

    public function test_unauthorized_user_cannot_view_the_index(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/users')->assertForbidden();
    }

    public function test_authorized_user_can_view_the_index(): void
    {
        $user = $this->userWithPermission('users.view');
        User::factory()->create(['name' => 'Listed User']);

        $response = $this->actingAs($user)->get('/users');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Index')
            // the authorized actor's own seeded user is also listed
            ->has('users', 2));
    }

    public function test_index_supports_searching_by_name_or_email(): void
    {
        $user = $this->userWithPermission('users.view');
        User::factory()->create(['name' => 'Findable Person', 'email' => 'findme@example.test']);
        User::factory()->create(['name' => 'Someone Else', 'email' => 'else@example.test']);

        $response = $this->actingAs($user)->get('/users?search=Findable');

        $response->assertInertia(fn ($page) => $page
            ->has('users', 1)
            ->where('users.0.name', 'Findable Person'));
    }

    public function test_index_supports_filtering_by_status(): void
    {
        $user = $this->userWithPermission('users.view');
        User::factory()->inactive()->create();

        $response = $this->actingAs($user)->get('/users?status=inactive');

        $response->assertInertia(fn ($page) => $page->has('users', 1));
    }

    public function test_index_supports_filtering_by_role(): void
    {
        $user = $this->userWithPermission('users.view');
        $role = Role::factory()->create();
        $target = User::factory()->create();
        $target->roles()->attach($role);

        $response = $this->actingAs($user)->get("/users?role_id={$role->id}");

        $response->assertInertia(fn ($page) => $page->has('users', 1));
    }

    public function test_unauthorized_user_cannot_create_a_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/users', [
            'first_name' => 'New', 'last_name' => 'Person',
            'email' => 'new-person@example.test',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'new-person@example.test']);
    }

    public function test_authorized_user_can_create_a_user_and_a_password_reset_link_is_sent(): void
    {
        Notification::fake();

        $actor = $this->userWithPermission('users.create');

        $response = $this->actingAs($actor)->post('/users', [
            'first_name' => 'New', 'last_name' => 'Person',
            'email' => 'new-person@example.test',
        ]);

        $response->assertRedirect();
        $created = User::where('email', 'new-person@example.test')->firstOrFail();
        $this->assertSame('active', $created->status->value);
        Notification::assertSentTo($created, ResetPassword::class);
    }

    public function test_created_user_password_is_hashed_and_unusable(): void
    {
        Notification::fake();

        $actor = $this->userWithPermission('users.create');

        $this->actingAs($actor)->post('/users', [
            'first_name' => 'New', 'last_name' => 'Person',
            'email' => 'new-person@example.test',
        ]);

        $created = User::where('email', 'new-person@example.test')->firstOrFail();
        $this->assertNotSame('', $created->password);
        $this->assertStringStartsWith('$2y$', $created->password);
    }

    public function test_required_fields_are_validated_on_create(): void
    {
        $actor = $this->userWithPermission('users.create');

        $response = $this->actingAs($actor)->post('/users', []);

        $response->assertSessionHasErrors(['first_name', 'last_name', 'email']);
    }

    public function test_duplicate_email_is_rejected_on_create(): void
    {
        $actor = $this->userWithPermission('users.create');
        $existing = User::factory()->create();

        $response = $this->actingAs($actor)->post('/users', [
            'first_name' => 'Duplicate', 'last_name' => 'Person',
            'email' => $existing->email,
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_mass_assignment_cannot_set_unlisted_attributes(): void
    {
        $actor = $this->userWithPermission('users.create');

        $this->actingAs($actor)->post('/users', [
            'first_name' => 'Mass', 'last_name' => 'Person',
            'email' => 'mass-assignment@example.test',
            'id' => 999999,
            'status' => 'inactive',
        ]);

        $created = User::where('email', 'mass-assignment@example.test')->firstOrFail();
        $this->assertNotSame(999999, $created->id);
        $this->assertSame('active', $created->status->value);
    }

    public function test_unauthorized_user_cannot_update_account_information(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($actor)->put("/users/{$target->id}", [
            'name' => 'Renamed',
            'email' => $target->email,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['name' => 'Renamed']);
    }

    public function test_authorized_user_can_update_account_information(): void
    {
        $actor = $this->userWithPermission('users.update');
        $target = User::factory()->create();

        $response = $this->actingAs($actor)->put("/users/{$target->id}", [
            'first_name' => 'Updated', 'last_name' => 'Name',
            'username' => 'UpdatedName ',
            'email' => $target->email,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'Updated Name', 'username' => 'updatedname']);
    }

    public function test_unauthorized_user_cannot_change_status(): void
    {
        $actor = $this->userWithPermission('users.update');
        $target = User::factory()->create();

        $this->actingAs($actor)->patch("/users/{$target->id}/status", [
            'status' => 'inactive',
        ])->assertForbidden();

        $this->assertSame('active', $target->fresh()->status->value);
    }

    public function test_authorized_user_can_deactivate_and_reactivate_a_user(): void
    {
        $actor = $this->userWithPermission('users.delete');
        $target = User::factory()->create();

        $this->actingAs($actor)
            ->patch("/users/{$target->id}/status", ['status' => 'inactive'])
            ->assertRedirect();

        $this->assertSame('inactive', $target->fresh()->status->value);

        $this->actingAs($actor)
            ->patch("/users/{$target->id}/status", ['status' => 'active'])
            ->assertRedirect();

        $this->assertSame('active', $target->fresh()->status->value);
    }

    public function test_last_active_super_admin_cannot_be_deactivated(): void
    {
        $superAdminRole = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach($superAdminRole);

        $response = $this->actingAs($superAdmin)->patch("/users/{$superAdmin->id}/status", [
            'status' => 'inactive',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame('active', $superAdmin->fresh()->status->value);
    }

    public function test_super_admin_can_be_deactivated_when_another_active_super_admin_exists(): void
    {
        $superAdminRole = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $actingSuperAdmin = User::factory()->create();
        $actingSuperAdmin->roles()->attach($superAdminRole);
        $otherSuperAdmin = User::factory()->create();
        $otherSuperAdmin->roles()->attach($superAdminRole);

        $response = $this->actingAs($actingSuperAdmin)->patch("/users/{$otherSuperAdmin->id}/status", [
            'status' => 'inactive',
        ]);

        $response->assertRedirect();
        $this->assertSame('inactive', $otherSuperAdmin->fresh()->status->value);
    }

    public function test_non_super_admin_cannot_modify_a_protected_administrator_account(): void
    {
        $superAdminRole = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $protectedAdmin = User::factory()->create();
        $protectedAdmin->roles()->attach($superAdminRole);

        $actor = $this->userWithPermission('users.update');

        $this->actingAs($actor)->put("/users/{$protectedAdmin->id}", [
            'name' => 'Hijacked',
            'email' => $protectedAdmin->email,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['name' => 'Hijacked']);
    }

    public function test_non_super_admin_cannot_deactivate_a_protected_administrator_account(): void
    {
        $superAdminRole = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $protectedAdmin = User::factory()->create();
        $protectedAdmin->roles()->attach($superAdminRole);
        // Another active super-admin exists, so this is purely testing the
        // "protected administrator" guard, not the last-super-admin guard.
        $otherSuperAdmin = User::factory()->create();
        $otherSuperAdmin->roles()->attach($superAdminRole);

        $actor = $this->userWithPermission('users.delete');

        $this->actingAs($actor)->patch("/users/{$protectedAdmin->id}/status", [
            'status' => 'inactive',
        ])->assertForbidden();

        $this->assertSame('active', $protectedAdmin->fresh()->status->value);
    }

    public function test_unauthorized_user_cannot_request_a_password_reset_for_another_account(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($actor)
            ->post("/users/{$target->id}/send-password-reset")
            ->assertForbidden();
    }

    public function test_authorized_user_can_send_a_password_reset_link(): void
    {
        Notification::fake();

        $actor = $this->userWithPermission('users.update');
        $target = User::factory()->create();

        $this->actingAs($actor)
            ->post("/users/{$target->id}/send-password-reset")
            ->assertRedirect();

        Notification::assertSentTo($target, ResetPassword::class);
    }

    public function test_invalid_user_returns_404_for_show(): void
    {
        $actor = $this->userWithPermission('users.view');

        $this->actingAs($actor)->get('/users/999999')->assertNotFound();
    }

    private function userWithPermission(string $permission): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(
            Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]),
        );
        $user->roles()->attach($role);

        return $user;
    }
}
