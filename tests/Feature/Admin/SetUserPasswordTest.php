<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Temporary-Pass-2026';

    public function test_a_super_admin_sets_a_password_that_must_be_changed_at_sign_in(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->superAdmin();
        $target = User::factory()->create(['remember_token' => 'old-token']);
        DB::table('sessions')->insert(['id' => 'target-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin)->put("/users/{$target->id}/password", $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $target->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $target->password));
        $this->assertTrue($target->must_change_password);
        $this->assertNotSame('old-token', $target->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);

        $log = AuditLog::query()->where('event', 'user.password_set')->sole();
        $this->assertStringNotContainsString(self::NEW_PASSWORD, json_encode($log->toArray()));

        // Signed in with it, they're held at Change Password until they pick their own.
        $this->actingAs($target)->get('/dashboard')->assertRedirect(route('account.password.edit'));
        $this->actingAs($target)->get(route('account.password.edit'))
            ->assertInertia(fn ($page) => $page->where('setByAdmin', true));

        $this->actingAs($target)->put('/account/password', [
            'current_password' => self::NEW_PASSWORD,
            'password' => 'Their-Own-Pass-2026',
            'password_confirmation' => 'Their-Own-Pass-2026',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

        $this->assertFalse($target->fresh()->must_change_password);
        $this->actingAs($target->fresh())->get('/dashboard')->assertOk();
    }

    public function test_the_must_change_requirement_can_be_left_off(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->superAdmin())->put("/users/{$target->id}/password", $this->payload(['must_change_password' => false]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($target->fresh()->must_change_password);
    }

    public function test_the_super_admin_must_confirm_with_their_own_password(): void
    {
        $target = User::factory()->create();
        $before = $target->password;

        $this->actingAs($this->superAdmin())->put("/users/{$target->id}/password", $this->payload(['admin_password' => 'wrong']))
            ->assertSessionHasErrors('admin_password');

        $this->assertSame($before, $target->fresh()->password);
    }

    public function test_the_password_policy_applies(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->superAdmin())->put("/users/{$target->id}/password", $this->payload([
            'password' => 'short', 'password_confirmation' => 'short',
        ]))->assertSessionHasErrors('password');
    }

    public function test_only_super_admins_may_set_a_password(): void
    {
        $actor = User::factory()->create();
        $role = Role::factory()->create();
        foreach (['users.view', 'users.update'] as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }
        $actor->roles()->attach($role);
        $target = User::factory()->create();

        $this->actingAs($actor)->put("/users/{$target->id}/password", $this->payload())->assertForbidden();
        $this->actingAs($actor)->get('/users')
            ->assertInertia(fn ($page) => $page->where('users', fn ($users) => collect($users)->every(fn ($user) => $user['can']['set_password'] === false)));
    }

    public function test_not_on_their_own_account(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put("/users/{$admin->id}/password", $this->payload())->assertForbidden();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->firstOrCreate(['name' => RoleName::SuperAdmin->value], ['display_name' => 'Super Administrator']));

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
            'must_change_password' => true,
            'admin_password' => 'password',
            ...$overrides,
        ];
    }
}
