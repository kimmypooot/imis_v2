<?php

namespace Tests\Feature\Portal;

use App\Models\User;
use App\Support\Portal\AtAGlance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtAGlanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_someone_without_an_employee_record_sees_only_their_notifications(): void
    {
        $user = User::factory()->create();

        $tiles = app(AtAGlance::class)->for($user);

        $this->assertSame(['notifications'], array_column($tiles, 'key'));
        $this->assertSame('0', $tiles[0]['value']);
        $this->assertSame('all read', $tiles[0]['hint']);
    }

    public function test_unread_notifications_are_counted(): void
    {
        $user = User::factory()->create();
        DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => ['title' => 'Hello', 'message' => 'World', 'kind' => 'leave'],
        ]);

        $tile = app(AtAGlance::class)->for($user)[0];

        $this->assertSame('1', $tile['value']);
        $this->assertSame('unread', $tile['hint']);
    }

    public function test_the_notifications_page_carries_each_notifications_kind(): void
    {
        $user = User::factory()->create();
        DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => ['title' => 'Hello', 'message' => 'World', 'kind' => 'leave'],
        ]);

        $this->actingAs($user)->get('/notifications')
            ->assertInertia(fn ($page) => $page->where('notifications.0.kind', 'leave'));
    }
}
