<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ICT SRTS's server-to-server read of the current employees, for its ICT
 * Group and form signatories.
 */
class IctSrtsEmployeesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ictsrts.api_token' => 'secret-token']);
    }

    public function test_the_endpoint_needs_the_shared_token(): void
    {
        $this->getJson('/api/v1/ictsrts/employees')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/v1/ictsrts/employees')->assertUnauthorized();

        config(['ictsrts.api_token' => null]);
        $this->withToken('')->getJson('/api/v1/ictsrts/employees')->assertUnauthorized();
    }

    public function test_it_lists_active_employees_with_their_office(): void
    {
        $office = OrganizationalUnit::factory()->create(['code' => 'LFO-I', 'name' => 'Leyte Field Office', 'unit_type' => UnitType::FieldOffice]);
        $section = OrganizationalUnit::factory()->create(['parent_id' => $office->id, 'unit_type' => UnitType::Other]);

        User::factory()->create(['name' => 'Ana Reyes', 'position' => 'ITO I', 'organizational_unit_id' => $section->id]);
        User::factory()->create(['name' => 'Gone Person', 'status' => UserStatus::Inactive]);

        $data = $this->withToken('secret-token')->getJson('/api/v1/ictsrts/employees')->assertOk()->json('data');

        $this->assertSame(['Ana Reyes'], array_column($data, 'name'));
        $this->assertSame('Leyte Field Office', $data[0]['office_name']);
        $this->assertSame('LFO-I', $data[0]['office_code']);
        $this->assertSame('ITO I', $data[0]['position']);
    }

    public function test_only_active_members_of_the_itg_committee_today_are_flagged_as_itg(): void
    {
        $itg = Committee::factory()->create(['short_name' => 'ITG']);
        $other = Committee::factory()->create(['short_name' => 'GAD']);

        $member = fn (string $name, Committee $committee, array $attributes = []) => CommitteeMember::factory()->create([
            'committee_id' => $committee->id,
            'user_id' => User::factory()->create(['name' => $name])->id,
            'effective_from' => null,
            ...$attributes,
        ]);

        $member('Current', $itg);
        $member('Dated', $itg, ['effective_from' => now()->subYear()->toDateString(), 'effective_until' => now()->addYear()->toDateString()]);
        $member('Ended', $itg, ['effective_until' => now()->subDay()->toDateString()]);
        $member('Not yet', $itg, ['effective_from' => now()->addDay()->toDateString()]);
        $member('Inactive', $itg, ['status' => Status::Inactive]);
        $member('Other committee', $other);

        $data = collect($this->withToken('secret-token')->getJson('/api/v1/ictsrts/employees')->json('data'))->pluck('in_itg', 'name');

        $this->assertSame(
            ['Current' => true, 'Dated' => true, 'Ended' => false, 'Not yet' => false, 'Inactive' => false, 'Other committee' => false],
            $data->only(['Current', 'Dated', 'Ended', 'Not yet', 'Inactive', 'Other committee'])->all(),
        );
    }
}
