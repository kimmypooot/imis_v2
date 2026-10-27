<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\HrUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationDropdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_read_the_dropdown(): void
    {
        $this->getJson('/notifications/recent')->assertUnauthorized();
    }

    public function test_the_dropdown_lists_only_my_latest_eight_newest_first(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        foreach (range(1, 10) as $number) {
            $me->notify(new HrUpdate("Mine {$number}", 'Message', '/my/payslips'));
        }
        $other->notify(new HrUpdate('Not mine', 'Message', '/my/payslips'));

        $response = $this->actingAs($me)->getJson('/notifications/recent')->assertOk();

        $response->assertJsonCount(8, 'notifications');
        $response->assertJsonPath('unread', 10);
        $this->assertNotContains('Not mine', array_column($response->json('notifications'), 'title'));
        $this->assertFalse($response->json('notifications.0.read'));
    }
}
