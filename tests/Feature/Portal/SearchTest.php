<?php

namespace Tests\Feature\Portal;

use App\Models\Announcement;
use App\Models\Application;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    // A word Faker won't generate, so random factory text never matches.
    private const TERM = 'Zephyrine';

    // --- Access ------------------------------------------------------------

    public function test_guest_cannot_search(): void
    {
        $this->get('/search?q=test')->assertRedirect('/login');
        $this->get('/search?q=test&type=services')->assertRedirect('/login');
    }

    // --- Query states ------------------------------------------------------

    public function test_empty_query_runs_no_search(): void
    {
        $this->actingAs(User::factory()->create())->get('/search')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Search')
                ->where('query', '')
                ->where('counts', null)
                ->where('groups', [])
                ->where('results', null)
                ->where('minLength', 2));
    }

    public function test_query_shorter_than_minimum_runs_no_search(): void
    {
        Service::factory()->create(['name' => 'Z Service']);

        $this->actingAs(User::factory()->create())->get('/search?q=Z')
            ->assertInertia(fn ($page) => $page
                ->where('query', 'Z')
                ->where('counts', null)
                ->where('groups', []));
    }

    public function test_query_is_trimmed_squished_and_capped(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/search?q='.urlencode('  leave    credits  '))
            ->assertInertia(fn ($page) => $page->where('query', 'leave credits'));

        $this->actingAs($user)->get('/search?q='.str_repeat('a', 150))
            ->assertInertia(fn ($page) => $page->where('query', str_repeat('a', 100)));
    }

    public function test_non_string_query_is_treated_as_empty(): void
    {
        $this->actingAs(User::factory()->create())->get('/search?q[]=x&type[]=services')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('query', '')->where('type', null));
    }

    // --- Search & grouping ------------------------------------------------

    public function test_results_are_grouped_by_type_in_order(): void
    {
        Announcement::factory()->published()->create(['title' => self::TERM.' Bulletin']);
        OrganizationalUnit::factory()->create(['name' => self::TERM.' Field Office']);
        Application::factory()->create(['name' => self::TERM.' HRIS']);
        Service::factory()->create(['name' => self::TERM.' Certification']);

        $this->actingAs(User::factory()->create())->get('/search?q='.self::TERM)
            ->assertInertia(fn ($page) => $page
                ->where('counts', [
                    'services' => 1,
                    'applications' => 1,
                    'organizational_units' => 1,
                    'announcements' => 1,
                ])
                ->has('groups', 4)
                ->where('groups.0.type', 'services')
                ->where('groups.0.label', 'Services')
                ->where('groups.0.items.0.type_label', 'Service')
                ->where('groups.1.type', 'applications')
                ->where('groups.1.label', 'Systems / Applications')
                ->where('groups.2.type', 'organizational_units')
                ->where('groups.3.type', 'announcements')
                ->where('groups.3.items.0.type_label', 'Announcement'));
    }

    public function test_results_link_to_portal_detail_pages(): void
    {
        $service = Service::factory()->create(['name' => self::TERM.' Service']);
        $application = Application::factory()->create(['name' => self::TERM.' App']);
        $unit = OrganizationalUnit::factory()->create(['name' => self::TERM.' Unit']);
        $announcement = Announcement::factory()->published()->create(['title' => self::TERM.' News']);

        $this->actingAs(User::factory()->create())->get('/search?q='.self::TERM)
            ->assertInertia(fn ($page) => $page
                ->where('groups.0.items.0.href', "/services/{$service->id}")
                ->where('groups.1.items.0.href', "/applications/{$application->id}")
                ->where('groups.2.items.0.href', "/organizational-units/{$unit->id}")
                ->where('groups.3.items.0.href', "/announcements/{$announcement->slug}"));
    }

    public function test_secondary_fields_are_searched(): void
    {
        Service::factory()->create(['name' => 'Alpha', 'short_name' => self::TERM]);
        Application::factory()->create(['name' => 'Beta', 'category' => self::TERM.' Tools']);
        OrganizationalUnit::factory()->create(['name' => 'Gamma', 'code' => self::TERM.'-01']);
        Announcement::factory()->published()->create(['title' => 'Delta', 'summary' => 'About '.self::TERM.'.']);

        $this->actingAs(User::factory()->create())->get('/search?q='.self::TERM)
            ->assertInertia(fn ($page) => $page->where('counts', [
                'services' => 1,
                'applications' => 1,
                'organizational_units' => 1,
                'announcements' => 1,
            ]));
    }

    public function test_search_is_case_insensitive(): void
    {
        Service::factory()->create(['name' => self::TERM.' Service']);

        $this->actingAs(User::factory()->create())->get('/search?q='.strtolower(self::TERM))
            ->assertInertia(fn ($page) => $page->where('counts.services', 1));
    }

    public function test_exact_and_prefix_title_matches_rank_first(): void
    {
        Service::factory()->create(['name' => 'Request a '.self::TERM, 'sort_order' => 0]);
        Service::factory()->create(['name' => self::TERM.' Request', 'sort_order' => 5]);
        Service::factory()->create(['name' => self::TERM, 'sort_order' => 9]);

        $this->actingAs(User::factory()->create())->get('/search?q='.self::TERM)
            ->assertInertia(fn ($page) => $page
                ->where('groups.0.items.0.title', self::TERM)
                ->where('groups.0.items.1.title', self::TERM.' Request')
                ->where('groups.0.items.2.title', 'Request a '.self::TERM));
    }

    public function test_like_wildcards_in_the_query_match_literally(): void
    {
        Service::factory()->create(['name' => 'Anything at all']);
        Service::factory()->create(['name' => 'Discount 50% off']);

        $user = User::factory()->create();

        $this->actingAs($user)->get('/search?q='.urlencode('%%'))
            ->assertInertia(fn ($page) => $page->where('counts.services', 0));

        $this->actingAs($user)->get('/search?q='.urlencode('__'))
            ->assertInertia(fn ($page) => $page->where('counts.services', 0));

        $this->actingAs($user)->get('/search?q='.urlencode('50%'))
            ->assertInertia(fn ($page) => $page->where('counts.services', 1));
    }

    public function test_no_results(): void
    {
        Service::factory()->create(['name' => 'Something Else Entirely']);

        $this->actingAs(User::factory()->create())->get('/search?q=NoSuchThingExists')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('counts', [
                    'services' => 0,
                    'applications' => 0,
                    'organizational_units' => 0,
                    'announcements' => 0,
                ])
                ->where('groups', []));
    }

    public function test_result_payload_is_lean(): void
    {
        Service::factory()->create([
            'name' => self::TERM,
            'procedure' => 'Long internal procedure text',
            'requirements' => 'Requirement list',
        ]);

        $response = $this->actingAs(User::factory()->create())->get('/search?q='.self::TERM);

        $item = $response->viewData('page')['props']['groups'][0]['items'][0];
        $this->assertSame(
            ['type', 'type_label', 'id', 'title', 'subtitle', 'excerpt', 'date', 'href'],
            array_keys($item),
        );
        $response->assertDontSee('Long internal procedure text');
    }

    // --- Filtering & pagination -------------------------------------------

    public function test_all_view_shows_a_bounded_preview_per_group_with_true_total(): void
    {
        Service::factory()->count(8)->sequence(fn ($s) => ['name' => self::TERM." Service {$s->index}"])->create();

        $this->actingAs(User::factory()->create())->get('/search?q='.self::TERM)
            ->assertInertia(fn ($page) => $page
                ->where('groups.0.total', 8)
                ->has('groups.0.items', 5)
                ->where('results', null));
    }

    public function test_type_filter_returns_only_that_type(): void
    {
        Service::factory()->create(['name' => self::TERM.' Service']);
        Application::factory()->create(['name' => self::TERM.' App']);

        $this->actingAs(User::factory()->create())->get('/search?q='.self::TERM.'&type=applications')
            ->assertInertia(fn ($page) => $page
                ->where('type', 'applications')
                ->where('groups', [])
                ->has('results.data', 1)
                ->where('results.data.0.type', 'applications')
                // Counts for every type stay available for the filter tabs.
                ->where('counts.services', 1));
    }

    public function test_filtered_results_are_paginated(): void
    {
        Service::factory()->count(20)->sequence(fn ($s) => ['name' => self::TERM." Service {$s->index}"])->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/search?q='.self::TERM.'&type=services')
            ->assertInertia(fn ($page) => $page
                ->has('results.data', 15)
                ->where('results.total', 20)
                ->where('results.current_page', 1)
                ->where('results.last_page', 2)
                ->where('results.next_page_url', fn ($url) => str_contains($url, 'q='.self::TERM)
                    && str_contains($url, 'type=services')
                    && str_contains($url, 'page=2')));

        $this->actingAs($user)->get('/search?q='.self::TERM.'&type=services&page=2')
            ->assertInertia(fn ($page) => $page->has('results.data', 5));
    }

    public function test_unknown_type_falls_back_to_all_and_never_searches_admin_records(): void
    {
        User::factory()->create(['name' => self::TERM.' Person']);
        Service::factory()->create(['name' => self::TERM.' Service']);

        $this->actingAs(User::factory()->create())->get('/search?q='.self::TERM.'&type=users')
            ->assertInertia(fn ($page) => $page
                ->where('type', null)
                ->where('results', null)
                ->has('groups', 1)
                ->where('groups.0.type', 'services')
                ->has('counts', 4)
                ->missing('counts.users'));
    }

    // --- Authorization & restricted records -------------------------------

    public function test_inactive_records_are_never_returned(): void
    {
        Service::factory()->inactive()->create(['name' => self::TERM.' Service']);
        Application::factory()->inactive()->create(['name' => self::TERM.' App']);
        OrganizationalUnit::factory()->inactive()->create(['name' => self::TERM.' Unit']);

        $admin = $this->userWithPermissions('services.view', 'applications.view');

        $this->actingAs($admin)->get('/search?q='.self::TERM)
            ->assertInertia(fn ($page) => $page
                ->where('counts.services', 0)
                ->where('counts.applications', 0)
                ->where('counts.organizational_units', 0)
                ->where('groups', []));
    }

    public function test_unpublished_announcements_are_never_returned(): void
    {
        Announcement::factory()->create(['title' => self::TERM.' Draft']);
        Announcement::factory()->scheduled()->create(['title' => self::TERM.' Scheduled']);
        Announcement::factory()->expired()->create(['title' => self::TERM.' Expired']);
        Announcement::factory()->archived()->create(['title' => self::TERM.' Archived']);
        Announcement::factory()->published()->create(['title' => self::TERM.' Live']);

        // Even an announcements administrator only finds live entries here.
        $admin = $this->userWithPermissions('announcements.view', 'announcements.update');

        $this->actingAs($admin)->get('/search?q='.self::TERM.'&type=announcements')
            ->assertInertia(fn ($page) => $page
                ->where('counts.announcements', 1)
                ->has('results.data', 1)
                ->where('results.data.0.title', self::TERM.' Live'));
    }

    public function test_administrative_records_are_hidden_from_regular_users(): void
    {
        Service::factory()->administrative()->create(['name' => self::TERM.' Internal Service']);
        Application::factory()->administrative()->create(['name' => self::TERM.' Internal App']);

        $this->actingAs(User::factory()->create())->get('/search?q='.self::TERM)
            ->assertInertia(fn ($page) => $page
                ->where('counts.services', 0)
                ->where('counts.applications', 0));

        $this->actingAs(User::factory()->create())->get('/search?q='.self::TERM.'&type=services')
            ->assertInertia(fn ($page) => $page->where('results.total', 0));
    }

    public function test_administrative_records_are_visible_to_admin_users(): void
    {
        Service::factory()->administrative()->create(['name' => self::TERM.' Internal Service']);
        Application::factory()->administrative()->create(['name' => self::TERM.' Internal App']);

        $this->actingAs($this->userWithPermissions('users.view'))->get('/search?q='.self::TERM)
            ->assertInertia(fn ($page) => $page
                ->where('counts.services', 1)
                ->where('counts.applications', 1));
    }

    public function test_direct_access_to_a_restricted_record_is_refused(): void
    {
        $internal = Service::factory()->administrative()->create(['name' => self::TERM.' Internal']);
        $inactiveUnit = OrganizationalUnit::factory()->inactive()->create();
        $draft = Announcement::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get("/services/{$internal->id}")->assertNotFound();
        $this->actingAs($user)->get("/organizational-units/{$inactiveUnit->id}")->assertNotFound();
        $this->actingAs($user)->get("/announcements/{$draft->slug}")->assertNotFound();
    }

    // --- Performance --------------------------------------------------------

    public function test_query_count_does_not_grow_with_results(): void
    {
        $user = User::factory()->create();
        $queriesFor = function (int $services) use ($user) {
            Service::query()->delete();
            Service::factory()->count($services)
                ->sequence(fn ($s) => [
                    'name' => self::TERM." Service {$s->index}",
                    'category_id' => ServiceCategory::factory()->create()->id,
                ])
                ->create();
            Application::factory()->count($services)
                ->sequence(fn ($s) => ['name' => self::TERM." App {$s->index}"])
                ->create();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->get('/search?q='.self::TERM)->assertOk();
            $this->actingAs($user)->get('/search?q='.self::TERM.'&type=services')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        // Warm one-time caches (the user's roles, settings) so only search
        // queries are compared.
        $this->actingAs($user)->get('/search?q='.self::TERM)->assertOk();

        $this->assertSame($queriesFor(2), $queriesFor(12));
    }

    // --- Helpers ------------------------------------------------------------

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
