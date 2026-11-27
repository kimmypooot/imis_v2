<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `throttle:N,M` counts each route separately for a signed-in user, so
 * using one limited page doesn't use up another page's limit.
 */
class ThrottlePerRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'throttle:3,1'])->get('/_test/busy', fn () => 'ok');
        Route::middleware(['web', 'auth', 'throttle:3,1'])->get('/_test/strict', fn () => 'ok');
    }

    public function test_one_routes_requests_do_not_count_against_another(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($user)->get('/_test/busy')->assertOk();
        }

        $this->actingAs($user)->get('/_test/busy')->assertTooManyRequests();
        $this->actingAs($user)->get('/_test/strict')->assertOk();
    }
}
