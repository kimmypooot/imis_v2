<?php

namespace Tests\Feature\Portal;

use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What the portal exposes once systems have per-system roles: the launch
 * URL is resolved per viewer, and a restricted system's URL never reaches
 * someone without a role in it.
 */
class SystemAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_restricted_system_withholds_its_url_from_someone_without_a_role(): void
    {
        $application = Application::factory()->create(['requires_access' => true, 'url' => 'https://otrs.example.test']);

        $this->actingAs(User::factory()->create())
            ->get("/applications/{$application->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('application.url', null)
                ->where('application.requires_access', true)
                ->where('application.role', null));
    }

    public function test_restricted_system_url_does_not_leak_through_listings(): void
    {
        Application::factory()->featured()->create(['requires_access' => true, 'url' => 'https://secret.example.test']);

        $response = $this->actingAs(User::factory()->create())->get('/applications');
        $response->assertOk();
        $this->assertStringNotContainsString('secret.example.test', $response->getContent());

        $response = $this->actingAs(User::factory()->create())->get('/dashboard');
        $response->assertOk();
        $this->assertStringNotContainsString('secret.example.test', $response->getContent());
    }

    public function test_role_holder_gets_the_restricted_systems_url_and_their_role(): void
    {
        $application = Application::factory()->create(['requires_access' => true, 'url' => 'https://otrs.example.test']);
        $role = ApplicationRole::factory()->for($application)->create(['name' => 'Encoder']);
        $grant = ApplicationAccess::factory()->forRole($role)->create();

        $this->actingAs($grant->user)
            ->get("/applications/{$application->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('application.url', 'https://otrs.example.test')
                ->where('application.role', 'Encoder'));
    }

    public function test_role_launch_url_overrides_the_system_url(): void
    {
        $application = Application::factory()->create(['url' => 'https://otrs.example.test']);
        $role = ApplicationRole::factory()->for($application)->create(['launch_url' => 'https://otrs.example.test/admin']);
        $grant = ApplicationAccess::factory()->forRole($role)->create();

        $this->actingAs($grant->user)
            ->get('/applications')
            ->assertInertia(fn (Assert $page) => $page
                ->where('applications.0.url', 'https://otrs.example.test/admin'));

        // Someone else still gets the open system's own URL.
        $this->actingAs(User::factory()->create())
            ->get('/applications')
            ->assertInertia(fn (Assert $page) => $page
                ->where('applications.0.url', 'https://otrs.example.test')
                ->where('applications.0.role', null));
    }

    public function test_application_listing_resolves_access_without_a_query_per_card(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $i) {
            ApplicationAccess::factory()->create(['user_id' => $user->id]);
        }

        $this->actingAs($user);
        $queries = 0;
        \DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'application_access')) {
                $queries++;
            }
        });

        $this->get('/applications')->assertOk();

        $this->assertSame(1, $queries);
    }
}
