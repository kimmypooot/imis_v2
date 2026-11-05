<?php

namespace Tests\Feature\HR;

use App\Enums\UserStatus;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Recruitment Portal's server-to-server read of the staff who hold an Admin
 * or HRMPSB role in it, for its Users page.
 */
class RecruitmentUsersApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['recruitment.api_token' => 'secret-token', 'recruitment.application' => 'ORS']);
    }

    public function test_the_endpoint_needs_the_shared_token(): void
    {
        $this->getJson('/api/v1/recruitment/users')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/v1/recruitment/users')->assertUnauthorized();
    }

    public function test_it_lists_active_staff_with_their_portal_role_only(): void
    {
        $unit = OrganizationalUnit::factory()->create(['code' => 'HRD', 'name' => 'Human Resource Division']);
        $ors = Application::factory()->create(['short_name' => 'ORS']);
        $other = Application::factory()->create(['short_name' => 'OTRS']);
        $admin = ApplicationRole::factory()->for($ors)->create(['name' => 'Admin', 'code' => 'admin']);
        $board = ApplicationRole::factory()->for($ors)->create(['name' => 'HRMPSB', 'code' => 'hrmpsb']);
        $plain = ApplicationRole::factory()->for($ors)->create(['name' => 'User', 'code' => null]);
        $encoder = ApplicationRole::factory()->for($other)->create(['code' => 'encoder']);

        $ana = ApplicationAccess::factory()->forRole($admin)->create()->user;
        $ana->forceFill(['organizational_unit_id' => $unit->id, 'position' => 'HRMO III'])->save();
        $ben = ApplicationAccess::factory()->forRole($board)->create()->user;

        ApplicationAccess::factory()->forRole($board)->create()->user->forceFill(['status' => UserStatus::Inactive])->save(); // left
        ApplicationAccess::factory()->forRole($plain)->create();   // a role with no code is not a portal role
        ApplicationAccess::factory()->forRole($encoder)->create(); // another system
        User::factory()->create();                                 // no access at all

        $data = $this->withToken('secret-token')->getJson('/api/v1/recruitment/users')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing([$ana->id, $ben->id], array_column($data, 'id'));
        $row = collect($data)->firstWhere('id', $ana->id);
        $this->assertSame('admin', $row['role_code']);
        $this->assertSame('HRMO III', $row['position']);
        $this->assertSame('HRD', $row['office_code']);
        $this->assertSame($ana->email, $row['email']);
    }

    public function test_it_is_empty_when_the_portal_is_not_in_the_directory(): void
    {
        $this->withToken('secret-token')->getJson('/api/v1/recruitment/users')->assertOk()->assertExactJson(['data' => []]);
    }
}
