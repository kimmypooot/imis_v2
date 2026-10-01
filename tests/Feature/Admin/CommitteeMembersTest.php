<?php

namespace Tests\Feature\Admin;

use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommitteeMembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_user_cannot_add_a_member(): void
    {
        $user = User::factory()->create();
        $committee = Committee::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($user)->post("/admin/committees/{$committee->id}/members", [
            'user_id' => $target->id,
            'position' => 'Member',
            'effective_from' => '2026-01-01',
            'status' => 'active',
        ])->assertForbidden();

        $this->assertDatabaseMissing('committee_members', ['committee_id' => $committee->id, 'user_id' => $target->id]);
    }

    public function test_authorized_user_can_add_a_member(): void
    {
        $actor = $this->userWithPermission('committees.update');
        $committee = Committee::factory()->create();
        $target = User::factory()->create();

        $response = $this->actingAs($actor)->post("/admin/committees/{$committee->id}/members", [
            'user_id' => $target->id,
            'position' => 'Chairperson',
            'effective_from' => '2026-01-01',
            'status' => 'active',
        ]);

        $response->assertRedirect("/admin/committees/{$committee->id}");
        $this->assertDatabaseHas('committee_members', [
            'committee_id' => $committee->id,
            'user_id' => $target->id,
            'position' => 'Chairperson',
            'status' => 'active',
        ]);
    }

    public function test_required_fields_are_validated_when_adding_a_member(): void
    {
        $actor = $this->userWithPermission('committees.update');
        $committee = Committee::factory()->create();

        $response = $this->actingAs($actor)->post("/admin/committees/{$committee->id}/members", []);

        $response->assertSessionHasErrors(['user_id', 'position', 'effective_from', 'status']);
    }

    public function test_effective_until_must_not_precede_effective_from_for_a_member(): void
    {
        $actor = $this->userWithPermission('committees.update');
        $committee = Committee::factory()->create();
        $target = User::factory()->create();

        $response = $this->actingAs($actor)->post("/admin/committees/{$committee->id}/members", [
            'user_id' => $target->id,
            'position' => 'Member',
            'effective_from' => '2026-06-01',
            'effective_until' => '2026-01-01',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('effective_until');
    }

    public function test_nonexistent_user_is_rejected(): void
    {
        $actor = $this->userWithPermission('committees.update');
        $committee = Committee::factory()->create();

        $response = $this->actingAs($actor)->post("/admin/committees/{$committee->id}/members", [
            'user_id' => 999999,
            'position' => 'Member',
            'effective_from' => '2026-01-01',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('user_id');
    }

    public function test_a_user_cannot_have_two_active_memberships_on_the_same_committee(): void
    {
        $actor = $this->userWithPermission('committees.update');
        $committee = Committee::factory()->create();
        $target = User::factory()->create();
        CommitteeMember::factory()->for($committee)->for($target)->create();

        $response = $this->actingAs($actor)->post("/admin/committees/{$committee->id}/members", [
            'user_id' => $target->id,
            'position' => 'Secretary',
            'effective_from' => '2026-01-01',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('user_id');
        $this->assertSame(1, CommitteeMember::where('committee_id', $committee->id)
            ->where('user_id', $target->id)
            ->count());
    }

    public function test_a_user_can_have_a_new_membership_once_their_prior_one_ended(): void
    {
        $actor = $this->userWithPermission('committees.update');
        $committee = Committee::factory()->create();
        $target = User::factory()->create();
        CommitteeMember::factory()->for($committee)->for($target)->ended()->create();

        $response = $this->actingAs($actor)->post("/admin/committees/{$committee->id}/members", [
            'user_id' => $target->id,
            'position' => 'Secretary',
            'effective_from' => '2026-01-01',
            'status' => 'active',
        ]);

        $response->assertRedirect();
        $this->assertSame(2, CommitteeMember::where('committee_id', $committee->id)
            ->where('user_id', $target->id)
            ->count());
    }

    public function test_a_user_can_be_active_on_two_different_committees_at_once(): void
    {
        $actor = $this->userWithPermission('committees.update');
        $committeeA = Committee::factory()->create();
        $committeeB = Committee::factory()->create();
        $target = User::factory()->create();
        CommitteeMember::factory()->for($committeeA)->for($target)->create();

        $response = $this->actingAs($actor)->post("/admin/committees/{$committeeB->id}/members", [
            'user_id' => $target->id,
            'position' => 'Member',
            'effective_from' => '2026-01-01',
            'status' => 'active',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('committee_members', ['committee_id' => $committeeB->id, 'user_id' => $target->id]);
    }

    public function test_authorized_user_can_update_a_members_position_and_dates(): void
    {
        $actor = $this->userWithPermission('committees.update');
        $committee = Committee::factory()->create();
        $member = CommitteeMember::factory()->for($committee)->create(['position' => 'Member']);

        $response = $this->actingAs($actor)->put("/admin/committees/{$committee->id}/members/{$member->id}", [
            'position' => 'Vice Chairperson',
            'effective_from' => $member->effective_from->toDateString(),
            'status' => 'active',
        ]);

        $response->assertRedirect("/admin/committees/{$committee->id}");
        $this->assertSame('Vice Chairperson', $member->fresh()->position);
    }

    public function test_authorized_user_can_end_a_membership(): void
    {
        $actor = $this->userWithPermission('committees.update');
        $committee = Committee::factory()->create();
        $member = CommitteeMember::factory()->for($committee)->create();

        $response = $this->actingAs($actor)->patch(
            "/admin/committees/{$committee->id}/members/{$member->id}/status",
            ['status' => 'inactive'],
        );

        $response->assertRedirect("/admin/committees/{$committee->id}");
        $this->assertSame('inactive', $member->fresh()->status->value);
    }

    public function test_reactivating_a_membership_is_blocked_if_another_active_membership_exists(): void
    {
        $actor = $this->userWithPermission('committees.update');
        $committee = Committee::factory()->create();
        $target = User::factory()->create();
        $endedMembership = CommitteeMember::factory()->for($committee)->for($target)->ended()->create();
        CommitteeMember::factory()->for($committee)->for($target)->create();

        $response = $this->actingAs($actor)->patch(
            "/admin/committees/{$committee->id}/members/{$endedMembership->id}/status",
            ['status' => 'active'],
        );

        $response->assertSessionHasErrors('status');
        $this->assertSame('inactive', $endedMembership->fresh()->status->value);
    }

    public function test_a_member_record_cannot_be_reached_through_an_unrelated_committee(): void
    {
        $actor = $this->userWithPermission('committees.update');
        $committeeA = Committee::factory()->create();
        $committeeB = Committee::factory()->create();
        $member = CommitteeMember::factory()->for($committeeA)->create();

        $this->actingAs($actor)
            ->put("/admin/committees/{$committeeB->id}/members/{$member->id}", [
                'position' => 'Hijacked',
                'effective_from' => $member->effective_from->toDateString(),
                'status' => 'active',
            ])
            ->assertNotFound();

        $this->assertNotSame('Hijacked', $member->fresh()->position);
    }

    private function userWithPermission(string $permission): User
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
