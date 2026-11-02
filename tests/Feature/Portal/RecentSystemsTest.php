<?php

namespace Tests\Feature\Portal;

use App\Models\Application;
use App\Models\SystemAccessLog;
use App\Models\User;
use App\Support\Portal\RecentSystems;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentSystemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_a_persons_systems_newest_first_without_repeats(): void
    {
        $user = User::factory()->create();
        [$a, $b, $c] = Application::factory()->count(3)->create();

        $this->launch($user, $a, '2026-11-01 08:00:00');
        $this->launch($user, $b, '2026-11-01 09:00:00');
        $this->launch($user, $a, '2026-11-02 08:00:00');
        $this->launch($user, $c, '2026-10-30 08:00:00');

        $this->assertSame([$a->id, $b->id, $c->id], app(RecentSystems::class)->idsFor($user));
    }

    public function test_it_ignores_other_peoples_launches_and_respects_the_limit(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $applications = Application::factory()->count(3)->create();

        $this->launch($other, $applications[0], '2026-11-02 08:00:00');
        $this->launch($user, $applications[1], '2026-11-01 08:00:00');
        $this->launch($user, $applications[2], '2026-11-02 08:00:00');

        $this->assertSame([$applications[2]->id], app(RecentSystems::class)->idsFor($user, 1));
        $this->assertSame([$applications[2]->id, $applications[1]->id], app(RecentSystems::class)->idsFor($user));
    }

    public function test_the_dashboard_carries_the_recent_systems(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->create();
        $this->launch($user, $application, '2026-11-02 08:00:00');

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('recentSystems', [$application->id]));
    }

    private function launch(User $user, Application $application, string $at): void
    {
        SystemAccessLog::query()->forceCreate([
            'user_id' => $user->id,
            'username' => $user->username,
            'application_id' => $application->id,
            'application_name' => $application->name,
            'accessed_at' => $at,
        ]);
    }
}
