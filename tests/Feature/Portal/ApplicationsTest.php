<?php

namespace Tests\Feature\Portal;

use App\Enums\Visibility;
use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Migrations register real hub systems; these tests count their own.
        Application::query()->delete();
    }

    public function test_guest_cannot_access_the_applications_directory(): void
    {
        $this->get('/applications')->assertRedirect('/login');
    }

    public function test_active_application_appears_in_the_directory(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->create(['name' => 'Visible App']);

        $response = $this->actingAs($user)->get('/applications');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Portal/Applications/Index')
            ->has('applications', 1)
            ->where('applications.0.name', $application->name));
    }

    public function test_inactive_application_is_not_exposed_in_the_directory(): void
    {
        $user = User::factory()->create();
        Application::factory()->inactive()->create();

        $response = $this->actingAs($user)->get('/applications');

        $response->assertInertia(fn ($page) => $page->has('applications', 0));
    }

    public function test_inactive_application_detail_page_returns_404(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->inactive()->create();

        $this->actingAs($user)->get("/applications/{$application->id}")->assertNotFound();
    }

    public function test_administrative_only_application_is_hidden_from_ordinary_user(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->administrative()->create();

        $this->actingAs($user)->get("/applications/{$application->id}")->assertNotFound();

        $response = $this->actingAs($user)->get('/applications');
        $response->assertInertia(fn ($page) => $page->has('applications', 0));
    }

    public function test_active_application_details_are_retrievable(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->create([
            'name' => 'Detail App',
            'visibility' => Visibility::Authenticated,
        ]);

        $response = $this->actingAs($user)->get("/applications/{$application->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Portal/Applications/Show')
            ->where('application.name', 'Detail App'));
    }

    public function test_invalid_application_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/applications/999999')->assertNotFound();
    }

    public function test_application_without_a_configured_url_is_not_exposed_as_a_link(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->withoutUrl()->create();

        $response = $this->actingAs($user)->get("/applications/{$application->id}");

        $response->assertInertia(fn ($page) => $page->where('application.url', null));
    }
}
