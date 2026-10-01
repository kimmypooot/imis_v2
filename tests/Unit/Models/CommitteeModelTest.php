<?php

namespace Tests\Unit\Models;

use App\Enums\Status;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommitteeModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_committee_has_many_members(): void
    {
        $committee = Committee::factory()->create();
        $member = CommitteeMember::factory()->for($committee)->create();

        $this->assertTrue($committee->members->contains($member));
    }

    public function test_member_belongs_to_a_user_without_duplicating_identity_data(): void
    {
        $user = User::factory()->create(['name' => 'Juan Dela Cruz']);
        $member = CommitteeMember::factory()->for($user)->create();

        $this->assertTrue($member->user->is($user));
        $this->assertSame('Juan Dela Cruz', $member->user->name);
    }

    public function test_active_members_scope_excludes_inactive_and_ended_memberships(): void
    {
        $committee = Committee::factory()->create();
        CommitteeMember::factory()->for($committee)->create();
        CommitteeMember::factory()->for($committee)->inactive()->create();
        CommitteeMember::factory()->for($committee)->ended()->create();

        $this->assertCount(1, $committee->activeMembers);
    }

    public function test_committee_active_scope_excludes_inactive_committees(): void
    {
        Committee::factory()->create();
        Committee::factory()->inactive()->create();

        $this->assertCount(1, Committee::query()->active()->get());
    }

    public function test_deleting_a_committee_cascades_to_its_members(): void
    {
        $committee = Committee::factory()->create();
        $member = CommitteeMember::factory()->for($committee)->create();

        $committee->delete();

        $this->assertDatabaseMissing('committee_members', ['id' => $member->id]);
    }

    public function test_status_casts_to_the_shared_status_enum(): void
    {
        $committee = Committee::factory()->create();
        $member = CommitteeMember::factory()->for($committee)->create();

        $this->assertInstanceOf(Status::class, $committee->status);
        $this->assertInstanceOf(Status::class, $member->status);
    }
}
