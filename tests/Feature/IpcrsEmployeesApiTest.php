<?php

namespace Tests\Feature;

use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IPCRS's server-to-server read of the current employees, for naming people
 * on a form and cascading rows to them.
 */
class IpcrsEmployeesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ipcrs.api_token' => 'secret-token']);
    }

    public function test_the_endpoint_needs_the_shared_token(): void
    {
        $this->getJson('/api/v1/ipcrs/employees')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/v1/ipcrs/employees')->assertUnauthorized();

        config(['ipcrs.api_token' => null]);
        $this->withToken('')->getJson('/api/v1/ipcrs/employees')->assertUnauthorized();
    }

    public function test_it_says_who_is_on_the_pmt(): void
    {
        config(['ipcrs.pmt_committee' => 'PMT']);
        $committee = Committee::factory()->create(['short_name' => 'PMT']);
        $member = User::factory()->create(['name' => 'Ana Member']);
        User::factory()->create(['name' => 'Ben Other']);
        CommitteeMember::factory()->create(['committee_id' => $committee->id, 'user_id' => $member->id, 'position' => 'Chairperson', 'effective_from' => null]);

        $rows = collect($this->withToken('secret-token')->getJson('/api/v1/ipcrs/employees')->json('data'));
        $flags = $rows->pluck('in_pmt', 'name');
        $this->assertSame('Chairperson', $rows->firstWhere('name', 'Ana Member')['pmt_position']);
        $this->assertNull($rows->firstWhere('name', 'Ben Other')['pmt_position']);

        $this->assertTrue($flags['Ana Member']);
        $this->assertFalse($flags['Ben Other']);
    }

    public function test_it_lists_active_employees_with_their_office(): void
    {
        $office = OrganizationalUnit::factory()->create(['code' => 'LFO-I', 'name' => 'Leyte Field Office', 'unit_type' => UnitType::FieldOffice]);
        $section = OrganizationalUnit::factory()->create(['parent_id' => $office->id, 'unit_type' => UnitType::Other]);

        User::factory()->create(['name' => 'Ana Reyes', 'position' => 'Administrative Aide', 'organizational_unit_id' => $section->id, 'profile_photo_path' => 'profile-photos/ana.jpg']);
        User::factory()->create(['name' => 'Gone Person', 'status' => UserStatus::Inactive]);

        $data = $this->withToken('secret-token')->getJson('/api/v1/ipcrs/employees')->assertOk()->json('data');

        $this->assertSame(['Ana Reyes'], array_column($data, 'name'));
        $this->assertSame('Leyte Field Office', $data[0]['office_name']);
        $this->assertSame('LFO-I', $data[0]['office_code']);
        $this->assertSame('Administrative Aide', $data[0]['position']);
        $this->assertStringEndsWith('profile-photos/ana.jpg', $data[0]['photo_url']);
    }
}
