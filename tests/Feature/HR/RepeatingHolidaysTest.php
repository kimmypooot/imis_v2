<?php

namespace Tests\Feature\HR;

use App\Models\Holiday;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\HR\Leave\HolidayCalendar;
use App\Support\HR\Leave\WorkingDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RepeatingHolidaysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Start from an empty holiday list rather than the seeded 2027 proclamation.
        Holiday::query()->delete();
    }

    public function test_a_repeating_holiday_is_kept_by_month_and_day_and_counts_in_every_year(): void
    {
        $this->actingAs($this->userWith(['leave.manage']))
            ->post('/admin/hr/leave/holidays', ['date' => '2030-06-12', 'name' => 'Independence Day', 'type' => 'regular', 'repeats_annually' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(Holiday::REPEATING_YEAR.'-06-12', Holiday::query()->sole()->date->toDateString());

        // Wednesday, June 12, 2030 and Thursday, June 12, 2031 are both days off.
        $workingDays = new WorkingDays;
        $this->assertSame(4.0, $workingDays->count('2030-06-10', '2030-06-14'));
        $this->assertSame(4.0, $workingDays->count('2031-06-09', '2031-06-13'));
        $this->assertSame(['2030-06-12', '2031-06-12'], (new HolidayCalendar)->between('2030-01-01', '2031-12-31')->map(fn (Holiday $holiday) => $holiday->date->toDateString())->all());
    }

    public function test_a_dated_holiday_of_the_same_name_moves_a_repeating_one_for_that_year_only(): void
    {
        Holiday::query()->create(['date' => '2030-11-30', 'name' => 'Bonifacio Day', 'type' => 'regular', 'repeats_annually' => true]);
        Holiday::query()->create(['date' => '2031-12-01', 'name' => 'Bonifacio Day', 'type' => 'regular']);

        $dates = (new HolidayCalendar)->between('2030-01-01', '2032-12-31')->map(fn (Holiday $holiday) => $holiday->date->toDateString())->all();

        $this->assertSame(['2030-11-30', '2031-12-01', '2032-11-30'], $dates);
    }

    public function test_a_repeating_february_29_falls_only_in_leap_years(): void
    {
        Holiday::query()->create(['date' => '2028-02-29', 'name' => 'Leap day', 'type' => 'special_non_working', 'repeats_annually' => true]);

        $dates = (new HolidayCalendar)->between('2028-01-01', '2029-12-31')->map(fn (Holiday $holiday) => $holiday->date->toDateString())->all();

        $this->assertSame(['2028-02-29'], $dates);
    }

    public function test_the_page_lists_repeating_holidays_without_a_year_apart_from_the_years_own(): void
    {
        Holiday::query()->create(['date' => '2030-12-25', 'name' => 'Christmas Day', 'type' => 'regular', 'repeats_annually' => true]);
        Holiday::query()->create(['date' => '2030-04-19', 'name' => 'Good Friday', 'type' => 'regular']);

        $this->actingAs($this->userWith(['leave.view']))->get('/admin/hr/leave/holidays?year=2030')->assertInertia(fn (Assert $page) => $page
            ->has('repeating', 1)
            ->where('repeating.0.month_day', 'December 25')
            ->has('holidays', 1)
            ->where('holidays.0.name', 'Good Friday')
            ->where('years', fn ($years) => ! collect($years)->contains(Holiday::REPEATING_YEAR)));
    }

    public function test_the_same_repeating_holiday_cannot_be_entered_twice_from_different_years(): void
    {
        Holiday::query()->create(['date' => '2030-12-25', 'name' => 'Christmas Day', 'type' => 'regular', 'repeats_annually' => true]);

        $this->actingAs($this->userWith(['leave.manage']))
            ->post('/admin/hr/leave/holidays', ['date' => '2031-12-25', 'name' => 'Christmas Day', 'type' => 'regular', 'repeats_annually' => true])
            ->assertSessionHasErrors('name');
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }

        $user->roles()->attach($role);

        return $user;
    }
}
