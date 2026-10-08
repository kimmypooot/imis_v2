<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\ExecutiveSnapshot;
use App\Models\ExecutiveTarget;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ExecutiveAlert;
use App\Notifications\ExecutiveDigest;
use App\Support\Executive\MetricCollector;
use App\Support\Executive\MetricFeed;
use App\Support\Executive\Sources\MetricSource;
use App\Support\Executive\Sources\SystemFeedSource;
use App\Support\Executive\TargetStatus;
use App\Support\Settings\SettingRegistry;
use Database\Seeders\HrRoleSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Exec-1 to Exec-3: the Executive Dashboard — collecting figures into
 * snapshots, connected systems' summary feeds, targets, alerts, the
 * weekly summary, and who may see and change what.
 */
class ExecutiveDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingRegistry::sync();
    }

    public function test_only_executive_viewers_open_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get('/executive')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/executive/print')->assertForbidden();

        app(MetricCollector::class)->collect();

        $this->actingAs($this->userWith(['executive.view']))->get('/executive')->assertInertia(fn (Assert $page) => $page
            ->component('Executive/Dashboard')
            ->where('canManage', false)
            ->where('dashboard.systems.0.key', 'imis')
            ->where('dashboard.sections', fn ($sections) => collect($sections)->pluck('key')->all() === ['hr', 'leave', 'ld', 'payroll', 'portal', 'imis']
                && collect($sections)->every(fn ($section) => $section['state'] === 'ok')));

        $this->actingAs($this->userWith(['executive.view']))->get('/executive/print')->assertInertia(fn (Assert $page) => $page->component('Executive/Print'));
    }

    public function test_settings_need_the_manage_permission(): void
    {
        $this->actingAs($this->userWith(['executive.view']))->get('/executive/settings')->assertForbidden();
        $this->actingAs($this->userWith(['executive.view']))->put('/executive/targets', [])->assertForbidden();
        $this->actingAs($this->userWith(['executive.view']))->post('/executive/collect')->assertForbidden();

        $this->actingAs($this->userWith(['executive.manage']))->get('/executive/settings')
            ->assertInertia(fn (Assert $page) => $page->component('Executive/Settings')->has('feeds')->has('figures'));
    }

    public function test_links_into_a_module_follow_the_viewers_permissions(): void
    {
        app(MetricCollector::class)->collect();

        $href = fn (User $user) => collect($this->actingAs($user)->get('/executive')->viewData('page')['props']['dashboard']['sections'])
            ->firstWhere('key', 'hr')['metrics'][0]['href'];

        $this->assertNull($href($this->userWith(['executive.view'])));
        $this->assertSame('/admin/hr/employees', $href($this->userWith(['executive.view', 'employees.view'])));
    }

    public function test_a_connected_systems_feed_is_read_with_its_key(): void
    {
        $system = $this->connectedSystem();
        Http::fake(['proctad.test/*' => Http::response($this->feed([
            ['key' => 'exams.scheduled', 'label' => 'Exams scheduled', 'value' => 12, 'unit' => 'count',
                'breakdown' => [['label' => 'Leyte FO', 'value' => 3]], 'href' => '/evil'],
        ]))]);

        $snapshot = app(MetricCollector::class)->collectSource(new SystemFeedSource($system));

        $this->assertTrue($snapshot->succeeded());
        $this->assertEquals(12, $snapshot->metrics[0]['value']);
        // A system can't add links into IMIS.
        $this->assertArrayNotHasKey('href', $snapshot->metrics[0]);
        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer secret-feed-key'));
    }

    public function test_a_failing_system_never_stops_the_others(): void
    {
        $this->connectedSystem();
        Http::fake(['proctad.test/*' => Http::response('Server error', 500)]);

        $snapshots = collect(app(MetricCollector::class)->collect())->keyBy('source');

        $this->assertTrue($snapshots['hr']->succeeded());
        $failed = $snapshots->first(fn (ExecutiveSnapshot $snapshot) => str_starts_with($snapshot->source, 'system:'));
        $this->assertFalse($failed->succeeded());
        $this->assertStringContainsString('HTTP 500', $failed->error);

        $tile = collect($this->actingAs($this->userWith(['executive.view']))->get('/executive')->viewData('page')['props']['dashboard']['systems'])
            ->firstWhere('key', $failed->source);
        $this->assertSame('failing', $tile['state']);
    }

    public function test_feeds_that_break_the_format_are_refused(): void
    {
        foreach ([
            ['metrics' => []],
            ['version' => 2, 'metrics' => []],
            ['version' => 1, 'metrics' => [['key' => 'Bad Key', 'label' => 'x', 'value' => 1]]],
            ['version' => 1, 'metrics' => [['key' => 'a', 'label' => 'x', 'value' => 'twelve']]],
            ['version' => 1, 'metrics' => [['key' => 'a', 'label' => 'x', 'value' => 1, 'unit' => 'furlongs']]],
            ['version' => 1, 'metrics' => [['key' => 'a', 'label' => 'x', 'value' => 1], ['key' => 'a', 'label' => 'y', 'value' => 2]]],
        ] as $payload) {
            try {
                MetricFeed::fromFeed($payload);
                $this->fail('Accepted an invalid feed: '.json_encode($payload));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_target_status(): void
    {
        $higher = new ExecutiveTarget(['target' => 90, 'direction' => 'higher', 'tolerance' => 10]);
        $this->assertSame(TargetStatus::ON_TRACK, TargetStatus::of(95, $higher));
        $this->assertSame(TargetStatus::AT_RISK, TargetStatus::of(81, $higher));
        $this->assertSame(TargetStatus::BEHIND, TargetStatus::of(80, $higher));

        $lower = new ExecutiveTarget(['target' => 5, 'direction' => 'lower', 'tolerance' => 20]);
        $this->assertSame(TargetStatus::ON_TRACK, TargetStatus::of(5, $lower));
        $this->assertSame(TargetStatus::AT_RISK, TargetStatus::of(6, $lower));
        $this->assertSame(TargetStatus::BEHIND, TargetStatus::of(7, $lower));

        $this->assertNull(TargetStatus::of(null, $higher));
        $this->assertNull(TargetStatus::of(10, null));
    }

    public function test_managers_set_targets_and_changes_are_audited(): void
    {
        $manager = $this->userWith(['executive.view', 'executive.manage']);

        $this->actingAs($manager)->put('/executive/targets', [
            'metric' => 'hr/plantilla.vacant', 'target' => 2, 'direction' => 'lower', 'tolerance' => 50, 'note' => 'Staffing plan 2027',
        ])->assertSessionHasNoErrors();

        $target = ExecutiveTarget::query()->sole();
        $this->assertSame(2.0, $target->target);
        $this->assertSame($manager->id, $target->updated_by);
        $this->assertTrue(AuditLog::query()->where('event', 'executive.target_saved')->exists());

        $this->actingAs($manager)->put('/executive/targets', ['metric' => 'not a key', 'target' => 'x', 'direction' => 'up', 'tolerance' => 500])
            ->assertSessionHasErrors(['metric', 'target', 'direction', 'tolerance']);

        $this->actingAs($manager)->delete("/executive/targets/{$target->id}");
        $this->assertSame(0, ExecutiveTarget::query()->count());
        $this->assertTrue(AuditLog::query()->where('event', 'executive.target_removed')->exists());
    }

    public function test_a_feed_key_is_shown_once_and_stored_encrypted(): void
    {
        $manager = $this->userWith(['executive.manage']);
        $system = Application::factory()->create();

        $this->actingAs($manager)->post("/executive/feeds/{$system->id}/key")->assertStatus(422);

        $this->actingAs($manager)->put("/executive/feeds/{$system->id}", ['feed_url' => 'javascript:alert(1)'])->assertSessionHasErrors('feed_url');
        $this->actingAs($manager)->put("/executive/feeds/{$system->id}", ['feed_url' => 'https://proctad.test/api/imis/summary'])->assertSessionHasNoErrors();

        $this->actingAs($manager)->post("/executive/feeds/{$system->id}/key")->assertSessionHas('feed_key');
        $key = session('feed_key')['key'];

        $this->assertSame($key, $system->fresh()->feed_key);
        $this->assertNotSame($key, $system->fresh()->getRawOriginal('feed_key'));
        $this->assertArrayNotHasKey('feed_key', $system->fresh()->toArray());
        $this->assertFalse(AuditLog::query()->where('metadata', 'like', "%{$key}%")->exists());
    }

    public function test_falling_behind_alerts_viewers_once(): void
    {
        Notification::fake();
        $viewer = $this->userWith(['executive.view']);
        ExecutiveTarget::query()->create(['metric' => 'test/score', 'target' => 90, 'direction' => 'higher', 'tolerance' => 10]);
        $collector = app(MetricCollector::class);

        $collector->collectSource($this->source(95));
        $collector->collectSource($this->source(50));
        $collector->collectSource($this->source(40));

        Notification::assertSentToTimes($viewer, ExecutiveAlert::class, 1);
    }

    public function test_alerts_can_be_turned_off(): void
    {
        Notification::fake();
        $viewer = $this->userWith(['executive.view']);
        $this->set('executive_alerts', false);
        ExecutiveTarget::query()->create(['metric' => 'test/score', 'target' => 90, 'direction' => 'higher', 'tolerance' => 10]);

        app(MetricCollector::class)->collectSource($this->source(10));

        Notification::assertNotSentTo($viewer, ExecutiveAlert::class);
    }

    public function test_history_is_thinned_to_one_snapshot_a_day(): void
    {
        foreach ([now()->subDays(5)->setTime(8, 0), now()->subDays(5)->setTime(9, 0), now()->subDays(5)->setTime(10, 0), now()->subHour(), now()] as $at) {
            ExecutiveSnapshot::query()->create(['source' => 'hr', 'collected_at' => $at, 'status' => 'ok', 'metrics' => []]);
        }

        app(MetricCollector::class)->prune();

        $this->assertSame(3, ExecutiveSnapshot::query()->count());
    }

    public function test_the_weekly_summary_is_sent_only_when_turned_on(): void
    {
        Notification::fake();
        $viewer = $this->userWith(['executive.view']);

        $this->artisan('imis:executive-digest')->assertSuccessful();
        Notification::assertNotSentTo($viewer, ExecutiveDigest::class);

        $this->set('executive_weekly_digest', true);
        $this->artisan('imis:executive-digest')->assertSuccessful();
        Notification::assertSentTo($viewer, ExecutiveDigest::class, fn (ExecutiveDigest $digest) => in_array('mail', $digest->via($viewer), true)
            && collect($digest->toMail($viewer)->introLines)->contains(fn ($line) => str_contains($line, 'systems')));
    }

    public function test_the_collect_command_runs_every_source(): void
    {
        $this->artisan('imis:collect-metrics')->assertSuccessful();

        $this->assertSame(6, ExecutiveSnapshot::query()->where('status', 'ok')->count());
    }

    public function test_top_management_gets_the_dashboard(): void
    {
        $this->seed([RolePermissionSeeder::class, HrRoleSeeder::class]);

        $this->assertTrue(Role::query()->where('name', 'top-management')->sole()->permissions->contains('name', 'executive.view'));
    }

    private function connectedSystem(): Application
    {
        $system = Application::factory()->create(['name' => 'PROCTAD']);
        $system->forceFill(['feed_url' => 'https://proctad.test/api/imis/summary', 'feed_key' => 'secret-feed-key'])->save();

        return $system;
    }

    /**
     * @param  array<int, array<string, mixed>>  $metrics
     * @return array<string, mixed>
     */
    private function feed(array $metrics): array
    {
        return ['version' => 1, 'generated_at' => now()->toIso8601String(), 'metrics' => $metrics];
    }

    private function source(float $value): MetricSource
    {
        return new class($value) implements MetricSource
        {
            public function __construct(private readonly float $value) {}

            public function key(): string
            {
                return 'test';
            }

            public function label(): string
            {
                return 'Test';
            }

            public function kind(): string
            {
                return 'module';
            }

            public function collect(): array
            {
                return [['key' => 'score', 'label' => 'Score', 'value' => $this->value, 'unit' => 'percent']];
            }
        };
    }

    private function set(string $key, mixed $value): void
    {
        $setting = Setting::query()->where('key', $key)->firstOrFail();
        $setting->value = $setting->type->serialize($value);
        $setting->save();
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
