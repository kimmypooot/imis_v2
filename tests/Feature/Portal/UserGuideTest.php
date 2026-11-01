<?php

namespace Tests\Feature\Portal;

use App\Models\User;
use App\Support\Guide\UserGuide;
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
}
