<?php

namespace Tests\Feature\Activities;

use App\Enums\ActivityStatus;
use App\Enums\AuditEvent;
use App\Models\Activity;
use App\Models\ActivityMove;
use App\Models\ActivityPlanCutoff;
use App\Models\AuditLog;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Activities\PlanVersusActual;
use App\Support\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PALD's Calendar of Activities management page: who may open it, the
 * plan-versus-actual counts, what changed recently and the plan cut-off.
 */
class ActivityManageTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_curators_open_manage(): void
    {
        $office = OrganizationalUnit::factory()->create();
        $poster = $this->userWith(['activities.view', 'activities.post'], $office);
        $curator = $this->userWith(['activities.view', 'activities.manage'], $office);

        $this->actingAs($poster)->get('/activities/manage')->assertForbidden();
        $this->actingAs($poster)->put('/activities/cutoff', ['year' => 2027, 'cuts_off_on' => '2027-02-15'])->assertForbidden();

        Activity::factory()->dated('2027-03-10')->create(['organizational_unit_id' => $office->id, 'title' => 'First']);
        Activity::factory()->dated('2027-03-10')->create(['organizational_unit_id' => $office->id, 'title' => 'Second']);
        Activity::factory()->dated('2027-03-20')->create(['organizational_unit_id' => $office->id, 'title' => 'Dropped', 'removed_reason' => 'Duplicate', 'updated_by' => $curator->id])->delete();

        $this->actingAs($curator)->get('/activities/manage?year=2027&month=2027-03')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Activities/Manage')
                ->where('year', 2027)
                ->where('month', '2027-03')
                ->where('days', 7)
                ->where('cutoff', '2027-01-31')
                ->where('cutoffIsDefault', true)
                ->has('changes')
                ->has('clashes', 1)
                ->where('clashes.0.activity.title', 'First')
                ->where('clashes.0.with.0.title', 'Second')
                ->has('planVersusActual', 1)
                ->has('removed', 1)
                ->where('removed.0.reason', 'Duplicate')
                ->where('removed.0.removed_by', $curator->name));
    }

    public function test_plan_versus_actual_counts(): void
    {
        $office = OrganizationalUnit::factory()->create(['name' => 'Policies and Systems Evaluation Division', 'short_name' => 'PSED']);
        $other = OrganizationalUnit::factory()->create(['name' => 'Management Services Division', 'short_name' => 'MSD']);
        $mine = ['organizational_unit_id' => $office->id];

        Activity::factory()->count(2)->dated('2027-03-10')->create([...$mine, 'status' => ActivityStatus::Done]);
        $moved = Activity::factory()->dated('2027-04-10')->create([...$mine, 'status' => ActivityStatus::Done]);
        ActivityMove::query()->create(['activity_id' => $moved->id, 'from_starts_on' => '2027-04-03', 'from_ends_on' => '2027-04-03', 'to_starts_on' => '2027-04-10', 'to_ends_on' => '2027-04-10', 'reason' => 'Venue taken']);
        Activity::factory()->dated('2027-05-10')->create([...$mine, 'status' => ActivityStatus::Cancelled, 'cancel_reason' => 'No budget']);
        Activity::factory()->dated('2027-06-10')->create([...$mine, 'unplanned' => true]);
        // Removed, another year, and another office: not counted for this office.
        Activity::factory()->dated('2027-07-10')->create($mine)->delete();
        Activity::factory()->dated('2026-07-10')->create($mine);
        Activity::factory()->monthOnly(2027, 9)->create(['organizational_unit_id' => $other->id]);

        $rows = PlanVersusActual::for(2027)->keyBy('office');

        $this->assertSame(
            ['office' => 'PSED', 'planned' => 4, 'done' => 3, 'cancelled' => 1, 'moved' => 1, 'unplanned' => 1, 'open' => 1],
            $rows['PSED'],
        );
        $this->assertSame(
            ['office' => 'MSD', 'planned' => 1, 'done' => 0, 'cancelled' => 0, 'moved' => 0, 'unplanned' => 0, 'open' => 1],
            $rows['MSD'],
        );
        $this->assertCount(2, $rows);
    }

    public function test_what_changed_lists_recent_activity_events(): void
    {
        $office = OrganizationalUnit::factory()->create(['short_name' => 'PSED']);
        $curator = $this->userWith(['activities.view', 'activities.manage'], $office);
        $activity = Activity::factory()->dated('2027-03-10')->create(['organizational_unit_id' => $office->id, 'title' => 'Quarterly review']);
        $removed = Activity::factory()->dated('2027-03-12')->create(['organizational_unit_id' => $office->id, 'title' => 'Gone']);

        $this->actingAs($curator);
        $logger = app(AuditLogger::class);
        $logger->record(AuditEvent::ActivityMoved, 'Moved the activity "Quarterly review" to 2027-03-10', $activity);
        $logger->record(AuditEvent::ActivityRemoved, 'Removed the activity "Gone"', $removed);
        $removed->delete();
        $old = $logger->record(AuditEvent::ActivitySaved, 'Added the activity "Quarterly review"', $activity);
        AuditLog::query()->whereKey($old->id)->toBase()->update(['created_at' => Carbon::now()->subDays(10)]);
        $logger->record(AuditEvent::UserCreated, 'Not an activity event', $curator);

        $this->get('/activities/manage')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('days', 7)
                ->has('changes', 2)
                ->where('changes.0.description', 'Removed the activity "Gone"')
                ->where('changes.0.office', 'PSED')
                ->where('changes.0.link', null)
                ->where('changes.0.who', $curator->name)
                ->where('changes.1.event', 'Moved')
                ->where('changes.1.link', '/activities?month=2027-03'));

        $this->get('/activities/manage?days=30')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('days', 30)->has('changes', 3));
    }

    public function test_cutoff_is_saved_and_audited(): void
    {
        $curator = $this->userWith(['activities.view', 'activities.manage'], OrganizationalUnit::factory()->create());

        $this->actingAs($curator)->put('/activities/cutoff', ['year' => 2027, 'cuts_off_on' => '2028-02-15'])
            ->assertSessionHasErrors('cuts_off_on');

        $this->actingAs($curator)->from('/activities/manage?year=2027')->put('/activities/cutoff', ['year' => 2027, 'cuts_off_on' => '2027-02-15'])
            ->assertRedirect('/activities/manage?year=2027')
            ->assertSessionHas('status');

        $this->assertSame('2027-02-15', ActivityPlanCutoff::for(2027)->toDateString());
        $this->assertSame($curator->id, ActivityPlanCutoff::query()->find(2027)->updated_by);
        $this->assertDatabaseHas('audit_logs', ['event' => AuditEvent::ActivityCutoffSaved->value, 'actor_id' => $curator->id]);

        $this->actingAs($curator)->put('/activities/cutoff', ['year' => 2027, 'cuts_off_on' => '2027-03-01'])->assertSessionHasNoErrors();
        $this->assertSame(1, ActivityPlanCutoff::query()->count());
        $this->assertSame('2027-03-01', ActivityPlanCutoff::for(2027)->toDateString());
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions, ?OrganizationalUnit $office): User
    {
        $user = User::factory()->create(['organizational_unit_id' => $office?->id]);
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(
                Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]),
            );
        }

        $user->roles()->attach($role);

        return $user;
    }
}
