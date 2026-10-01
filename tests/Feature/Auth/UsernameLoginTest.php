<?php

namespace Tests\Feature\Auth;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Usernames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Sign-in by username (the legacy IMIS convention), the administrator
 * login toggle, the Turnstile security check, and username management.
 */
class UsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_username_is_case_insensitive_and_trimmed(): void
    {
        $user = User::factory()->create(['username' => 'jdcruz']);

        $this->post('/login', ['username' => '  JDCruz ', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_email_no_longer_signs_in(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['username' => $user->email, 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_wrong_credentials_get_one_generic_message(): void
    {
        User::factory()->create(['username' => 'jdcruz']);

        $this->from('/login')->post('/login', ['username' => 'jdcruz', 'password' => 'nope'])
            ->assertSessionHasErrors(['username' => 'Invalid username or password.']);
        $this->from('/login')->post('/login', ['username' => 'nobody', 'password' => 'nope'])
            ->assertSessionHasErrors(['username' => 'Invalid username or password.']);
    }

    public function test_login_records_the_previous_sign_in_time(): void
    {
        $lastTime = now()->subDay()->startOfSecond();
        $user = User::factory()->create(['last_login_at' => $lastTime]);

        $this->post('/login', ['username' => $user->username, 'password' => 'password']);

        $user->refresh();
        $this->assertTrue($lastTime->equalTo($user->previous_login_at));
        $this->assertTrue($user->last_login_at->isAfter($lastTime));
    }

    public function test_lockout_seconds_are_passed_to_the_login_page(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 6) as $attempt) {
            $this->from('/login')->post('/login', ['username' => $user->username, 'password' => 'wrong']);
        }

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('lockoutSeconds', fn ($seconds) => $seconds > 0 && $seconds <= 60));
    }

    // ── Administrator login toggle ───────────────────────────────────

    public function test_admin_mode_lands_an_administrator_on_the_admin_console(): void
    {
        $user = $this->userWithPermission('users.view');

        $this->post('/login', ['username' => $user->username, 'password' => 'password', 'mode' => 'admin'])
            ->assertRedirect(route('admin.index'));
    }

    public function test_admin_mode_never_grants_access_to_a_regular_user(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['username' => $user->username, 'password' => 'password', 'mode' => 'admin'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status');

        $this->get('/admin')->assertForbidden();
    }

    // ── Turnstile ────────────────────────────────────────────────────

    public function test_turnstile_is_off_without_keys(): void
    {
        $user = User::factory()->create();

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('turnstileSiteKey', null));
        $this->post('/login', ['username' => $user->username, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_turnstile_token_is_required_and_verified_when_enabled(): void
    {
        config(['services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()
            ->push(['success' => false])
            ->push(['success' => true])]);
        $user = User::factory()->create();

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('turnstileSiteKey', 'site'));

        $this->from('/login')->post('/login', ['username' => $user->username, 'password' => 'password'])
            ->assertSessionHasErrors('cf-turnstile-response');
        $this->from('/login')->post('/login', [
            'username' => $user->username, 'password' => 'password', 'cf-turnstile-response' => 'bad',
        ])->assertSessionHasErrors('cf-turnstile-response');
        $this->assertGuest();

        $this->post('/login', [
            'username' => $user->username, 'password' => 'password', 'cf-turnstile-response' => 'good',
        ]);
        $this->assertAuthenticatedAs($user);

        Http::assertSent(fn ($request) => ($request->data()['secret'] ?? null) === 'secret'
            && ($request->data()['response'] ?? null) === 'good');
    }

    public function test_turnstile_fails_closed_when_cloudflare_is_unreachable(): void
    {
        config(['services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::failedConnection()]);
        $user = User::factory()->create();

        $this->from('/login')->post('/login', [
            'username' => $user->username, 'password' => 'password', 'cf-turnstile-response' => 'token',
        ])->assertSessionHasErrors('cf-turnstile-response');

        $this->assertGuest();
    }

    // ── Logout ───────────────────────────────────────────────────────

    public function test_logout_returns_to_the_login_page_with_a_confirmation(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'You have been signed out.');
    }

    // ── Username management ──────────────────────────────────────────

    public function test_username_is_generated_from_the_name_when_left_blank(): void
    {
        Notification::fake();
        User::factory()->create(['username' => 'jscruz']);
        $actor = $this->userWithPermission('users.create');

        $this->actingAs($actor)->post('/users', ['name' => 'Juan Santos Cruz', 'email' => 'juan@example.test']);

        $this->assertDatabaseHas('users', ['email' => 'juan@example.test', 'username' => 'jscruz2']);
    }

    public function test_chosen_usernames_are_normalized_validated_and_unique(): void
    {
        Notification::fake();
        User::factory()->create(['username' => 'taken']);
        $actor = $this->userWithPermission('users.create');

        $this->actingAs($actor)->post('/users', ['name' => 'A', 'email' => 'a@example.test', 'username' => 'Taken'])
            ->assertSessionHasErrors('username');
        $this->actingAs($actor)->post('/users', ['name' => 'B', 'email' => 'b@example.test', 'username' => 'has space'])
            ->assertSessionHasErrors('username');
        $this->actingAs($actor)->post('/users', ['name' => 'C', 'email' => 'c@example.test', 'username' => ' MDelaCruz '])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'c@example.test', 'username' => 'mdelacruz']);
    }

    public function test_generator_follows_the_legacy_convention(): void
    {
        $this->assertSame('jdcruz', Usernames::generate('Juan Dela Cruz'));
        $this->assertSame('mramos', Usernames::generate('María Ramos'));
        $this->assertSame('user', Usernames::generate('   '));
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
