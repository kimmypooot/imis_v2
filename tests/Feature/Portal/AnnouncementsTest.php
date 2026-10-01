<?php

namespace Tests\Feature\Portal;

use App\Models\Announcement;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/announcements')->assertRedirect('/login');
    }

    public function test_index_lists_only_live_announcements(): void
    {
        $live = Announcement::factory()->published()->create();
        Announcement::factory()->create();
        Announcement::factory()->scheduled()->create();
        Announcement::factory()->expired()->create();
        Announcement::factory()->archived()->create();

        $this->actingAs(User::factory()->create())
            ->get('/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Announcements/Index')
                ->has('announcements.data', 1)
                ->where('announcements.data.0.slug', $live->slug));
    }

    public function test_listing_does_not_include_bodies(): void
    {
        Announcement::factory()->published()->create();

        $this->actingAs(User::factory()->create())
            ->get('/announcements')
            ->assertInertia(fn ($page) => $page->missing('announcements.data.0.body'));
    }

    public function test_featured_announcements_are_listed_first(): void
    {
        Announcement::factory()->published()->create(['publish_at' => now()->subHour()]);
        $featured = Announcement::factory()->published()->featured()->create(['publish_at' => now()->subWeek()]);

        $this->actingAs(User::factory()->create())
            ->get('/announcements')
            ->assertInertia(fn ($page) => $page->where('announcements.data.0.slug', $featured->slug));
    }

    public function test_detail_page_shows_a_live_announcement(): void
    {
        $announcement = Announcement::factory()->published()->create();

        $this->actingAs(User::factory()->create())
            ->get("/announcements/{$announcement->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Announcements/Show')
                ->where('announcement.body', $announcement->body)
                ->missing('announcement.status'));
    }

    public function test_draft_is_not_visible(): void
    {
        $this->assertHiddenFromPortal(Announcement::factory()->create());
    }

    public function test_announcement_before_its_publish_date_is_not_visible(): void
    {
        $this->assertHiddenFromPortal(Announcement::factory()->scheduled()->create());
    }

    public function test_announcement_becomes_visible_once_its_publish_date_is_reached(): void
    {
        $announcement = Announcement::factory()->scheduled()->create(['publish_at' => now()->addHour()]);
        $user = User::factory()->create();

        $this->actingAs($user)->get("/announcements/{$announcement->slug}")->assertNotFound();

        $this->travel(2)->hours();

        $this->actingAs($user)->get("/announcements/{$announcement->slug}")->assertOk();
    }

    public function test_expired_announcement_is_not_visible(): void
    {
        $this->assertHiddenFromPortal(Announcement::factory()->expired()->create());
    }

    public function test_announcement_disappears_once_it_expires(): void
    {
        $announcement = Announcement::factory()->published()->create(['expires_at' => now()->addHour()]);
        $user = User::factory()->create();

        $this->actingAs($user)->get("/announcements/{$announcement->slug}")->assertOk();

        $this->travel(2)->hours();

        $this->actingAs($user)->get("/announcements/{$announcement->slug}")->assertNotFound();
    }

    public function test_archived_announcement_is_not_visible(): void
    {
        $this->assertHiddenFromPortal(Announcement::factory()->archived()->create());
    }

    public function test_admins_cannot_see_unpublished_announcements_on_the_portal_either(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'super-admin')->firstOrFail());
        $draft = Announcement::factory()->create();

        $this->actingAs($admin)->get("/announcements/{$draft->slug}")->assertNotFound();
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->actingAs(User::factory()->create())->get('/announcements/does-not-exist')->assertNotFound();
    }

    public function test_dashboard_shows_only_live_announcements(): void
    {
        $live = Announcement::factory()->published()->create();
        Announcement::factory()->create();
        Announcement::factory()->expired()->create();

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('announcements', 1)
                ->where('announcements.0.slug', $live->slug));
    }

    public function test_dashboard_has_no_announcements_when_none_are_published(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('announcements', 0));
    }

    public function test_search_returns_only_live_announcements(): void
    {
        $live = Announcement::factory()->published()->create(['title' => 'Searchable live notice']);
        Announcement::factory()->create(['title' => 'Searchable draft notice']);

        $this->actingAs(User::factory()->create())
            ->get('/search?q=Searchable&type=announcements')
            ->assertInertia(fn ($page) => $page
                ->where('counts.announcements', 1)
                ->has('results.data', 1)
                ->where('results.data.0.href', "/announcements/{$live->slug}"));
    }

    public function test_html_in_content_is_never_rendered_as_markup(): void
    {
        $payload = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
        $announcement = Announcement::factory()->published()->create([
            'title' => "Title {$payload}",
            'summary' => "Summary {$payload}",
            'body' => "Body {$payload}",
        ]);

        $response = $this->actingAs(User::factory()->create())->get("/announcements/{$announcement->slug}");

        $response->assertOk();
        // Stored verbatim and handed to Vue as data...
        $response->assertInertia(fn ($page) => $page->where('announcement.body', "Body {$payload}"));
        // ...but the server-rendered HTML never contains it as live markup.
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $response->getContent());
        $this->assertStringNotContainsString('<img src=x onerror', $response->getContent());
    }

    public function test_announcement_components_never_render_raw_html(): void
    {
        $files = [
            ...File::allFiles(resource_path('js/Components/Announcements')),
            ...File::allFiles(resource_path('js/Pages/Portal/Announcements')),
            ...File::allFiles(resource_path('js/Pages/Admin/Announcements')),
        ];

        foreach ($files as $file) {
            // Matches the directive as an attribute, not prose in comments.
            $this->assertDoesNotMatchRegularExpression(
                '/\sv-html\s*=/',
                $file->getContents(),
                "{$file->getRelativePathname()} must render announcement content as text, not v-html.",
            );
        }
    }

    private function assertHiddenFromPortal(Announcement $announcement): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get("/announcements/{$announcement->slug}")->assertNotFound();

        $this->actingAs($user)
            ->get('/announcements')
            ->assertInertia(fn ($page) => $page->has('announcements.data', 0));
    }
}
