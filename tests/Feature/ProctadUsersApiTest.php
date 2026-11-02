<?php

namespace Tests\Feature;

use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROCTAD's server-to-server read of the people who hold a PROCTAD role, for
 * its Users page.
 */
class ProctadUsersApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['proctad.api_token' => 'secret-token', 'proctad.application' => 'PROCTAD']);
    }

    public function test_the_endpoint_needs_the_shared_token(): void
    {
        $this->getJson('/api/v1/proctad/users')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/v1/proctad/users')->assertUnauthorized();

        config(['proctad.api_token' => null]);
        $this->withToken('')->getJson('/api/v1/proctad/users')->assertUnauthorized();
    }

    public function test_it_lists_active_people_with_their_proctad_role_and_office(): void
    {
        $office = OrganizationalUnit::factory()->create(['code' => 'LFO-I', 'name' => 'Leyte Field Office', 'unit_type' => UnitType::FieldOffice]);
        $section = OrganizationalUnit::factory()->create(['parent_id' => $office->id, 'unit_type' => UnitType::Other]);

        $proctad = Application::factory()->create(['short_name' => 'PROCTAD']);
        $other = Application::factory()->create(['short_name' => 'OTRS']);
        $fo = ApplicationRole::factory()->for($proctad)->create(['name' => 'Field Office Admin', 'code' => 'fo_admin']);
        $encoder = ApplicationRole::factory()->for($other)->create(['code' => 'encoder']);

        $ana = ApplicationAccess::factory()->forRole($fo)->create()->user;
        $ana->forceFill(['organizational_unit_id' => $section->id, 'position' => 'AO II'])->save();

        $gone = ApplicationAccess::factory()->forRole($fo)->create()->user;
        $gone->forceFill(['status' => UserStatus::Inactive])->save();

        ApplicationAccess::factory()->forRole($encoder)->create();     // another system
        User::factory()->create();                                      // no access at all

        $data = $this->withToken('secret-token')->getJson('/api/v1/proctad/users')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($ana->id, $data[0]['id']);
        $this->assertSame('fo_admin', $data[0]['role_code']);
        $this->assertSame('LFO-I', $data[0]['office_code']);
        $this->assertSame('field_office', $data[0]['office_type']);
        $this->assertSame('Leyte Field Office', $data[0]['office_name']);
        $this->assertSame('AO II', $data[0]['position']);
    }

    public function test_it_is_empty_when_proctad_is_not_in_the_directory(): void
    {
        $this->withToken('secret-token')->getJson('/api/v1/proctad/users')->assertOk()->assertExactJson(['data' => []]);
    }
}
