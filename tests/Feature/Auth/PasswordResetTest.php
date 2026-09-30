<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_page_renders(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertOk();
    }

    public function test_reset_link_request_is_accepted_for_existing_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->post('/forgot-password', ['email' => $user->email]);

        $response->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_link_request_does_not_reveal_whether_account_exists(): void
    {
        Notification::fake();

        $existing = $this->post('/forgot-password', ['email' => User::factory()->create()->email]);
        $missing = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame(
            $existing->getSession()->get('status'),
            $missing->getSession()->get('status')
        );
    }

    public function test_forgot_password_validates_email(): void
    {
        $response = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'not-an-email']);

        $response->assertSessionHasErrors('email');
    }

    public function test_reset_password_page_renders_with_valid_token(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->get("/reset-password/{$token}?email={$user->email}");

        $response->assertOk();
    }

    public function test_user_can_reset_password_with_valid_token(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertRedirect(route('login', absolute: false));
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_password_reset_fails_with_invalid_token(): void
    {
        $user = User::factory()->create();

        $response = $this->from('/reset-password/invalid-token')->post('/reset-password', [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_reset_fails_with_expired_token(): void
    {
        config(['auth.passwords.users.expire' => 0]);

        $user = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->from("/reset-password/{$token}")->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_reset_requires_matching_confirmation(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->from("/reset-password/{$token}")->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertSessionHasErrors('password');
    }
}
