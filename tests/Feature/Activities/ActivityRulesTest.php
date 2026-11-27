<?php

namespace Tests\Feature\Activities;

use App\Enums\ActivityMode;
use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivitySeries;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Support\Activities\Activities;
use App\Support\Activities\MoveReasonRequired;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Calendar of Activities posting rules: planned versus scheduled, moves
 * and their reasons, the plan cut-off, series detachment, cancelling,
 * marking done, returning to the office and PALD's removal.
 */
class ActivityRulesTest extends TestCase
{
    use RefreshDatabase;

    private Activities $activities;

    private User $poster;

    private OrganizationalUnit $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activities = app(Activities::class);
        $this->poster = User::factory()->create();
        $this->office = OrganizationalUnit::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function data(array $overrides = []): array
    {
        return array_merge([
            'organizational_unit_id' => $this->office->id,
            'title' => 'Regional planning workshop',
            'responsible' => 'PALD',
            'participants' => 'Division chiefs',
            'venue' => 'Conference Room',
            'mode' => ActivityMode::FaceToFace->value,
            'remarks' => null,
            'year' => 2025,
            'month' => 12,
            'starts_on' => null,
            'ends_on' => null,
            'starts_at' => null,
            'ends_at' => null,
            'all_day' => false,
            'unplanned' => false,
        ], $overrides);
    }

    public function test_month_only_activity_is_planned(): void
    {
        $activity = $this->activities->save($this->data(), $this->poster);

        $this->assertSame(ActivityStatus::Planned, $activity->status);
        $this->assertNull($activity->starts_on);
        $this->assertSame(2025, $activity->year);
        $this->assertSame(12, $activity->month);
        $this->assertSame($this->poster->id, $activity->created_by);
    }

    public function test_first_date_schedules_without_a_move(): void
    {
        $activity = $this->activities->save($this->data(), $this->poster);

        $activity = $this->activities->save($this->data(['starts_on' => '2025-12-03']), $this->poster, $activity);

        $this->assertSame(ActivityStatus::Scheduled, $activity->status);
        $this->assertSame('2025-12-03', $activity->starts_on->toDateString());
        $this->assertSame('2025-12-03', $activity->ends_on->toDateString());
        $this->assertSame(0, $activity->moves()->count());
    }

    public function test_changing_a_date_records_a_move_and_needs_a_reason(): void
    {
        $activity = $this->activities->save($this->data(['starts_on' => '2025-12-02']), $this->poster);

        try {
            $this->activities->save($this->data(['starts_on' => '2025-12-04']), $this->poster, $activity);
            $this->fail('A date change without a reason was accepted.');
        } catch (MoveReasonRequired) {
            $this->assertSame('2025-12-02', $activity->fresh()->starts_on->toDateString());
        }

        $activity = $this->activities->save($this->data(['starts_on' => '2025-12-04']), $this->poster, $activity, 'Venue not available');

        $this->assertSame(1, $activity->moves()->count());
        $move = $activity->moves()->first();
        $this->assertSame('2025-12-02', $move->from_starts_on->toDateString());
        $this->assertSame('2025-12-04', $move->to_starts_on->toDateString());
        $this->assertSame('Venue not available', $move->reason);
        $this->assertSame($this->poster->id, $move->moved_by);
        $this->assertSame('2025-12-04', $activity->starts_on->toDateString());
        $this->assertSame(ActivityStatus::Scheduled, $activity->status);
    }

    public function test_changing_only_the_time_is_a_move(): void
    {
        $activity = $this->activities->save($this->data([
            'starts_on' => '2025-12-02', 'starts_at' => '13:30', 'ends_at' => '15:00',
        ]), $this->poster);

        $activity = $this->activities->move($activity, [
            'starts_on' => '2025-12-02', 'ends_on' => '2025-12-02', 'starts_at' => '14:00', 'ends_at' => '15:00', 'all_day' => false,
        ], 'Speaker arrives late', $this->poster);

        $this->assertSame(1, $activity->moves()->count());
        $move = $activity->moves()->first();
        $this->assertSame('13:30:00', $move->from_starts_at);
        $this->assertSame('14:00:00', $move->to_starts_at);
    }

    public function test_saving_unchanged_times_is_not_a_move(): void
    {
        $activity = $this->activities->save($this->data([
            'starts_on' => '2025-12-02', 'starts_at' => '13:30', 'ends_at' => '15:00',
        ]), $this->poster);

        $activity = $this->activities->save($this->data([
            'starts_on' => '2025-12-02', 'ends_on' => '2025-12-02', 'starts_at' => '13:30:00', 'ends_at' => '15:00', 'venue' => 'Training Room',
        ]), $this->poster, $activity);

        $this->assertSame('Training Room', $activity->venue);
        $this->assertSame(0, $activity->moves()->count());
    }

    public function test_details_only_edit_on_a_row_without_ends_on_is_not_a_move(): void
    {
        $activity = Activity::factory()->dated('2027-03-10')->create(['ends_on' => null]);

        $edited = $this->activities->save(['venue' => 'Training Room'], $this->poster, $activity);

        $this->assertSame('Training Room', $edited->venue);
        $this->assertSame('2027-03-10', $edited->starts_on->toDateString());
        $this->assertSame(0, $edited->moves()->count());
    }

