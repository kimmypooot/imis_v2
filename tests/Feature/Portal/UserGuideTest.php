<?php

namespace Tests\Feature\Portal;

use App\Models\User;
use App\Support\Guide\UserGuide;
use App\Support\HR\Payroll\PayrollAccounting;
use App\Support\HR\Payroll\PayslipApprovers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/guide')->assertRedirect('/login');
    }

    public function test_signed_in_users_see_the_guide_with_its_articles(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/guide')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Guide')
                ->has('categories', fn ($categories) => $categories->etc())
                ->where('categories.0.articles.0.slug', 'sign-in')
                ->has('categories.0.articles.0.steps'));
    }

    public function test_articles_that_need_a_permission_are_hidden_without_it(): void
    {
        config(['user-guide.categories' => [[
            'title' => 'Admin',
            'icon' => 'cog',
            'articles' => [
                ['slug' => 'secret', 'title' => 'Secret', 'summary' => 's', 'steps' => ['a'], 'requires' => ['users.view']],
                ['slug' => 'open', 'title' => 'Open', 'summary' => 's', 'steps' => ['a']],
            ],
        ], [
            'title' => 'Only secret',
            'icon' => 'cog',
            'articles' => [
                ['slug' => 'secret-2', 'title' => 'Secret 2', 'summary' => 's', 'steps' => ['a'], 'requires' => ['users.view']],
            ],
        ]]]);

        $categories = app(UserGuide::class)->for(User::factory()->create());

        $this->assertCount(1, $categories);
        $this->assertSame(['open'], array_column($categories[0]['articles'], 'slug'));
    }

    public function test_articles_for_a_position_based_role_show_only_to_people_in_it(): void
    {
        config(['user-guide.categories' => [[
            'title' => 'Approvals',
            'icon' => 'checkCircle',
            'articles' => [
                ['slug' => 'payslips', 'title' => 'Payslips', 'summary' => 's', 'steps' => ['a'], 'audience' => ['payslipApprover']],
                ['slug' => 'tax', 'title' => 'Tax', 'summary' => 's', 'steps' => ['a'], 'requires' => ['payroll.tax'], 'audience' => ['payrollTaxReviewer']],
                ['slug' => 'open', 'title' => 'Open', 'summary' => 's', 'steps' => ['a']],
            ],
        ]]]);

        $this->mock(PayslipApprovers::class, fn ($mock) => $mock->shouldReceive('isApprover')->andReturn(true));
        $this->mock(PayrollAccounting::class, fn ($mock) => $mock->shouldReceive('isReviewer')->andReturn(false));

        $categories = app(UserGuide::class)->for(User::factory()->create());

        $this->assertSame(['payslips', 'open'], array_column($categories[0]['articles'], 'slug'));
    }

    public function test_pages_share_a_help_link_to_the_article_that_explains_them(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/notifications')
            ->assertInertia(fn ($page) => $page
                ->where('help.slug', 'notifications-bell')
                ->where('help.href', '/guide#notifications-bell'));

        $this->actingAs($user)
            ->get('/guide')
            ->assertInertia(fn ($page) => $page->where('help', null));
    }

    public function test_help_link_prefers_the_article_linking_to_the_page_over_one_covering_it_by_pattern(): void
    {
        config(['user-guide.categories' => [[
            'title' => 'Records',
            'icon' => 'document',
            'articles' => [
                ['slug' => 'filing', 'title' => 'Filing', 'summary' => 's', 'steps' => ['a'], 'link' => ['label' => 'Go', 'href' => '/my/saln'], 'pages' => ['my/saln/*']],
                ['slug' => 'review', 'title' => 'Review', 'summary' => 's', 'steps' => ['a'], 'link' => ['label' => 'Go', 'href' => '/my/saln/queue']],
            ],
        ]]]);

        $guide = app(UserGuide::class);
        $user = User::factory()->create();

        $this->assertSame('filing', $guide->articleFor($user, '/my/saln')['slug']);
        $this->assertSame('filing', $guide->articleFor($user, 'my/saln/12/edit')['slug']);
        $this->assertSame('review', $guide->articleFor($user, 'my/saln/queue')['slug']);
        $this->assertNull($guide->articleFor($user, 'my/pds'));
    }

    public function test_help_link_skips_articles_the_person_may_not_read(): void
    {
        config(['user-guide.categories' => [[
            'title' => 'Admin',
            'icon' => 'cog',
            'articles' => [
                ['slug' => 'secret', 'title' => 'Secret', 'summary' => 's', 'steps' => ['a'], 'requires' => ['users.view'], 'link' => ['label' => 'Go', 'href' => '/admin/users']],
            ],
        ]]]);

        $this->assertNull(app(UserGuide::class)->articleFor(User::factory()->create(), 'admin/users'));
    }

    public function test_the_guide_lists_every_article_once_with_its_parts(): void
    {
        $articles = collect(config('user-guide.categories'))->flatMap(fn (array $category) => $category['articles']);

        $this->assertSame($articles->count(), $articles->pluck('slug')->unique()->count());
        $articles->each(function (array $article): void {
            $this->assertNotEmpty($article['title']);
            $this->assertNotEmpty($article['summary']);
            $this->assertNotEmpty($article['steps']);
        });
    }
}
