<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/account/password')->assertRedirect('/login');
    }

    public function test_user_can_change_their_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'password',
            'password' => 'New-Secret-123',
            'password_confirmation' => 'New-Secret-123',
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertTrue(Hash::check('New-Secret-123', $user->fresh()->password));

        $log = AuditLog::query()->where('event', 'user.password_changed')->sole();
        $this->assertStringNotContainsString('New-Secret-123', json_encode($log->getAttributes()));
    }

    public function test_current_password_must_be_correct(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'wrong-one',
            'password' => 'New-Secret-123',
            'password_confirmation' => 'New-Secret-123',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_new_password_follows_the_policy_and_must_be_confirmed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'password',
            'password' => 'New-Secret-123',
            'password_confirmation' => 'Different-123',
        ])->assertSessionHasErrors('password');
    }

    public function test_shared_props_include_username_last_login_and_version(): void
    {
        $user = User::factory()->create(['username' => 'jdcruz', 'previous_login_at' => now()->subDay()]);

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.username', 'jdcruz')
            ->where('auth.user.previous_login_at', fn ($value) => $value !== null)
            ->where('app.version', config('app.version')));
    }

    public function test_sign_in_flags_the_welcome_splash_once(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['username' => $user->username, 'password' => 'password']);

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('flash.signedIn', 'portal'));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('flash.signedIn', null));
    }
}
