<?php

namespace Tests\Feature\Admin;

use App\Models\Changelog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChangelogsTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = [
        'version' => '2.1.0',
        'title' => 'Maintenance notices',
        'release_date' => '2026-10-08',
        'items' => [
            ['type' => 'feature', 'description' => 'Systems can be put under maintenance.'],
            ['type' => 'fix', 'description' => 'Badges no longer wrap.'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // A migration ships the v3.0.0 draft; these tests start from none.
        Changelog::query()->delete();
    }

    public function test_the_v3_release_note_ships_as_a_draft(): void
    {
        $this->artisan('migrate:refresh', ['--path' => 'database/migrations/2026_11_06_090000_add_v3_release_note.php'])->assertSuccessful();

        $release = Changelog::query()->where('version', '3.0.0')->sole();
        $this->assertFalse($release->isPublished());
        $this->assertSame(14, $release->items()->count());
    }

    public function test_listing_requires_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/changelogs')->assertForbidden();
        $this->actingAs($this->userWith('changelogs.view'))->get('/admin/changelogs')->assertOk();
    }

    public function test_writing_requires_manage_permission(): void
    {
        $this->actingAs($this->userWith('changelogs.view'))->post('/admin/changelogs', self::PAYLOAD)->assertForbidden();
        $this->assertDatabaseCount('changelogs', 0);
    }

    public function test_a_release_note_is_saved_as_a_draft_with_ordered_entries(): void
    {
        $actor = $this->userWith('changelogs.manage');

        $this->actingAs($actor)->post('/admin/changelogs', self::PAYLOAD)->assertSessionHasNoErrors();

        $changelog = Changelog::query()->sole();
        $this->assertSame('draft', $changelog->status);
        $this->assertSame($actor->id, $changelog->created_by);
        $this->assertSame(['feature', 'fix'], $changelog->items->pluck('type')->map->value->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'changelog.created', 'target_id' => $changelog->id]);
    }

    public function test_status_cannot_be_set_through_the_form(): void
    {
        $this->actingAs($this->userWith('changelogs.manage'))
            ->post('/admin/changelogs', [...self::PAYLOAD, 'status' => 'published', 'published_at' => now()]);

        $this->assertSame('draft', Changelog::query()->sole()->status);
    }

    public function test_validation(): void
    {
        $actor = $this->userWith('changelogs.manage');
        Changelog::factory()->create(['version' => '2.1.0']);

        $this->actingAs($actor)->post('/admin/changelogs', self::PAYLOAD)->assertSessionHasErrors('version');
        $this->actingAs($actor)->post('/admin/changelogs', [...self::PAYLOAD, 'version' => '2.2.0', 'items' => []])
            ->assertSessionHasErrors('items');
        $this->actingAs($actor)->post('/admin/changelogs', [
            ...self::PAYLOAD, 'version' => '2.2.0', 'items' => [['type' => 'bogus', 'description' => '']],
        ])->assertSessionHasErrors(['items.0.type', 'items.0.description']);
    }

    public function test_editing_replaces_the_entries(): void
    {
        $changelog = Changelog::factory()->create(['version' => '2.1.0']);
        $changelog->replaceItems([['type' => 'feature', 'description' => 'Old']]);

        $this->actingAs($this->userWith('changelogs.manage'))
            ->put("/admin/changelogs/{$changelog->id}", [...self::PAYLOAD, 'items' => [['type' => 'improvement', 'description' => 'New']]])
            ->assertSessionHasNoErrors();

        $this->assertSame(['New'], $changelog->fresh()->items->pluck('description')->all());
    }

    public function test_publishing_requires_its_own_permission_and_is_audited(): void
    {
        $changelog = Changelog::factory()->create();

        $this->actingAs($this->userWith('changelogs.manage'))
            ->patch("/admin/changelogs/{$changelog->id}/status", ['status' => 'published'])
            ->assertForbidden();

        $this->actingAs($this->userWith('changelogs.publish'))
            ->patch("/admin/changelogs/{$changelog->id}/status", ['status' => 'published']);

        $this->assertTrue($changelog->fresh()->isPublished());
        $this->assertNotNull($changelog->fresh()->published_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'changelog.published']);
    }

    // ── Portal ───────────────────────────────────────────────────────

    public function test_the_shown_version_follows_the_latest_published_release(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('app.version', config('app.version')));

        Changelog::factory()->published()->create(['version' => '2.7.0', 'published_at' => now()->subDay()]);
        Changelog::factory()->published()->create(['version' => '2.8.0']);
        Changelog::factory()->create(['version' => '2.9.0']); // draft, ignored

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('app.version', '2.8.0'));
    }

    public function test_dashboard_pops_up_the_latest_published_release_once(): void
    {
        Changelog::factory()->published()->create(['version' => '2.0.0', 'published_at' => now()->subDay()]);
        $latest = Changelog::factory()->published()->create(['version' => '2.1.0']);
        Changelog::factory()->create(['version' => '2.2.0']); // draft, never shown
        $latest->replaceItems([['type' => 'feature', 'description' => 'Something new']]);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('whatsNew.version', '2.1.0')
            ->where('whatsNew.items.0.type_label', 'New'));

        $this->actingAs($user)->post('/whats-new/seen')->assertRedirect();

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('whatsNew', null));
    }

    public function test_history_page_lists_published_releases_and_marks_them_seen(): void
    {
        $published = Changelog::factory()->published()->create();
        Changelog::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/whats-new')->assertInertia(fn (Assert $page) => $page
            ->component('Portal/WhatsNew')
            ->has('releases', 1));

        $this->assertSame($published->id, $user->fresh()->last_seen_changelog_id);
    }

    public function test_no_pop_up_without_published_releases(): void
    {
        Changelog::factory()->create();

        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('whatsNew', null));
    }

    private function userWith(string $permission): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(
            Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]),
        );
        $user->roles()->attach($role);

        return $user;
    }
}
