<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/announcements')->assertRedirect('/login');
    }

    public function test_unauthorized_user_cannot_view_the_index(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/announcements')->assertForbidden();
    }

    public function test_authorized_user_can_view_the_index_including_drafts(): void
    {
        $user = $this->userWithPermissions('announcements.view');
        Announcement::factory()->create();
        Announcement::factory()->published()->create();
        Announcement::factory()->archived()->create();

        $response = $this->actingAs($user)->get('/admin/announcements');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Announcements/Index')
            ->has('announcements.data', 3));
    }

    public function test_index_supports_search_and_filters(): void
    {
        $user = $this->userWithPermissions('announcements.view');
        Announcement::factory()->create(['title' => 'Findable Notice']);
        Announcement::factory()->published()->featured()->create(['title' => 'Other Item']);

        $this->actingAs($user)->get('/admin/announcements?search=Findable')
            ->assertInertia(fn ($page) => $page
                ->has('announcements.data', 1)
                ->where('announcements.data.0.title', 'Findable Notice'));

        $this->actingAs($user)->get('/admin/announcements?status=published')
            ->assertInertia(fn ($page) => $page
                ->has('announcements.data', 1)
                ->where('announcements.data.0.title', 'Other Item'));

        $this->actingAs($user)->get('/admin/announcements?featured=1')
            ->assertInertia(fn ($page) => $page
                ->has('announcements.data', 1)
                ->where('announcements.data.0.title', 'Other Item'));
    }

    public function test_unauthorized_user_cannot_create_an_announcement(): void
    {
        $user = $this->userWithPermissions('announcements.view');

        $this->actingAs($user)->post('/admin/announcements', $this->payload())->assertForbidden();

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_created_announcement_is_a_draft_authored_by_the_creator(): void
    {
        $user = $this->userWithPermissions('announcements.create');

        $response = $this->actingAs($user)->post('/admin/announcements', $this->payload([
            'status' => 'published',
            'author_id' => 999,
            'slug' => 'chosen-slug',
        ]));

        $announcement = Announcement::firstOrFail();
        $response->assertRedirect("/admin/announcements/{$announcement->id}");
        $this->assertSame('draft', $announcement->status->value);
        $this->assertSame($user->id, $announcement->author_id);
        $this->assertSame('office-closure-notice', $announcement->slug);
    }

    public function test_slugs_are_unique_for_duplicate_titles(): void
    {
        $user = $this->userWithPermissions('announcements.create');

        $this->actingAs($user)->post('/admin/announcements', $this->payload());
        $this->actingAs($user)->post('/admin/announcements', $this->payload());
        $this->actingAs($user)->post('/admin/announcements', $this->payload());

        $slugs = Announcement::pluck('slug');
        $this->assertCount(3, $slugs);
        $this->assertCount(3, $slugs->unique());
        $this->assertEqualsCanonicalizing(
            ['office-closure-notice', 'office-closure-notice-2', 'office-closure-notice-3'],
            $slugs->all(),
        );
    }

    public function test_slug_does_not_change_when_title_is_edited(): void
    {
        $user = $this->userWithPermissions('announcements.update');
        $announcement = Announcement::factory()->create(['slug' => 'original-slug']);

        $this->actingAs($user)->put("/admin/announcements/{$announcement->id}", $this->payload(['title' => 'Renamed']));

        $this->assertSame('Renamed', $announcement->fresh()->title);
        $this->assertSame('original-slug', $announcement->fresh()->slug);
    }

    public function test_required_fields_are_validated(): void
    {
        $user = $this->userWithPermissions('announcements.create');

        $this->actingAs($user)->post('/admin/announcements', [])
            ->assertSessionHasErrors(['title', 'body']);
    }

    public function test_expiration_must_be_after_publish_date(): void
    {
        $user = $this->userWithPermissions('announcements.create');

        $this->actingAs($user)->post('/admin/announcements', $this->payload([
            'publish_at' => '2026-11-10T00:00:00Z',
            'expires_at' => '2026-11-09T00:00:00Z',
        ]))->assertSessionHasErrors('expires_at');
    }

    public function test_timestamps_with_offsets_are_stored_as_the_same_instant(): void
    {
        $user = $this->userWithPermissions('announcements.create');

        $this->actingAs($user)->post('/admin/announcements', $this->payload([
            'publish_at' => '2026-11-10T08:00:00+08:00',
        ]));

        $this->assertTrue(
            Announcement::firstOrFail()->publish_at->equalTo('2026-11-10 00:00:00 UTC'),
        );
    }

    public function test_unauthorized_user_cannot_update(): void
    {
        $user = $this->userWithPermissions('announcements.view');
        $announcement = Announcement::factory()->create();

        $this->actingAs($user)
            ->put("/admin/announcements/{$announcement->id}", $this->payload(['title' => 'Hijacked']))
            ->assertForbidden();

        $this->assertNotSame('Hijacked', $announcement->fresh()->title);
    }

    public function test_publish_date_cannot_be_cleared_on_a_published_announcement(): void
    {
        $user = $this->userWithPermissions('announcements.update');
        $announcement = Announcement::factory()->published()->create();

        $this->actingAs($user)
            ->put("/admin/announcements/{$announcement->id}", $this->payload(['publish_at' => null]))
            ->assertSessionHasErrors('publish_at');
    }

    public function test_preview_shows_drafts_to_authorized_users_only(): void
    {
        $announcement = Announcement::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/admin/announcements/{$announcement->id}")
            ->assertForbidden();

        $this->actingAs($this->userWithPermissions('announcements.view'))
            ->get("/admin/announcements/{$announcement->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Announcements/Show')
                ->where('announcement.visibility_state', 'draft')
                ->where('announcement.body', $announcement->body));
    }

    public function test_publishing_without_a_date_publishes_immediately(): void
    {
        $user = $this->userWithPermissions('announcements.publish');
        $announcement = Announcement::factory()->create();

        $this->actingAs($user)
            ->patch("/admin/announcements/{$announcement->id}/status", ['status' => 'published'])
            ->assertSessionHasNoErrors();

        $announcement->refresh();
        $this->assertSame('published', $announcement->status->value);
        $this->assertNotNull($announcement->publish_at);
        $this->assertTrue($announcement->isPublished());
    }

    public function test_publishing_with_a_future_date_schedules(): void
    {
        $user = $this->userWithPermissions('announcements.publish');
        $announcement = Announcement::factory()->create(['publish_at' => now()->addDays(2)]);

        $this->actingAs($user)
            ->patch("/admin/announcements/{$announcement->id}/status", ['status' => 'published']);

        $announcement->refresh();
        $this->assertSame('published', $announcement->status->value);
        $this->assertSame('scheduled', $announcement->visibilityState());
    }

    public function test_cannot_publish_an_already_expired_announcement(): void
    {
        $user = $this->userWithPermissions('announcements.publish');
        $announcement = Announcement::factory()->create(['expires_at' => now()->subHour()]);

        $this->actingAs($user)
            ->patch("/admin/announcements/{$announcement->id}/status", ['status' => 'published'])
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', $announcement->fresh()->status->value);
    }

    public function test_unpublish_returns_to_draft(): void
    {
        $user = $this->userWithPermissions('announcements.publish');
        $announcement = Announcement::factory()->published()->create();

        $this->actingAs($user)
            ->patch("/admin/announcements/{$announcement->id}/status", ['status' => 'draft'])
            ->assertSessionHasNoErrors();

        $this->assertSame('draft', $announcement->fresh()->status->value);
    }

    public function test_update_permission_alone_cannot_publish(): void
    {
        $user = $this->userWithPermissions('announcements.view', 'announcements.update');
        $announcement = Announcement::factory()->create();

        $this->actingAs($user)
            ->patch("/admin/announcements/{$announcement->id}/status", ['status' => 'published'])
            ->assertForbidden();

        $this->assertSame('draft', $announcement->fresh()->status->value);
    }

    public function test_archive_permission_cannot_publish_and_publish_permission_cannot_archive(): void
    {
        $announcement = Announcement::factory()->create();

        $this->actingAs($this->userWithPermissions('announcements.archive'))
            ->patch("/admin/announcements/{$announcement->id}/status", ['status' => 'published'])
            ->assertForbidden();

        $this->actingAs($this->userWithPermissions('announcements.publish'))
            ->patch("/admin/announcements/{$announcement->id}/status", ['status' => 'archived'])
            ->assertForbidden();

        $this->assertSame('draft', $announcement->fresh()->status->value);
    }

    public function test_archive_and_restore(): void
    {
        $user = $this->userWithPermissions('announcements.archive');
        $announcement = Announcement::factory()->published()->create();

        $this->actingAs($user)
            ->patch("/admin/announcements/{$announcement->id}/status", ['status' => 'archived'])
            ->assertSessionHasNoErrors();
        $this->assertSame('archived', $announcement->fresh()->status->value);

        $this->actingAs($user)
            ->patch("/admin/announcements/{$announcement->id}/status", ['status' => 'draft'])
            ->assertSessionHasNoErrors();
        $this->assertSame('draft', $announcement->fresh()->status->value);
    }

    public function test_archived_announcement_cannot_be_published_directly(): void
    {
        $user = $this->userWithPermissions('announcements.publish', 'announcements.archive');
        $announcement = Announcement::factory()->archived()->create();

        $this->actingAs($user)
            ->patch("/admin/announcements/{$announcement->id}/status", ['status' => 'published'])
            ->assertSessionHasErrors('status');

        $this->assertSame('archived', $announcement->fresh()->status->value);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $user = $this->userWithPermissions('announcements.publish');
        $announcement = Announcement::factory()->create();

        $this->actingAs($user)
            ->patch("/admin/announcements/{$announcement->id}/status", ['status' => 'live'])
            ->assertSessionHasErrors('status');
    }

    public function test_feature_and_unfeature(): void
    {
        $user = $this->userWithPermissions('announcements.update');
        $announcement = Announcement::factory()->create();

        $this->actingAs($user)->patch("/admin/announcements/{$announcement->id}/featured", ['featured' => true]);
        $this->assertTrue($announcement->fresh()->featured);

        $this->actingAs($user)->patch("/admin/announcements/{$announcement->id}/featured", ['featured' => false]);
        $this->assertFalse($announcement->fresh()->featured);
    }

    public function test_unauthorized_user_cannot_feature(): void
    {
        $user = $this->userWithPermissions('announcements.view');
        $announcement = Announcement::factory()->create();

        $this->actingAs($user)
            ->patch("/admin/announcements/{$announcement->id}/featured", ['featured' => true])
            ->assertForbidden();

        $this->assertFalse($announcement->fresh()->featured);
    }

    public function test_announcements_view_grants_access_to_admin_landing(): void
    {
        $this->actingAs($this->userWithPermissions('announcements.view'))
            ->get('/admin')
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Office Closure Notice',
            'summary' => 'The office will be closed.',
            'body' => "First paragraph.\n\nSecond paragraph.",
            'publish_at' => null,
            'expires_at' => null,
            'featured' => false,
            ...$overrides,
        ];
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(
                Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]),
            );
        }

        $user->roles()->attach($role);

        return $user;
    }
}
