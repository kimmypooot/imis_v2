<?php

namespace Tests\Feature\Portal;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityMove;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Activities\UpcomingActivities;
use App\Support\Portal\AtAGlance;
use App\Support\Portal\QuickAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardActivitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Holiday::query()->delete();
    }

    public function test_shortcuts_follow_access(): void
    {
        $staff = $this->userWith(['activities.view']);

        $this->assertSame(['activities'], array_column(app(QuickAccess::class)->for($staff), 'key'));
        $this->assertSame([], app(QuickAccess::class)->for(User::factory()->create()));

        $employee = Employee::factory()->create();
        $this->attachPermissions($employee->user, ['activities.view']);

        $shortcuts = app(QuickAccess::class)->for($employee->user->fresh());

        $this->assertSame(['leave', 'dtr', 'payslips', 'pds', 'activities'], array_column($shortcuts, 'key'));
        $this->assertSame(['/my/leave/apply', '/my/dtr', '/my/payslips', '/my/pds', '/activities'], array_column($shortcuts, 'href'));
    }

    public function test_upcoming_lists_next_seven_days_in_manila_time(): void
    {
        // 00:30 on 2 December in Manila is still 1 December (16:30) in UTC.
        $this->travelTo(Carbon::parse('2025-12-02 00:30', 'Asia/Manila'));
        $this->assertSame('2025-12-01', now('UTC')->toDateString());
        $office = OrganizationalUnit::factory()->create();
        Activity::factory()->for($office, 'office')->dated('2025-12-01')->create(['title' => 'Yesterday']);
        Activity::factory()->for($office, 'office')->dated('2025-12-02')->create(['title' => 'Today']);
        Activity::factory()->for($office, 'office')->dated('2025-12-08')->create(['title' => 'Last day']);
        Activity::factory()->for($office, 'office')->dated('2025-12-09')->create(['title' => 'Too late']);
        Holiday::query()->create(['date' => '2025-12-08', 'name' => 'Feast of the Immaculate Conception of Mary', 'type' => 'special_non_working']);

        $upcoming = app(UpcomingActivities::class)->for($this->userWith(['activities.view']));

        $this->assertSame(['Today', 'Feast of the Immaculate Conception of Mary', 'Last day'], array_column($upcoming['items'], 'title'));
        $this->assertSame('2025-12-02', $upcoming['items'][0]['date']);
        $this->assertTrue($upcoming['items'][0]['today']);
        $this->assertSame('holiday', $upcoming['items'][1]['type']);
        $this->assertSame(2, $upcoming['count']);
        $this->assertSame('2025-12', $upcoming['month']['month']);
        $this->assertSame(['2025-12-01', '2025-12-02', '2025-12-08', '2025-12-09'], $upcoming['month']['days']);
        $this->assertSame('2025-12-08', $upcoming['month']['holidays'][0]['date']);
    }

    public function test_cancelled_shown_but_not_counted(): void
    {
        $this->travelTo('2025-12-02 08:00:00');
        $office = OrganizationalUnit::factory()->create();
        $user = $this->userWith(['activities.view'], $office);
        Activity::factory()->for($office, 'office')->dated('2025-12-03')->create(['title' => 'Ours']);
        $moved = Activity::factory()->dated('2025-12-04')->create(['title' => 'Moved']);
        ActivityMove::query()->create(['activity_id' => $moved->id, 'from_starts_on' => '2025-12-02', 'from_ends_on' => '2025-12-02', 'to_starts_on' => '2025-12-04', 'to_ends_on' => '2025-12-04', 'reason' => 'Clash']);
        Activity::factory()->dated('2025-12-05')->create(['title' => 'Called off', 'status' => ActivityStatus::Cancelled]);

        $upcoming = app(UpcomingActivities::class)->for($user);
        $items = collect($upcoming['items'])->keyBy('title');

        $this->assertSame(2, $upcoming['count']);
        $this->assertCount(3, $upcoming['items']);
        $this->assertSame(['mine'], array_column($items['Ours']['tags'], 'key'));
        $this->assertSame([['key' => 'moved_from', 'label' => 'Moved from 2 Dec']], $items['Moved']['tags']);
        $this->assertSame(['cancelled'], array_column($items['Called off']['tags'], 'key'));
    }

    public function test_glance_has_activities_tile(): void
    {
        $this->travelTo('2025-12-02 08:00:00');
        Activity::factory()->dated('2025-12-03')->create();

        $tile = collect(app(AtAGlance::class)->for($this->userWith(['activities.view'])))->firstWhere('key', 'activities');

        $this->assertSame(['key' => 'activities', 'label' => 'Activities', 'value' => '1', 'hint' => 'this week', 'href' => '/activities', 'icon' => 'calendar', 'tone' => 'success'], $tile);
        $this->assertSame([], app(AtAGlance::class)->for(User::factory()->create()));
    }

    public function test_dashboard_defers_activities(): void
    {
        $this->travelTo('2025-12-02 08:00:00');
        Activity::factory()->dated('2025-12-03')->create(['title' => 'Coming up']);
        Activity::factory()->dated('2026-01-14')->create();

        $this->actingAs($this->userWith(['activities.view']))->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->component('Portal/Dashboard')
                ->where('shortcuts.0.key', 'activities')
                ->missing('activities')
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('activities.items.0.title', 'Coming up')->where('activities.count', 1)));

        // The mini calendar's ‹ › reload only `activities` with a month.
        $this->actingAs($this->userWith(['activities.view']))->get('/dashboard?month=2026-01')
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('activities.month.month', '2026-01')->where('activities.month.days', ['2026-01-14'])));

        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('shortcuts', [])
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('activities', null)));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions, ?OrganizationalUnit $office = null): User
    {
        $user = User::factory()->create(['organizational_unit_id' => $office?->id]);
        $this->attachPermissions($user, $permissions);

        return $user;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function attachPermissions(User $user, array $permissions): void
    {
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }

        $user->roles()->attach($role);
    }
}
