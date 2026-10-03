<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\LoginHistory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Legacy System Administration logs: login history, system launches,
 * security events, and active sessions.
 */
class ActivityLogsTest extends TestCase
{
    use RefreshDatabase;

    // ── Login history ────────────────────────────────────────────────

    public function test_signing_in_and_out_is_recorded(): void
    {
        $user = User::factory()->create();

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0')
            ->post('/login', ['username' => $user->username, 'password' => 'password']);

        $login = LoginHistory::query()->sole();
        $this->assertSame($user->id, $login->user_id);
        $this->assertSame($user->username, $login->username);
        $this->assertNotNull($login->session_id);
        $this->assertNull($login->logged_out_at);

        $this->post('/logout');

        $this->assertNotNull($login->fresh()->logged_out_at);
        $this->assertSame('user', $login->fresh()->ended_by);
    }

    public function test_login_history_page_requires_permission_and_searches_on_the_server(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/login-history')->assertForbidden();

        $this->history('jdcruz');
        $this->history('mreyes');

        $this->actingAs($this->userWith('audit_logs.view'))
            ->get('/admin/login-history?search=jdc')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Logs/LoginHistory')
                ->where('logins.total', 1)
                ->where('logins.data.0.username', 'jdcruz')
                ->where('logins.data.0.status', 'expired')
                ->where('query.search', 'jdc'));
    }

    public function test_csv_export_streams_matching_rows_and_neutralizes_formulas(): void
    {
        $this->history('jdcruz', '=HYPERLINK("http://evil")');

        $response = $this->actingAs($this->userWith('audit_logs.view'))->get('/admin/login-history/export?search=jdcruz');
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBFUsername,", $csv);
        $this->assertStringContainsString('jdcruz', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    // ── System launches ──────────────────────────────────────────────

    public function test_launching_records_the_role_and_redirects_to_the_resolved_url(): void
    {
        $application = Application::factory()->create(['url' => 'https://otrs.example.test']);
        $role = ApplicationRole::factory()->for($application)->create(['name' => 'Encoder', 'launch_url' => 'https://otrs.example.test/encode']);
        $user = ApplicationAccess::factory()->forRole($role)->create()->user;

        $this->actingAs($user)->get("/applications/{$application->id}/launch")
            ->assertRedirect('https://otrs.example.test/encode');

        $this->assertDatabaseHas('system_access_logs', [
            'user_id' => $user->id,
            'application_id' => $application->id,
            'application_name' => $application->name,
            'role_name' => 'Encoder',
        ]);
    }

    public function test_launch_refuses_when_the_user_cannot_open_the_system(): void
    {
        $restricted = Application::factory()->create(['requires_access' => true, 'url' => 'https://x.example.test']);
        $down = Application::factory()->create(['url' => 'https://y.example.test']);
        $down->startMaintenance(null);
        $hidden = Application::factory()->administrative()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get("/applications/{$restricted->id}/launch")
            ->assertRedirect("/applications/{$restricted->id}")
            ->assertSessionHas('error');
        $this->actingAs($user)->get("/applications/{$down->id}/launch")->assertSessionHas('error');
        $this->actingAs($user)->get("/applications/{$hidden->id}/launch")->assertNotFound();

        $this->assertDatabaseCount('system_access_logs', 0);
    }

    public function test_access_logs_page_filters_by_system(): void
    {
        $a = Application::factory()->create(['url' => 'https://a.example.test']);
        $b = Application::factory()->create(['url' => 'https://b.example.test']);
        $user = User::factory()->create();
        $this->actingAs($user)->get("/applications/{$a->id}/launch");
        $this->actingAs($user)->get("/applications/{$b->id}/launch");

        $this->actingAs($this->userWith('audit_logs.view'))
            ->get("/admin/access-logs?application_id={$a->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.total', 1)
                ->where('logs.data.0.application_name', $a->name)
                ->where('logs.data.0.role_name', null));
    }

    // ── Security logs ────────────────────────────────────────────────

    public function test_security_logs_list_failed_sign_ins_and_top_ips(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 3) as $attempt) {
            $this->post('/login', ['username' => $user->username, 'password' => 'wrong']);
        }

        $this->actingAs($this->userWith('audit_logs.view'))
            ->get('/admin/security-logs')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.total', 3)
                ->where('events.data.0.severity', 'warning')
                ->where('topIps.0.ip_address', '127.0.0.1')
                ->where('topIps.0.attempts', 3));
    }

    // ── Active sessions ──────────────────────────────────────────────

    public function test_active_sessions_lists_live_sessions_without_exposing_ids(): void
    {
        config(['session.driver' => 'database']);
        $owner = User::factory()->create();
        $this->storeSession($owner, 'live-session-id');
        $this->storeSession(User::factory()->create(), 'stale-session-id', now()->subDay()->getTimestamp());

        $response = $this->actingAs($this->userWith('sessions.view'))->get('/admin/sessions');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Sessions/Index')
            ->has('sessions', 1)
            ->where('sessions.0.user.id', $owner->id)
            ->where('sessions.0.key', hash('sha256', 'live-session-id')));
        $this->assertStringNotContainsString('live-session-id', $response->getContent());
    }

    public function test_force_logout_ends_the_session_and_blocks_remember_me(): void
    {
        config(['session.driver' => 'database']);
        $owner = User::factory()->create(['remember_token' => 'old-token']);
        $this->storeSession($owner, 'their-session');
        $this->history($owner->username, null, 'their-session', $owner->id);

        $this->actingAs($this->userWith('sessions.revoke'))
            ->delete('/admin/sessions/'.hash('sha256', 'their-session'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('sessions', ['id' => 'their-session']);
        $this->assertNotSame('old-token', $owner->fresh()->remember_token);
        $this->assertSame('admin', LoginHistory::query()->sole()->ended_by);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.session_ended', 'target_id' => $owner->id]);
    }

    public function test_only_a_super_admin_can_end_a_super_admins_session(): void
    {
        config(['session.driver' => 'database']);
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::factory()->create(['name' => RoleName::SuperAdmin->value]));
        $this->storeSession($owner, 'protected-session');

        $this->actingAs($this->userWith('sessions.revoke'))
            ->delete('/admin/sessions/'.hash('sha256', 'protected-session'))
            ->assertForbidden();

        $this->assertDatabaseHas('sessions', ['id' => 'protected-session']);
    }

    private function storeSession(User $user, string $id, ?int $lastActivity = null): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '10.0.0.5',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0',
            'payload' => base64_encode(serialize([])),
            'last_activity' => $lastActivity ?? now()->getTimestamp(),
        ]);
    }

    private function history(string $username, ?string $agent = null, ?string $sessionId = null, ?int $userId = null): void
    {
        LoginHistory::query()->forceCreate([
            'user_id' => $userId,
            'username' => $username,
            'session_id' => $sessionId,
            'ip_address' => '10.0.0.5',
            'user_agent' => $agent,
            'logged_in_at' => now(),
        ]);
    }

    private function userWith(string $permission): User
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
