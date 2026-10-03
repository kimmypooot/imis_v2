<?php

namespace Tests\Feature\Legacy;

use App\Enums\UserStatus;
use App\Models\Application;
use App\Models\User;
use App\Support\Legacy\LegacySso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The one-time sign-in handed to legacy subsystems on launch, and its
 * server-to-server redemption (the contract of legacy session.php).
 */
class LegacySsoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['legacy.sso.hosts' => ['legacy.example.test'], 'legacy.bridge' => false]);
    }

    private function person(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'legacy_id' => 42, 'first_name' => 'Juan', 'middle_name' => 'Dela', 'last_name' => 'Cruz', 'position' => 'Analyst',
        ]);
    }

    private function system(string $url): Application
    {
        return Application::factory()->create(['url' => $url, 'requires_access' => false]);
    }

    /** Launches the system and returns the token on the redirect, if any. */
    private function launch(User $user, Application $system): ?string
    {
        $location = $this->actingAs($user)->get("/applications/{$system->id}/launch")->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query['sso_token'] ?? null;
    }

    private function redeem(?string $token, array $extra = [])
    {
        return $this->postJson('/api/v1/sso/validate', ['token' => $token] + $extra);
    }

    public function test_a_legacy_subsystem_launch_signs_the_person_in_once(): void
    {
        $user = $this->person();
        $token = $this->launch($user, $this->system('https://legacy.example.test/otrs/index?x=1'));

        $this->assertNotNull($token);
        $this->assertSame(1, DB::table('sso_tokens')->count());
        $this->assertNotSame($token, DB::table('sso_tokens')->value('token_hash'));

        $this->redeem($token, ['path' => '/otrs/index'])
            ->assertOk()
            ->assertJson(['success' => true, 'user' => [
                'id' => 42, 'username' => $user->username, 'fname' => 'Juan', 'lname' => 'Cruz', 'minitial' => 'D.',
                'global_role' => 'user', 'email' => $user->email, 'position' => 'Analyst',
            ]]);

        $this->redeem($token)->assertUnauthorized()->assertJsonPath('success', false);
    }

    public function test_other_hosts_never_get_a_token(): void
    {
        $this->assertNull($this->launch($this->person(), $this->system('https://elsewhere.example.test/otrs')));
        $this->assertSame(0, DB::table('sso_tokens')->count());
    }

    public function test_an_account_without_a_legacy_identity_gets_no_token(): void
    {
        $this->assertNull($this->launch($this->person(['legacy_id' => null]), $this->system('https://legacy.example.test/otrs')));
    }

    public function test_a_token_expires(): void
    {
        $token = $this->launch($this->person(), $this->system('https://legacy.example.test/otrs'));

        $this->travel(61)->seconds();

        $this->redeem($token)->assertUnauthorized();
    }

    public function test_a_token_only_works_for_its_own_system(): void
    {
        $token = $this->launch($this->person(), $this->system('https://legacy.example.test/otrs/index'));

        $this->redeem($token, ['path' => '/cts/index'])->assertUnauthorized();
        $this->redeem($token, ['path' => '/OTRS/admin'])->assertOk();
    }

    public function test_the_system_must_be_named_once_required(): void
    {
        config(['legacy.sso.require_path' => true]);
        $token = $this->launch($this->person(), $this->system('https://legacy.example.test/otrs'));

        $this->redeem($token)->assertUnauthorized();
    }

    public function test_a_deactivated_account_cannot_redeem(): void
    {
        $user = $this->person();
        $token = $this->launch($user, $this->system('https://legacy.example.test/otrs'));

        $user->update(['status' => UserStatus::Inactive]);

        $this->redeem($token)->assertUnauthorized();
    }

    public function test_garbage_is_rejected(): void
    {
        $this->redeem(null)->assertUnauthorized();
        $this->redeem('not-a-token')->assertUnauthorized();
        $this->postJson('/api/v1/sso/validate', ['token' => ['array']])->assertUnauthorized();
    }

    public function test_old_tokens_are_pruned(): void
    {
        $this->launch($this->person(), $this->system('https://legacy.example.test/otrs'));
        $this->travel(2)->days();

        $this->assertSame(1, app(LegacySso::class)->prune());
        $this->assertSame(0, DB::table('sso_tokens')->count());
    }
}
