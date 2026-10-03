<?php

namespace Tests\Feature\Auth;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The legacy session limits: idle timeout with a browser keep-alive, an
 * absolute cap per sign-in, and closing the history of expired sessions.
 */
class SessionLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(array $extra = []): User
    {
        $user = User::factory()->create();
        $this->post('/login', ['username' => $user->username, 'password' => 'password', ...$extra]);

        return $user;
    }

    /** Hourly activity, so the idle timeout never kicks in. */
    private function stayActiveFor(int $hours): void
    {
        foreach (range(1, $hours) as $hour) {
            $this->travel(1)->hours();
            $this->get('/dashboard');
        }
    }

    public function test_limits_are_shared_with_the_browser(): void
    {
        // A second can pass between signing in and the request, which
        // would make the remaining time 28 799 instead of 28 800.
        $this->freezeTime();
        $this->signIn();

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('sessionLimits.idle_seconds', 120 * 60)
            ->where('sessionLimits.absolute_remaining', 480 * 60)
            ->where('sessionLimits.warning_seconds', 5 * 60));
    }

    public function test_a_sign_in_ends_at_the_absolute_limit_however_active(): void
    {
        $this->signIn();

        $this->stayActiveFor(7);
        $this->assertAuthenticated();

        $this->travel(61)->minutes();

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame('timeout', LoginHistory::query()->sole()->ended_by);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('status', 'Your session reached its time limit. Please sign in again.'));
    }

    public function test_heartbeat_keeps_the_session_and_reports_the_limits(): void
    {
        $this->signIn();
        $this->travel(2)->hours();

        $this->postJson('/session/heartbeat')
            ->assertOk()
            ->assertJsonPath('alive', true)
            ->assertJsonPath('limits.absolute_remaining', 360 * 60);
    }

    public function test_heartbeat_answers_401_once_the_limit_has_passed(): void
    {
        $this->signIn();
        $this->stayActiveFor(7);
        $this->travel(61)->minutes();

        $this->postJson('/session/heartbeat')->assertUnauthorized()->assertJsonPath('reason', 'limit');
        $this->assertGuest();
    }

    public function test_heartbeat_requires_a_session(): void
    {
        $this->postJson('/session/heartbeat')->assertUnauthorized();
    }

    public function test_remember_me_sign_ins_are_exempt(): void
    {
        $this->signIn(['remember' => 'on']);

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('sessionLimits', null));

        $this->stayActiveFor(9);
        $this->assertAuthenticated();
    }

    public function test_login_page_explains_an_idle_sign_out(): void
    {
        $this->get('/login?reason=idle')->assertInertia(fn (Assert $page) => $page
            ->where('status', 'You were signed out after a period of inactivity.'));

        $this->get('/login?reason=other')->assertInertia(fn (Assert $page) => $page->where('status', null));
    }

    public function test_expired_sessions_are_closed_in_the_login_history(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();

        $live = $this->history($user, 'live-session', lastActivity: now()->subMinutes(10));
        $idle = $this->history($user, 'idle-session', lastActivity: now()->subMinutes(130));
        $gone = $this->history($user, 'gone-session');

        $this->artisan('imis:expire-sessions')->assertSuccessful();

        $this->assertNull($live->fresh()->logged_out_at);

        $this->assertSame('timeout', $idle->fresh()->ended_by);
        $this->assertTrue($idle->fresh()->logged_out_at->equalTo(now()->subMinutes(10)->startOfSecond()));

        $this->assertSame('timeout', $gone->fresh()->ended_by);
        $this->assertSame('expired', $gone->fresh()->statusFor(false));
    }

    private function history(User $user, string $sessionId, $lastActivity = null): LoginHistory
    {
        if ($lastActivity) {
            DB::table('sessions')->insert([
                'id' => $sessionId,
                'user_id' => $user->id,
                'payload' => '',
                'last_activity' => $lastActivity->getTimestamp(),
            ]);
        }

        return LoginHistory::query()->forceCreate([
            'user_id' => $user->id,
            'username' => $user->username,
            'session_id' => $sessionId,
            'logged_in_at' => now()->subHours(3),
        ]);
    }
}
