<?php

namespace Tests\Feature\Legacy;

use App\Models\Application;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\Legacy\LegacySso;
use App\Support\Legacy\SsoLogout;
use App\Support\Security\AccountLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Signing out of IMIS tells the Laravel systems opened from that session
 * to end theirs (docs/sso-logout.md).
 */
class SsoLogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['legacy.sso.hub_hosts' => ['proctad.example.test']]);

        // One browser session across the launch and the sign-out.
        $this->withCookie(config('session.cookie'), str_repeat('a', 40));
    }

    private function system(string $url = 'https://proctad.example.test/sso'): Application
    {
        return Application::factory()->create(['url' => $url, 'requires_access' => false]);
    }

    /** Launches the system as the user and redeems its token, as the system would. */
    private function signInTo(User $user, Application $system): ?string
    {
        $location = null;
        $this->actingAs($user)->get("/applications/{$system->id}/launch")
            ->assertInertia(function (AssertableInertia $page) use (&$location) {
                $location = $page->toArray()['props']['url'];
            });
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $this->postJson('/api/v1/sso/validate', ['token' => $query['sso_token'], 'audience' => 'proctad.example.test'])
            ->assertOk()->json('user.sid');
    }

    public function test_signing_out_of_imis_signs_out_the_system_with_its_sid(): void
    {
        Http::fake(['proctad.example.test/*' => Http::response(['success' => true])]);
        $user = User::factory()->create();
        $sid = $this->signInTo($user, $this->system());

        $this->assertSame(64, strlen($sid));

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://proctad.example.test/sso/logout'
            && $request['sid'] === $sid);
        $this->assertNotNull(DB::table('sso_sessions')->value('ended_at'));
    }

    public function test_deactivating_the_account_signs_out_the_system(): void
    {
        Http::fake(['proctad.example.test/*' => Http::response(['success' => true])]);
        $user = User::factory()->create();
        $sid = $this->signInTo($user, $this->system());
        DB::table('sessions')->insert([
            'id' => str_repeat('a', 40), 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->getTimestamp(),
        ]);

        $this->assertSame(1, app(AccountLifecycle::class)->ended($user));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sso/logout') && $request['sid'] === $sid);
    }

    public function test_an_idle_expired_session_signs_out_the_system(): void
    {
        Http::fake(['proctad.example.test/*' => Http::response(['success' => true])]);
        $user = User::factory()->create();
        $sid = $this->signInTo($user, $this->system());
        LoginHistory::query()->forceCreate([
            'user_id' => $user->id, 'username' => $user->username, 'session_id' => str_repeat('a', 40), 'logged_in_at' => now(),
        ]);

        config(['session.driver' => 'database']);
        $this->artisan('imis:expire-sessions')->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sso/logout') && $request['sid'] === $sid);
    }

    public function test_a_system_that_is_down_does_not_block_signing_out(): void
    {
        Http::fake(['proctad.example.test/*' => Http::response('down', 500)]);
        $user = User::factory()->create();
        $this->signInTo($user, $this->system());

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_system_is_only_signed_out_once(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $this->signInTo($user, $this->system());

        $this->assertSame(1, app(SsoLogout::class)->signOut(DB::table('sso_sessions')->value('imis_session_id')));
        $this->assertSame(0, app(SsoLogout::class)->signOut(DB::table('sso_sessions')->value('imis_session_id')));
    }

    public function test_finished_sessions_are_pruned(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $this->signInTo($user, $this->system());
        app(SsoLogout::class)->signOut(DB::table('sso_sessions')->value('imis_session_id'));
        $this->travel(2)->days();

        app(LegacySso::class)->prune();

        $this->assertSame(0, DB::table('sso_sessions')->count());
    }
}