    public function test_details_only_edit_on_an_all_day_row_with_stale_times_is_not_a_move(): void
    {
        $activity = Activity::factory()->dated('2027-03-10')->create([
            'all_day' => true, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00',
        ]);

        $edited = $this->activities->save(['title' => 'Renamed activity'], $this->poster, $activity);

        $this->assertSame('Renamed activity', $edited->title);
        $this->assertTrue($edited->all_day);
        $this->assertSame(0, $edited->moves()->count());
    }

    public function test_created_after_cutoff_is_unplanned(): void
    {
        $this->travelTo('2026-02-10 09:00');
        $late = $this->activities->save($this->data(['year' => 2026, 'month' => 3]), $this->poster);

        $this->travelTo('2026-01-15 09:00');
        $early = $this->activities->save($this->data(['year' => 2026, 'month' => 3]), $this->poster);
        $ticked = $this->activities->save($this->data(['year' => 2026, 'month' => 3, 'unplanned' => true]), $this->poster);

        $this->assertTrue($late->unplanned);
        $this->assertFalse($early->unplanned);
        $this->assertTrue($ticked->unplanned);

        $stillUnplanned = $this->activities->save($this->data(['year' => 2026, 'month' => 3, 'unplanned' => false]), $this->poster, $late);
        $this->assertTrue($stillUnplanned->unplanned);
    }

    public function test_editing_an_occurrence_detaches_it_from_its_series(): void
    {
        $series = ActivitySeries::factory()->create(['organizational_unit_id' => $this->office->id]);
        $occurrence = Activity::factory()->dated('2027-03-08')->create([
            'organizational_unit_id' => $this->office->id, 'series_id' => $series->id,
        ]);

        $edited = $this->activities->save($this->data([
            'starts_on' => '2027-03-08', 'starts_at' => '09:00', 'ends_at' => '12:00', 'remarks' => 'Bring laptops',
        ]), $this->poster, $occurrence);

        $this->assertNull($edited->series_id);
        $this->assertSame('Bring laptops', $edited->remarks);
        $this->assertSame($this->poster->id, $edited->updated_by);
    }

    public function test_cancel_keeps_dates_and_reason(): void
    {
        $activity = Activity::factory()->dated('2027-03-10')->create();

        $cancelled = $this->activities->cancel($activity, 'Typhoon signal no. 2', $this->poster);

        $this->assertSame(ActivityStatus::Cancelled, $cancelled->status);
        $this->assertSame('Typhoon signal no. 2', $cancelled->cancel_reason);
        $this->assertSame('2027-03-10', $cancelled->starts_on->toDateString());
        $this->assertSame('2027-03-10', $cancelled->ends_on->toDateString());
    }

    public function test_moved_then_done_counts_as_both(): void
    {
        $activity = Activity::factory()->dated('2027-03-10')->create();

        $moved = $this->activities->move($activity, [
            'starts_on' => '2027-03-12', 'ends_on' => '2027-03-12', 'starts_at' => '09:00', 'ends_at' => '12:00', 'all_day' => false,
        ], 'Clashes with the regional meeting', $this->poster);
        $done = $this->activities->markDone($moved, $this->poster);

        $this->assertSame(ActivityStatus::Done, $done->status);
        $this->assertTrue($done->hasMoved);
    }

    public function test_month_only_activity_cannot_be_marked_done(): void
    {
        $activity = Activity::factory()->monthOnly(2027, 3)->create();

        $this->expectException(\DomainException::class);

        $this->activities->markDone($activity, $this->poster);
    }

    public function test_dates_and_times_must_be_in_order(): void
    {
        try {
            $this->activities->save($this->data(['starts_on' => '2025-12-04', 'ends_on' => '2025-12-03']), $this->poster);
            $this->fail('An end date before the start was accepted.');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->activities->save($this->data(['starts_on' => '2025-12-04', 'starts_at' => '14:00', 'ends_at' => '13:00']), $this->poster);
    }

    public function test_return_detaches_and_keeps_the_activity_visible(): void
    {
        $series = ActivitySeries::factory()->create();
        $activity = Activity::factory()->dated('2027-03-08')->create(['series_id' => $series->id]);

        $returned = $this->activities->returnToOffice($activity, 'Please give the venue', $this->poster);

        $this->assertSame('Please give the venue', $returned->returned_note);
        $this->assertNotNull($returned->returned_at);
        $this->assertNull($returned->series_id);
        $this->assertSame($this->poster->id, $returned->updated_by);
        $this->assertTrue(Activity::query()->whereKey($activity->id)->exists());
    }

    public function test_saving_a_returned_activity_clears_the_return(): void
    {
        $activity = Activity::factory()->dated('2027-03-08')->create([
            'organizational_unit_id' => $this->office->id, 'starts_at' => '09:00', 'ends_at' => '12:00',
        ]);
        $returned = $this->activities->returnToOffice($activity, 'Please give the venue', $this->poster);

        $saved = $this->activities->save($this->data([
            'starts_on' => '2027-03-08', 'starts_at' => '09:00', 'ends_at' => '12:00', 'venue' => 'Training Room',
        ]), $this->poster, $returned);

        $this->assertNull($saved->returned_note);
        $this->assertNull($saved->returned_at);
    }

    public function test_remove_soft_deletes_with_a_reason(): void
    {
        $activity = Activity::factory()->dated('2027-03-08')->create();

        $this->activities->remove($activity, 'Posted twice', $this->poster);

        $this->assertSoftDeleted($activity);
        $trashed = Activity::withTrashed()->find($activity->id);
        $this->assertSame('Posted twice', $trashed->removed_reason);
        $this->assertSame($this->poster->id, $trashed->updated_by);
    }
}
