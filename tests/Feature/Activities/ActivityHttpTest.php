<?php

namespace Tests\Feature\Activities;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityMove;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ActivityUpdate;
use Database\Seeders\RegionalOfficeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Calendar of Activities over HTTP: who may view, post, edit and curate,
 * the bell notifications PALD's return and removal send, clash warnings
 * and the audit trail.
 */
class ActivityHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_view_but_not_post(): void
    {
        $office = OrganizationalUnit::factory()->create();
        $activity = Activity::factory()->dated('2027-03-10')->create(['organizational_unit_id' => $office->id]);
        $staff = $this->userWith(['activities.view'], $office);

        $this->actingAs($staff)->get('/activities?month=2027-03')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Activities/Index')->has('activities', 1));
        $this->actingAs($staff)->getJson("/activities/{$activity->id}")
            ->assertOk()
            ->assertJsonPath('activity.title', $activity->title);

        $this->actingAs($staff)->post('/activities', $this->form($office))->assertForbidden();
        $this->actingAs($staff)->put("/activities/{$activity->id}", ['title' => 'Changed'])->assertForbidden();
        $this->assertSame(1, Activity::query()->count());
    }

    public function test_poster_posts_for_their_own_office_only(): void
    {
        $office = OrganizationalUnit::factory()->create();
        $other = OrganizationalUnit::factory()->create();
        $poster = $this->userWith(['activities.view', 'activities.post'], $office);

        $this->actingAs($poster)->post('/activities', $this->form($office))
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->actingAs($poster)->post('/activities', $this->form($other))->assertForbidden();

        $this->assertSame([$office->id], Activity::query()->pluck('organizational_unit_id')->all());
    }

    public function test_poster_without_an_office_gets_a_validation_error(): void
    {
        $office = OrganizationalUnit::factory()->create();
        $poster = $this->userWith(['activities.view', 'activities.post'], null);

        $this->actingAs($poster)->post('/activities', $this->form($office))
            ->assertSessionHasErrors('organizational_unit_id');

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_leyte_i_poster_cannot_edit_leyte_ii_activity(): void
    {
        $this->seed(RegionalOfficeSeeder::class);
        $leyteOne = OrganizationalUnit::query()->where('code', 'LFO-I')->firstOrFail();
        $leyteTwo = OrganizationalUnit::query()->where('code', 'LFO-II')->firstOrFail();
        $activity = Activity::factory()->dated('2027-03-10')->create(['organizational_unit_id' => $leyteTwo->id]);
        $poster = $this->userWith(['activities.view', 'activities.post'], $leyteOne);

        $this->actingAs($poster)->put("/activities/{$activity->id}", ['title' => 'Changed'])->assertForbidden();
        $this->actingAs($poster)->post("/activities/{$activity->id}/cancel", ['reason' => 'No'])->assertForbidden();
        $this->actingAs($poster)->post("/activities/{$activity->id}/done")->assertForbidden();

        $this->assertNotSame('Changed', $activity->fresh()->title);
    }

    public function test_curator_can_edit_any_office_and_return_and_remove(): void
    {
        Notification::fake();
        $office = OrganizationalUnit::factory()->create();
        $curator = $this->userWith(['activities.view', 'activities.post', 'activities.manage'], OrganizationalUnit::factory()->create());
        $activity = Activity::factory()->dated('2027-03-10')->create(['organizational_unit_id' => $office->id]);

        $this->actingAs($curator)->put("/activities/{$activity->id}", ['title' => 'Renamed by PALD'])->assertRedirect();
        $this->assertSame('Renamed by PALD', $activity->fresh()->title);

        $this->actingAs($curator)->post("/activities/{$activity->id}/return", ['note' => 'Fix the venue'])->assertRedirect();
        $this->assertSame('Fix the venue', $activity->fresh()->returned_note);

        $this->actingAs($curator)->delete("/activities/{$activity->id}", ['reason' => 'Duplicate'])->assertRedirect();
        $this->assertSoftDeleted($activity);
        $this->assertSame('Duplicate', Activity::withTrashed()->find($activity->id)->removed_reason);
    }

    public function test_poster_cannot_return_or_remove(): void
    {
        $office = OrganizationalUnit::factory()->create();
        $poster = $this->userWith(['activities.view', 'activities.post'], $office);
        $activity = Activity::factory()->dated('2027-03-10')->create(['organizational_unit_id' => $office->id]);

        $this->actingAs($poster)->post("/activities/{$activity->id}/return", ['note' => 'x'])->assertForbidden();
        $this->actingAs($poster)->delete("/activities/{$activity->id}", ['reason' => 'x'])->assertForbidden();
    }

    public function test_return_and_remove_notify_the_offices_posters_and_creator(): void
    {
        Notification::fake();
        $office = OrganizationalUnit::factory()->create();
        $creator = $this->userWith(['activities.view', 'activities.post'], $office);
        $otherPoster = $this->userWith(['activities.view', 'activities.post'], $office);
        $inactivePoster = $this->userWith(['activities.view', 'activities.post'], $office);
        $inactivePoster->forceFill(['status' => 'inactive'])->save();
        $elsewhere = $this->userWith(['activities.view', 'activities.post'], OrganizationalUnit::factory()->create());
        $curator = $this->userWith(['activities.view', 'activities.manage'], null);
        $activity = Activity::factory()->dated('2027-03-10')->create(['organizational_unit_id' => $office->id, 'created_by' => $creator->id]);

        $this->actingAs($curator)->post("/activities/{$activity->id}/return", ['note' => 'Fix the venue'])->assertRedirect();

        Notification::assertSentToTimes($creator, ActivityUpdate::class, 1);
        Notification::assertSentToTimes($otherPoster, ActivityUpdate::class, 1);
        Notification::assertNotSentTo([$inactivePoster, $elsewhere, $curator], ActivityUpdate::class);
        Notification::assertSentTo($creator, ActivityUpdate::class, fn (ActivityUpdate $notification) => $notification->toArray($creator)['url'] === '/activities?month=2027-03');

        $this->actingAs($curator)->delete("/activities/{$activity->id}", ['reason' => 'Duplicate'])->assertRedirect();

        Notification::assertSentToTimes($creator, ActivityUpdate::class, 2);
        Notification::assertSentToTimes($otherPoster, ActivityUpdate::class, 2);
    }

    public function test_moving_without_a_reason_is_a_validation_error(): void
    {
        $office = OrganizationalUnit::factory()->create();
        $poster = $this->userWith(['activities.view', 'activities.post'], $office);
        $activity = Activity::factory()->dated('2027-03-10')->create(['organizational_unit_id' => $office->id]);

        $this->actingAs($poster)->put("/activities/{$activity->id}", ['starts_on' => '2027-03-12', 'ends_on' => '2027-03-12'])
            ->assertSessionHasErrors('move_reason');
        $this->actingAs($poster)->post("/activities/{$activity->id}/move", ['starts_on' => '2027-03-12'])
            ->assertSessionHasErrors('move_reason');
        $this->assertSame('2027-03-10', $activity->fresh()->starts_on->toDateString());

        $this->actingAs($poster)->post("/activities/{$activity->id}/move", ['starts_on' => '2027-03-12', 'move_reason' => 'Venue taken'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2027-03-12', $activity->fresh()->starts_on->toDateString());
        $this->assertSame('Venue taken', ActivityMove::query()->value('reason'));
    }

    public function test_saving_a_clash_warns_but_saves(): void
    {
        $office = OrganizationalUnit::factory()->create();
        $poster = $this->userWith(['activities.view', 'activities.post'], $office);
        $existing = Activity::factory()->dated('2027-03-10')->create(['venue' => 'Conference Room', 'title' => 'Staff meeting']);

        $this->actingAs($poster)->post('/activities', $this->form($office, ['venue' => ' conference room ']))
            ->assertRedirect()
            ->assertSessionHas('clashes', fn (array $clashes) => count($clashes) === 1 && $clashes[0]['title'] === 'Staff meeting' && $clashes[0]['date'] === '2027-03-10');

        $this->assertSame(2, Activity::query()->count());

        // On an edit the activity's own stored values count, not only the
        // fields sent, and it never clashes with itself.
        $mine = Activity::query()->whereKeyNot($existing->id)->firstOrFail();
        $this->actingAs($poster)->put("/activities/{$mine->id}", ['title' => 'Renamed'])
            ->assertSessionHas('clashes', fn (array $clashes) => count($clashes) === 1 && $clashes[0]['title'] === 'Staff meeting');
    }

    public function test_storing_with_a_repeat_creates_the_series(): void
    {
        $office = OrganizationalUnit::factory()->create();
        $poster = $this->userWith(['activities.view', 'activities.post'], $office);

        $this->actingAs($poster)->post('/activities', $this->form($office, [
            'starts_on' => '2027-03-01',
            'ends_on' => null,
            'repeat' => ['frequency' => 'weekly', 'interval' => 1, 'weekday' => 1, 'until' => '2027-03-31', 'skip_holidays' => false],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['2027-03-01', '2027-03-08', '2027-03-15', '2027-03-22', '2027-03-29'], Activity::query()->orderBy('starts_on')->get()->map(fn (Activity $activity) => $activity->starts_on->toDateString())->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'activity.series_saved', 'actor_id' => $poster->id]);
    }

    public function test_a_monthly_repeat_takes_a_day_or_a_weekday_not_both(): void
    {
        $office = OrganizationalUnit::factory()->create();
        $poster = $this->userWith(['activities.view', 'activities.post'], $office);

        $this->actingAs($poster)->post('/activities', $this->form($office, [
            'repeat' => ['frequency' => 'monthly', 'day_of_month' => 5, 'nth_weekday' => 1, 'weekday' => 1],
        ]))->assertSessionHasErrors('repeat');

        $this->actingAs($poster)->post('/activities', $this->form($office, [
            'repeat' => ['frequency' => 'daily', 'interval' => 2],
        ]))->assertSessionHasErrors('repeat.interval');

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_editing_this_and_the_following_updates_the_series(): void
    {
        $office = OrganizationalUnit::factory()->create();
        $poster = $this->userWith(['activities.view', 'activities.post'], $office);
        $this->actingAs($poster)->post('/activities', $this->form($office, [
            'starts_on' => '2027-03-01',
            'ends_on' => null,
            'repeat' => ['frequency' => 'weekly', 'weekday' => 1, 'until' => '2027-03-31', 'skip_holidays' => false],
        ]));
        $third = Activity::query()->whereDate('starts_on', '2027-03-15')->firstOrFail();

        $this->actingAs($poster)->put("/activities/{$third->id}", ['title' => 'New title', 'scope' => 'following'])
            ->assertSessionHasNoErrors();

        $titles = Activity::query()->orderBy('starts_on')->pluck('title', 'starts_on')->mapWithKeys(fn ($title, $date) => [substr($date, 0, 10) => $title]);
        $this->assertSame('Quarterly planning', $titles['2027-03-08']);
        $this->assertSame('New title', $titles['2027-03-15']);
        $this->assertSame('New title', $titles['2027-03-29']);
        $this->assertSame(5, Activity::query()->whereNotNull('series_id')->count());
    }

    public function test_each_action_is_audited(): void
    {
        Notification::fake();
        $office = OrganizationalUnit::factory()->create();
        $poster = $this->userWith(['activities.view', 'activities.post'], $office);
        $curator = $this->userWith(['activities.view', 'activities.manage'], null);

        $this->actingAs($poster)->post('/activities', $this->form($office));
        $activity = Activity::query()->firstOrFail();
        $this->actingAs($poster)->put("/activities/{$activity->id}", ['title' => 'Renamed']);
        $this->actingAs($poster)->post("/activities/{$activity->id}/move", ['starts_on' => '2027-03-11', 'move_reason' => 'Clash']);
        $this->actingAs($poster)->post("/activities/{$activity->id}/done");

        $other = Activity::factory()->dated('2027-03-12')->create(['organizational_unit_id' => $office->id]);
        $this->actingAs($poster)->post("/activities/{$other->id}/cancel", ['reason' => 'No budget']);
        $this->actingAs($curator)->post("/activities/{$other->id}/return", ['note' => 'Say why']);
        $this->actingAs($curator)->delete("/activities/{$other->id}", ['reason' => 'Duplicate']);

        foreach ([
            'activity.saved' => 2,
            'activity.moved' => 1,
            'activity.done' => 1,
            'activity.cancelled' => 1,
            'activity.returned' => 1,
            'activity.removed' => 1,
        ] as $event => $count) {
            $this->assertSame($count, $this->auditCount($event), $event);
        }
        $this->assertSame(ActivityStatus::Done, $activity->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(OrganizationalUnit $office, array $overrides = []): array
    {
        return [
            'organizational_unit_id' => $office->id,
            'title' => 'Quarterly planning',
            'responsible' => 'MSD',
            'participants' => 'All staff',
            'venue' => 'Board Room',
            'mode' => 'face_to_face',
            'starts_on' => '2027-03-10',
            'ends_on' => '2027-03-10',
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'all_day' => false,
            ...$overrides,
        ];
    }

    private function auditCount(string $event): int
    {
        return DB::table('audit_logs')->where('event', $event)->count();
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
