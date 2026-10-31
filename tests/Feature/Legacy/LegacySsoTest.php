<?php

namespace Tests\Feature\Legacy;

use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Support\Legacy\LegacySso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
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
        $location = null;
        $this->actingAs($user)->get("/applications/{$system->id}/launch")
            ->assertInertia(function (AssertableInertia $page) use (&$location) {
                $page->component('Portal/Applications/Launching');
                $location = $page->toArray()['props']['url'];
            });
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

    public function test_a_laravel_system_under_imis_signs_the_person_in_once_with_their_role(): void
    {
        config(['legacy.sso.hub_hosts' => ['proctad.example.test:8001']]);
        $system = $this->system('http://proctad.example.test:8001/sso');
        $role = ApplicationRole::factory()->for($system)->create(['name' => 'Administrator (ESD)']);
        $user = ApplicationAccess::factory()->forRole($role)->create()->user;

        $token = $this->launch($user, $system);

        $this->assertNotNull($token);
        $this->redeem($token, ['audience' => 'other.example.test'])->assertUnauthorized();
        $this->redeem($token, ['audience' => 'PROCTAD.example.test:8001'])
            ->assertOk()
            ->assertJsonPath('user.username', $user->username)
            ->assertJsonPath('user.role', 'Administrator (ESD)');
        $this->redeem($token, ['audience' => 'proctad.example.test:8001'])->assertUnauthorized();
    }

    public function test_a_laravel_system_gets_the_role_code_and_the_office_of_the_person(): void
    {
        config(['legacy.sso.hub_hosts' => ['proctad.example.test']]);
        $system = $this->system('https://proctad.example.test/sso');
        $role = ApplicationRole::factory()->for($system)->create(['name' => 'Field Office Staff', 'code' => 'fo_admin']);
        $office = OrganizationalUnit::factory()->create(['code' => 'LFO-I', 'unit_type' => UnitType::FieldOffice]);
        $section = OrganizationalUnit::factory()->create(['parent_id' => $office->id, 'unit_type' => UnitType::Other]);
        $user = ApplicationAccess::factory()->forRole($role)->create()->user;
        $user->forceFill(['organizational_unit_id' => $section->id, 'profile_photo_path' => 'profile-photos/x.jpg'])->save();

        $token = $this->launch($user, $system);

        $this->redeem($token, ['audience' => 'proctad.example.test'])
            ->assertOk()
            ->assertJsonPath('user.role_code', 'fo_admin')
            ->assertJsonPath('user.office_name', $office->name)
            ->assertJsonPath('user.itg_member', false)
            ->assertJsonPath('user.office_code', 'LFO-I')
            ->assertJsonPath('user.office_type', 'field_office')
            ->assertJsonPath('user.photo_url', Storage::disk('public')->url('profile-photos/x.jpg'));
    }

    public function test_a_hub_system_is_told_whether_the_person_is_on_the_itg_committee(): void
    {
        config(['legacy.sso.hub_hosts' => ['ictsrts.example.test'], 'ictsrts.itg_committee' => 'ITG']);
        $system = $this->system('https://ictsrts.example.test/sso');
        $role = ApplicationRole::factory()->for($system)->create(['code' => 'staff']);
        $committee = Committee::factory()->create(['short_name' => 'ITG']);

        $member = ApplicationAccess::factory()->forRole($role)->create()->user;
        CommitteeMember::factory()->create(['committee_id' => $committee->id, 'user_id' => $member->id, 'effective_from' => null]);
        $other = ApplicationAccess::factory()->forRole($role)->create()->user;

        $this->redeem($this->launch($member, $system), ['audience' => 'ictsrts.example.test'])->assertJsonPath('user.itg_member', true);
        $this->redeem($this->launch($other, $system), ['audience' => 'ictsrts.example.test'])->assertJsonPath('user.itg_member', false);
    }

    public function test_a_hub_system_is_told_whether_the_person_is_on_the_pmt_committee(): void
    {
        config(['legacy.sso.hub_hosts' => ['ipcrs.example.test'], 'ipcrs.pmt_committee' => 'PMT']);
        $system = $this->system('https://ipcrs.example.test/sso');
        $role = ApplicationRole::factory()->for($system)->create(['code' => 'staff']);
        $pmt = Committee::factory()->create(['short_name' => 'PMT']);
        $other = Committee::factory()->create(['short_name' => 'GAD']);

        $member = ApplicationAccess::factory()->forRole($role)->create()->user;
        CommitteeMember::factory()->create(['committee_id' => $pmt->id, 'user_id' => $member->id, 'position' => 'Secretariat', 'effective_from' => null]);
        CommitteeMember::factory()->create(['committee_id' => $pmt->id, 'user_id' => $member->id, 'position' => 'Alternate', 'effective_from' => null]);
        $elsewhere = ApplicationAccess::factory()->forRole($role)->create()->user;
        CommitteeMember::factory()->create(['committee_id' => $other->id, 'user_id' => $elsewhere->id, 'effective_from' => null]);
        $ended = ApplicationAccess::factory()->forRole($role)->create()->user;
        CommitteeMember::factory()->create(['committee_id' => $pmt->id, 'user_id' => $ended->id, 'effective_from' => null, 'effective_until' => now()->subDay()->toDateString()]);

        $this->redeem($this->launch($member, $system), ['audience' => 'ipcrs.example.test'])->assertJsonPath('user.pmt_member', true)->assertJsonPath('user.pmt_position', 'Secretariat, Alternate');
        $this->redeem($this->launch($elsewhere, $system), ['audience' => 'ipcrs.example.test'])->assertJsonPath('user.pmt_member', false)->assertJsonPath('user.pmt_position', null);
        $this->redeem($this->launch($ended, $system), ['audience' => 'ipcrs.example.test'])->assertJsonPath('user.pmt_member', false);
    }

    public function test_a_hub_token_cannot_be_redeemed_as_a_legacy_one_or_without_an_audience(): void
    {
        config(['legacy.sso.hub_hosts' => ['proctad.example.test']]);
        $token = $this->launch($this->person(), $this->system('https://proctad.example.test/sso'));

        $this->redeem($token)->assertUnauthorized();
        $this->redeem($token, ['path' => '/sso'])->assertUnauthorized();
        $this->redeem($token, ['audience' => 'proctad.example.test'])->assertOk()->assertJsonPath('user.role', null);
    }

    public function test_a_legacy_token_cannot_be_redeemed_by_a_laravel_system(): void
    {
        $token = $this->launch($this->person(), $this->system('https://legacy.example.test/otrs'));

        $this->redeem($token, ['audience' => 'legacy.example.test'])->assertUnauthorized();
    }
}
