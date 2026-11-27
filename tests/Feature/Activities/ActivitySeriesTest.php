<?php

namespace Tests\Feature\Activities;

use App\Enums\ActivityMode;
use App\Enums\ActivityStatus;
use App\Enums\SeriesFrequency;
use App\Models\Activity;
use App\Models\ActivitySeries;
use App\Models\Holiday;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Support\Activities\Activities;
use App\Support\Activities\ActivitySeriesBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Calendar of Activities repeating activities: the occurrence dates of
 * daily, weekly and monthly rules, skipped holidays and short months, the
 * year-end cap, and regenerating "this and the following" occurrences.
 */
class ActivitySeriesTest extends TestCase
{
    use RefreshDatabase;

    private ActivitySeriesBuilder $builder;

    private User $poster;

    private OrganizationalUnit $office;

    protected function setUp(): void
    {
        parent::setUp();

        Holiday::query()->delete();

        $this->builder = app(ActivitySeriesBuilder::class);
        $this->poster = User::factory()->create();
        $this->office = OrganizationalUnit::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rule(array $overrides = []): array
    {
        return array_merge([
            'frequency' => SeriesFrequency::Weekly->value,
            'interval' => 1,
            'weekday' => 1,
            'day_of_month' => null,
            'nth_weekday' => null,
            'starts_on' => '2026-01-05',
            'until' => '2026-01-26',
            'skip_holidays' => true,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function template(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Weekly staff meeting',
            'responsible' => 'HRD',
            'participants' => 'All staff',
            'venue' => 'Conference Room',
            'mode' => ActivityMode::FaceToFace->value,
            'remarks' => null,
            'starts_at' => '08:00',
            'ends_at' => '09:00',
            'all_day' => false,
        ], $overrides);
    }

    /**
     * @return list<string>
     */
    private function dates(ActivitySeries $series): array
    {
        return $series->activities()->orderBy('starts_on')->get()
            ->map(fn (Activity $activity) => $activity->starts_on->toDateString())->all();
    }

    public function test_weekly_series_creates_each_occurrence_to_until(): void
    {
        $series = $this->builder->create($this->rule(), $this->template(), $this->office->id, $this->poster);

        $this->assertSame(['2026-01-05', '2026-01-12', '2026-01-19', '2026-01-26'], $this->dates($series));

        $occurrence = $series->activities()->first();
        $this->assertSame(ActivityStatus::Scheduled, $occurrence->status);
        $this->assertSame('Weekly staff meeting', $occurrence->title);
        $this->assertSame($this->office->id, $occurrence->organizational_unit_id);
        $this->assertSame('08:00:00', $occurrence->getRawOriginal('starts_at'));
        $this->assertSame($this->poster->id, $series->created_by);
    }

    public function test_daily_series_skips_weekends(): void
    {
        $series = $this->builder->create(
            $this->rule(['frequency' => 'daily', 'weekday' => null, 'starts_on' => '2026-01-05', 'until' => '2026-01-18']),
            $this->template(), $this->office->id, $this->poster,
        );

        $this->assertSame([
            '2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08', '2026-01-09',
            '2026-01-12', '2026-01-13', '2026-01-14', '2026-01-15', '2026-01-16',
        ], $this->dates($series));
    }

    public function test_monthly_on_the_31st_skips_short_months(): void
    {
        $series = $this->builder->create(
            $this->rule(['frequency' => 'monthly', 'weekday' => null, 'day_of_month' => 31, 'starts_on' => '2026-01-01', 'until' => '2026-06-30']),
            $this->template(), $this->office->id, $this->poster,
        );

        $this->assertSame(['2026-01-31', '2026-03-31', '2026-05-31'], $this->dates($series));
    }

    public function test_fifth_monday_skips_months_without_one(): void
    {
        $series = $this->builder->create(
            $this->rule(['frequency' => 'monthly', 'weekday' => 1, 'nth_weekday' => 5, 'starts_on' => '2026-01-01', 'until' => '2026-06-30']),
            $this->template(), $this->office->id, $this->poster,
        );

        $this->assertSame(['2026-03-30', '2026-06-29'], $this->dates($series));
    }

    public function test_holidays_are_skipped_by_default(): void
    {
        Holiday::query()->create(['date' => '2026-06-12', 'name' => 'Independence Day', 'type' => 'regular', 'repeats_annually' => true]);

        $series = $this->builder->create(
            $this->rule(['weekday' => 5, 'starts_on' => '2026-06-01', 'until' => '2026-06-30']),
            $this->template(), $this->office->id, $this->poster,
        );

        $this->assertSame(['2026-06-05', '2026-06-19', '2026-06-26'], $this->dates($series));
    }

    public function test_until_is_capped_at_year_end(): void
    {
        $series = $this->builder->create(
            $this->rule(['starts_on' => '2026-12-07', 'until' => '2027-03-01']),
            $this->template(), $this->office->id, $this->poster,
        );

        $this->assertSame('2026-12-31', $series->until->toDateString());
        $this->assertSame(['2026-12-07', '2026-12-14', '2026-12-21', '2026-12-28'], $this->dates($series));
    }

    public function test_update_following_keeps_moved_and_detached_occurrences(): void
    {
        $series = $this->builder->create($this->rule(['until' => '2026-02-23']), $this->template(), $this->office->id, $this->poster);
        $byDate = fn (string $date): Activity => Activity::query()->whereDate('starts_on', $date)->sole();

        $activities = app(Activities::class);
        $activities->markDone($byDate('2026-01-05'), $this->poster);
        $moved = $activities->move($byDate('2026-01-26'), ['starts_on' => '2026-01-27', 'starts_at' => '08:00', 'ends_at' => '09:00'], 'Venue taken', $this->poster);
        $cancelled = $activities->cancel($byDate('2026-02-02'), 'No quorum', $this->poster);

        $series = $this->builder->updateFollowing(
            $series, Carbon::parse('2026-01-19'),
            $this->rule(['weekday' => 3, 'until' => '2026-02-23']),
            $this->template(['title' => 'Staff meeting (Wednesday)']),
            $this->poster,
        );

        $this->assertSame(3, $series->weekday);
        $this->assertSame([
            '2026-01-05', '2026-01-12', '2026-01-21', '2026-01-28',
            '2026-02-02', '2026-02-04', '2026-02-11', '2026-02-18',
        ], $this->dates($series));

        $this->assertSame('Weekly staff meeting', $byDate('2026-01-12')->title);
        $this->assertSame('Staff meeting (Wednesday)', $byDate('2026-01-21')->title);
        $this->assertSame(ActivityStatus::Done, $byDate('2026-01-05')->status);
        $this->assertSame(ActivityStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame($series->id, $cancelled->fresh()->series_id);
        $this->assertNull($moved->fresh()->series_id);
        $this->assertSame('2026-01-27', $moved->fresh()->starts_on->toDateString());
        $this->assertSame(9, Activity::withTrashed()->count());
    }

    public function test_update_following_with_an_unchanged_pattern_does_not_duplicate_detached_occurrences(): void
    {
        $series = $this->builder->create($this->rule(), $this->template(), $this->office->id, $this->poster);
        $byDate = fn (string $date) => Activity::withTrashed()->whereDate('starts_on', $date)->get();

        $activities = app(Activities::class);
        $moved = $activities->move($byDate('2026-01-26')->sole(), ['starts_on' => '2026-01-27', 'starts_at' => '08:00', 'ends_at' => '09:00'], 'Venue taken', $this->poster);
        $edited = $activities->save(['title' => 'Staff meeting with the RD'], $this->poster, $byDate('2026-01-19')->sole());

        $this->assertSame($series->id, $moved->detached_from_series_id);
        $this->assertSame($series->id, $edited->detached_from_series_id);

        $this->builder->updateFollowing($series, Carbon::parse('2026-01-12'), $this->rule(), $this->template(['venue' => 'Board Room']), $this->poster);

        foreach (['2026-01-05', '2026-01-12', '2026-01-19', '2026-01-27'] as $date) {
            $this->assertCount(1, $byDate($date), "One activity on {$date}");
        }
        $this->assertCount(0, $byDate('2026-01-26'), 'The moved occurrence is not recreated on its original date');
        $this->assertSame('Staff meeting with the RD', $byDate('2026-01-19')->sole()->title);
        $this->assertSame('Board Room', $byDate('2026-01-12')->sole()->venue);
        $this->assertSame(4, Activity::withTrashed()->count());
    }

    public function test_returned_occurrence_keeps_its_link_to_the_series(): void
    {
        $series = $this->builder->create($this->rule(), $this->template(), $this->office->id, $this->poster);
        $returned = app(Activities::class)->returnToOffice($series->activities()->whereDate('starts_on', '2026-01-12')->sole(), 'Fix the venue', $this->poster);

        $this->assertNull($returned->series_id);
        $this->assertSame($series->id, $returned->detached_from_series_id);

        $this->builder->updateFollowing($series, Carbon::parse('2026-01-05'), $this->rule(), $this->template(['venue' => 'Board Room']), $this->poster);

        $this->assertSame(1, Activity::query()->whereDate('starts_on', '2026-01-12')->count());
    }

    public function test_update_following_does_not_bring_back_a_removed_occurrence(): void
    {
        $series = $this->builder->create($this->rule(), $this->template(), $this->office->id, $this->poster);
        $removed = $series->activities()->whereDate('starts_on', '2026-01-19')->sole();
        app(Activities::class)->remove($removed, 'Duplicate entry', $this->poster);

        $series = $this->builder->updateFollowing($series, Carbon::parse('2026-01-12'), $this->rule(), $this->template(['venue' => 'Board Room']), $this->poster);

        $this->assertSame(['2026-01-05', '2026-01-12', '2026-01-26'], $this->dates($series));
        $this->assertSame(1, Activity::withTrashed()->whereDate('starts_on', '2026-01-19')->count());
    }

    public function test_rescheduling_a_planned_series_after_the_cutoff_keeps_occurrences_planned(): void
    {
        $this->travelTo('2026-01-10');
        $series = $this->builder->create($this->rule(['starts_on' => '2026-03-02', 'until' => '2026-03-30']), $this->template(), $this->office->id, $this->poster);
        $this->assertFalse($series->activities()->get()->contains('unplanned', true));

        $this->travelTo('2026-03-10');
        $series = $this->builder->updateFollowing($series, Carbon::parse('2026-03-10'), $this->rule(['weekday' => 3, 'starts_on' => '2026-03-02', 'until' => '2026-03-30']), $this->template(), $this->poster);

        $regenerated = $series->activities()->whereDate('starts_on', '>=', '2026-03-10')->get();
        $this->assertCount(3, $regenerated);
        $this->assertFalse($regenerated->contains('unplanned', true));
    }

    public function test_series_created_after_the_cutoff_is_unplanned(): void
    {
        $this->travelTo('2026-03-10');
        $series = $this->builder->create($this->rule(['starts_on' => '2026-03-16', 'until' => '2026-03-30']), $this->template(), $this->office->id, $this->poster);

        $occurrences = $series->activities()->get();
        $this->assertCount(3, $occurrences);
        $this->assertTrue($occurrences->every(fn (Activity $activity) => $activity->unplanned));
    }
}
