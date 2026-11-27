<?php

namespace Tests\Feature\Activities;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\OrganizationalUnit;
use App\Support\Activities\ActivityClashes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Calendar of Activities clash warnings: overlapping date and time with
 * the same venue or the same posting office.
 */
class ActivityClashesTest extends TestCase
{
    use RefreshDatabase;

    private ActivityClashes $clashes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clashes = app(ActivityClashes::class);
    }

    public function test_same_venue_overlapping_time_clashes(): void
    {
        $existing = Activity::factory()->dated('2027-03-10')->create(['venue' => 'Conference Room']);

        $found = $this->clashes->for($this->data([
            'organizational_unit_id' => OrganizationalUnit::factory()->create()->id,
            'starts_at' => '11:00',
            'ends_at' => '14:00',
        ]));

        $this->assertSame([$existing->id], $found->pluck('id')->all());
    }

    public function test_same_venue_different_time_does_not_clash(): void
    {
        Activity::factory()->dated('2027-03-10')->create(['venue' => 'Conference Room']);

        $found = $this->clashes->for($this->data([
            'organizational_unit_id' => OrganizationalUnit::factory()->create()->id,
            'starts_at' => '12:00',
            'ends_at' => '15:00',
        ]));

        $this->assertTrue($found->isEmpty());
    }

    public function test_same_office_same_day_all_day_clashes(): void
    {
        $office = OrganizationalUnit::factory()->create();
        $existing = Activity::factory()->dated('2027-03-10')->create([
            'organizational_unit_id' => $office->id,
            'venue' => 'Field',
            'starts_at' => '14:00:00',
            'ends_at' => '16:00:00',
        ]);

        $found = $this->clashes->for($this->data([
            'organizational_unit_id' => $office->id,
            'venue' => 'Elsewhere',
            'all_day' => true,
            'starts_at' => null,
            'ends_at' => null,
        ]));

        $this->assertSame([$existing->id], $found->pluck('id')->all());
    }

    public function test_cancelled_activities_never_clash(): void
    {
        Activity::factory()->dated('2027-03-10')->create([
            'venue' => 'Conference Room',
            'status' => ActivityStatus::Cancelled,
        ]);
        $removed = Activity::factory()->dated('2027-03-10')->create(['venue' => 'Conference Room']);
        $removed->delete();

        $found = $this->clashes->for($this->data([
            'organizational_unit_id' => OrganizationalUnit::factory()->create()->id,
        ]));

        $this->assertTrue($found->isEmpty());
    }

    public function test_venue_match_ignores_case_and_spaces(): void
    {
        $existing = Activity::factory()->dated('2027-03-10')->create(['venue' => 'Conference Room']);
        Activity::factory()->dated('2027-03-10')->create(['venue' => '']);

        $found = $this->clashes->for($this->data([
            'organizational_unit_id' => OrganizationalUnit::factory()->create()->id,
            'venue' => '  conference ROOM ',
        ]));

        $this->assertSame([$existing->id], $found->pluck('id')->all());
        $this->assertTrue($this->clashes->for($this->data([
            'organizational_unit_id' => OrganizationalUnit::factory()->create()->id,
            'venue' => '   ',
        ]))->isEmpty());
    }

    public function test_ignores_the_activity_being_edited_and_month_only_data(): void
    {
        $existing = Activity::factory()->dated('2027-03-10')->create();

        $this->assertTrue($this->clashes->for($this->data([
            'organizational_unit_id' => $existing->organizational_unit_id,
        ]), $existing->id)->isEmpty());
        $this->assertTrue($this->clashes->for([
            'organizational_unit_id' => $existing->organizational_unit_id,
            'year' => 2027,
            'month' => 3,
            'venue' => 'Conference Room',
        ])->isEmpty());
    }

    public function test_for_month_lists_each_pair_once(): void
    {
        $first = Activity::factory()->dated('2027-03-10')->create(['venue' => 'Conference Room']);
        $second = Activity::factory()->dated('2027-03-10')->create(['venue' => 'conference room', 'starts_at' => '10:00:00', 'ends_at' => '11:00:00']);
        Activity::factory()->dated('2027-03-11')->create(['venue' => 'Conference Room']);
        Activity::factory()->dated('2027-04-10')->create(['venue' => 'Conference Room']);
        Activity::factory()->dated('2027-04-10')->create(['venue' => 'Conference Room']);

        $pairs = $this->clashes->forMonth(2027, 3);

        $this->assertCount(1, $pairs);
        $this->assertSame($first->id, $pairs[0]['activity']->id);
        $this->assertSame([$second->id], $pairs[0]['with']->pluck('id')->all());
    }

    /**
     * Save-shaped data for a 9:00–12:00 activity on 10 March 2027 at the
     * Conference Room.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function data(array $overrides = []): array
    {
        return array_merge([
            'starts_on' => '2027-03-10',
            'ends_on' => '2027-03-10',
            'starts_at' => '09:00',
            'ends_at' => '12:00',
            'all_day' => false,
            'venue' => 'Conference Room',
        ], $overrides);
    }
}
