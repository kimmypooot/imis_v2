<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Security\SecurityPolicy;
use App\Support\Settings\SettingRegistry;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * System Settings → Security: lockout, session timeouts, password rules,
 * reuse and expiry.
 */
class SecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingSeeder::class);
    }

    // ── Settings ─────────────────────────────────────────────────────

    public function test_password_policy_keys_pass_the_credential_guard(): void
    {
        SettingRegistry::sync();

        $this->assertDatabaseHas('settings', ['key' => 'password_policy_min_length', 'group' => 'security']);
    }

    public function test_a_period_must_be_zero_or_at_least_its_floor(): void
    {
        $editor = $this->userWithPermissions('settings.view', 'settings.update');

        $this->actingAs($editor)->from('/admin/settings')
            ->put('/admin/settings/security', ['settings' => ['password_policy_expiry_days' => 10]])
            ->assertSessionHasErrors('settings.password_policy_expiry_days');

        $this->actingAs($editor)->from('/admin/settings')
            ->put('/admin/settings/security', ['settings' => ['password_policy_expiry_days' => 0]])
            ->assertSessionHasNoErrors();

        $this->actingAs($editor)->from('/admin/settings')
            ->put('/admin/settings/retention', ['settings' => ['retention_audit_log_months' => 6]])
            ->assertSessionHasErrors('settings.retention_audit_log_months');
    }

    // ── Sign-in lockout and sessions ─────────────────────────────────

    public function test_lockout_follows_the_settings(): void
    {
        $this->set('security_login_max_attempts', 3);
        $this->set('security_lockout_minutes', 2);
        $user = User::factory()->create();

        foreach (range(1, 3) as $attempt) {
            $this->from('/login')->post('/login', ['username' => $user->username, 'password' => 'wrong']);
        }

        $this->from('/login')->post('/login', ['username' => $user->username, 'password' => 'password']);
        $this->assertGuest();

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('lockoutSeconds', fn ($seconds) => $seconds > 60 && $seconds <= 120));
    }

    public function test_session_timeouts_override_the_server_defaults_only_when_set(): void
    {
        config(['session.lifetime' => 120, 'session.absolute_lifetime' => 480]);

        SecurityPolicy::applySessionConfig();
        $this->assertSame(120, config('session.lifetime'));

        $this->set('security_idle_timeout_minutes', 30);
        $this->set('security_session_limit_minutes', 240);
        SecurityPolicy::applySessionConfig();

        $this->assertSame(30, config('session.lifetime'));
        $this->assertSame(240, config('session.absolute_lifetime'));
    }

    // ── Password rules ───────────────────────────────────────────────

    public function test_password_rules_follow_the_settings(): void
    {
        $this->set('password_policy_min_length', 12);
        $this->set('password_policy_mixed_case', true);
        $this->set('password_policy_symbols', true);
        $user = User::factory()->create();

        $this->changePassword($user, 'short1pass')->assertSessionHasErrors('password');
        $this->changePassword($user, 'alllowercase123!')->assertSessionHasErrors('password');
        $this->changePassword($user, 'NoSymbolsHere123')->assertSessionHasErrors('password');
        $this->changePassword($user, 'Good-Password-123')->assertSessionHasNoErrors();

        $this->actingAs($user)->get('/account/password')->assertInertia(fn (Assert $page) => $page
            ->where('requirements', fn ($requirements) => collect($requirements)->contains('At least 12 characters')
                && collect($requirements)->contains('At least one symbol')));
    }

    public function test_recent_passwords_cannot_be_reused(): void
    {
        $this->set('password_policy_history', 2);
        $user = User::factory()->create();

        $this->changePassword($user, 'zero-pass-1')->assertSessionHasNoErrors();
        $this->changePassword($user, 'first-pass-1', 'zero-pass-1')->assertSessionHasNoErrors();
        $this->changePassword($user, 'second-pass-1', 'first-pass-1')->assertSessionHasNoErrors();
        $this->changePassword($user, 'third-pass-1', 'second-pass-1')->assertSessionHasNoErrors();

        // Two back: still blocked, and named as such.
        $this->changePassword($user, 'first-pass-1', 'third-pass-1')
            ->assertSessionHasErrors(['password' => 'Choose a password you haven\'t used for your last 3 passwords.']);
        $this->assertTrue(Hash::check('third-pass-1', $user->fresh()->password));

        // Three back has dropped out of the window.
        $this->changePassword($user, 'zero-pass-1', 'third-pass-1')->assertSessionHasNoErrors();
    }

    public function test_without_history_only_the_current_password_is_blocked(): void
    {
        $user = User::factory()->create();

        $this->changePassword($user, 'first-pass-1')->assertSessionHasNoErrors();
        $this->changePassword($user, 'second-pass-1', 'first-pass-1')->assertSessionHasNoErrors();
        $this->changePassword($user, 'first-pass-1', 'second-pass-1')->assertSessionHasNoErrors();
    }

    public function test_only_the_newest_hashes_are_kept(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 26) as $i) {
            $user->update(['password' => "pass-number-{$i}"]);
        }

        $this->assertSame(24, $user->passwordHistories()->count());
    }

    public function test_a_reset_cannot_reuse_a_recent_password_and_keeps_the_token(): void
    {
        $this->set('password_policy_history', 1);
        $user = User::factory()->create();
        $user->update(['password' => 'old-pass-1']);
        $user->update(['password' => 'current-pass-1']);
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email, 'password' => 'old-pass-1', 'password_confirmation' => 'old-pass-1',
        ])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('current-pass-1', $user->fresh()->password));

        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email, 'password' => 'brand-new-1', 'password_confirmation' => 'brand-new-1',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('brand-new-1', $user->fresh()->password));
    }

    // ── Expiry ───────────────────────────────────────────────────────

    public function test_changing_a_password_records_when(): void
    {
        $user = User::factory()->create();
        $this->travel(5)->days();

        $this->changePassword($user, 'second-pass-1');

        $this->assertTrue($user->fresh()->password_changed_at->isToday());
    }

    public function test_an_expired_password_sends_every_page_to_change_password(): void
    {
        $this->set('password_policy_expiry_days', 30);
        $user = User::factory()->create();
        $user->forceFill(['password_changed_at' => now()->subDays(31)])->saveQuietly();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('account.password.edit'));
        $this->actingAs($user)->getJson('/dashboard')->assertForbidden();
        $this->actingAs($user)->get('/account/password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('expired', true)->where('expiryDays', 30));
        $this->actingAs($user)->post('/session/heartbeat')->assertSuccessful();

        $this->changePassword($user, 'second-pass-1')->assertRedirect(route('dashboard'));
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    public function test_passwords_never_expire_when_expiry_is_off(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['password_changed_at' => now()->subYears(3)])->saveQuietly();

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function changePassword(User $user, string $new, string $current = 'password')
    {
        return $this->actingAs($user)->from('/account/password')->put('/account/password', [
            'current_password' => $current, 'password' => $new, 'password_confirmation' => $new,
        ]);
    }

    private function set(string $key, mixed $value): void
    {
        $setting = Setting::query()->where('key', $key)->firstOrFail();
        $setting->value = $setting->type->serialize($value);
        $setting->save();
    }

    private function userWithPermissions(string ...$permissions): User
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
