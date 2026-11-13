<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\Security\PasskeySignIn;
use App\Support\Security\SessionLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passkeys\Passkey;
use Tests\TestCase;

/**
 * Fingerprint / face sign-in: the account page, the password gate in front of
 * adding and removing a device, and what a passkey sign-in does in IMIS (the
 * WebAuthn cryptography itself is the laravel/passkeys package's).
 */
class PasskeysTest extends TestCase
{
    use RefreshDatabase;

    private function passkeyFor(User $user, string $name = 'My phone'): Passkey
    {
        return Passkey::query()->forceCreate([
            'user_id' => $user->id,
            'name' => $name,
            'credential_id' => bin2hex(random_bytes(16)),
            'credential' => ['id' => 'x'],
        ]);
    }

    public function test_the_page_needs_a_signed_in_user_and_lists_only_their_own_devices(): void
    {
        $this->get('/account/passkeys')->assertRedirect('/login');

        $user = User::factory()->create();
        $this->passkeyFor($user, 'My Samsung');
        $this->passkeyFor(User::factory()->create(), 'Someone else');

        $this->actingAs($user)->get('/account/passkeys')->assertInertia(fn (Assert $page) => $page
            ->component('Account/Passkeys')
            ->has('passkeys', 1)
            ->where('passkeys.0.name', 'My Samsung')
            ->where('passwordConfirmed', false));
    }

    public function test_adding_a_device_needs_the_password_first(): void
    {
        $user = User::factory()->create(['password' => 'a-Good-passw0rd!']);

        $this->actingAs($user)->getJson('/user/passkeys/options')->assertStatus(423);

        $this->actingAs($user)->post('/account/passkeys/confirm', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($user)->getJson('/user/passkeys/options')->assertStatus(423);

        $this->actingAs($user)->post('/account/passkeys/confirm', ['current_password' => 'a-Good-passw0rd!'])->assertRedirect();
        $this->getJson('/user/passkeys/options')->assertOk()->assertJsonStructure(['options' => ['challenge']]);
        $this->get('/account/passkeys')->assertInertia(fn (Assert $page) => $page->where('passwordConfirmed', true));
    }

    public function test_the_device_prompt_names_the_account_by_username(): void
    {
        $user = User::factory()->create(['username' => 'jdelacruz']);

        $this->assertSame('jdelacruz', $user->getPasskeyUsername());
    }

    public function test_a_person_can_remove_only_their_own_device_and_it_is_audited(): void
    {
        $user = User::factory()->create(['password' => 'a-Good-passw0rd!']);
        $own = $this->passkeyFor($user, 'My phone');
        $others = $this->passkeyFor(User::factory()->create());

        $this->actingAs($user)->post('/account/passkeys/confirm', ['current_password' => 'a-Good-passw0rd!']);

        $this->delete("/user/passkeys/{$others->id}")->assertForbidden();
        $this->assertModelExists($others);

        $this->delete("/user/passkeys/{$own->id}")->assertRedirect();
        $this->assertModelMissing($own);
        $this->assertDatabaseHas('audit_logs', ['event' => AuditEvent::AuthPasskeyRemoved->value, 'actor_id' => $user->id]);
    }

    public function test_an_inactive_account_cannot_sign_in_with_a_passkey(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Inactive]);

        try {
            PasskeySignIn::authorizeLogin(Request::create('/passkeys/login', 'POST'), $user, $this->passkeyFor($user));
            $this->fail('An inactive account was allowed to sign in.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('inactive', $exception->errors()['credential'][0]);
        }

        $this->assertTrue(AuditLog::query()->where('event', AuditEvent::AuthLoginBlocked->value)->exists());
        $this->assertTrue(PasskeySignIn::authorizeLogin(Request::create('/passkeys/login', 'POST'), User::factory()->create(), $this->passkeyFor($user)));
    }

    public function test_a_passkey_sign_in_starts_the_same_session_as_a_password_sign_in(): void
    {
        $user = User::factory()->create();
        $request = Request::create('/passkeys/login', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.0.0.9']);
        $request->setLaravelSession($this->app->make('session.store'));
        $request->setUserResolver(fn () => $user);

        $response = PasskeySignIn::finishSignIn($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('redirect', $response->getData(true));
        $this->assertTrue(app(SessionLimits::class)->started($request->session()));
        $this->assertSame('portal', $request->session()->get('signed_in'));
        $this->assertTrue(LoginHistory::query()->where('user_id', $user->id)->where('ip_address', '10.0.0.9')->exists());
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_the_login_page_still_loads_and_password_sign_in_is_unchanged(): void
    {
        $user = User::factory()->create(['username' => 'maria', 'password' => 'a-Good-passw0rd!']);
        $this->assertTrue(Hash::check('a-Good-passw0rd!', $user->password));

        $this->get('/login')->assertOk();
        $this->post('/login', ['username' => 'maria', 'password' => 'a-Good-passw0rd!'])->assertRedirect('/dashboard');
    }
}
