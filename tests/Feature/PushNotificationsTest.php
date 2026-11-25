<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\HrUpdate;
use App\Notifications\PushTest;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

/**
 * Push notifications: allowed by the Super Administrator (System Settings
 * → Notifications), switched on per device by each person, and generic
 * in what they say.
 */
class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingSeeder::class);
        config(['webpush.vapid.public_key' => 'test-public-key', 'webpush.vapid.private_key' => 'test-private-key']);
    }

    public function test_push_is_hidden_and_refused_until_the_super_administrator_allows_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/account/notifications')->assertInertia(fn (Assert $page) => $page->where('push', null));
        $this->actingAs($user)->post('/account/push-subscriptions', $this->subscription())->assertNotFound();

        $this->allowPush();
        $this->actingAs($user)->get('/account/notifications')->assertInertia(fn (Assert $page) => $page
            ->where('push.publicKey', 'test-public-key')
            ->where('push.endpoints', []));
    }

    public function test_a_device_is_switched_on_and_off_and_moves_to_whoever_switched_it_on_last(): void
    {
        $this->allowPush();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first)->post('/account/push-subscriptions', $this->subscription())->assertSessionHasNoErrors();
        $this->assertSame([self::ENDPOINT], $first->pushSubscriptions()->pluck('endpoint')->all());

        // Same browser, another person signs in and switches it on.
        $this->actingAs($second)->post('/account/push-subscriptions', $this->subscription())->assertSessionHasNoErrors();
        $this->assertSame(0, $first->pushSubscriptions()->count());

        // Switching off removes only your own device.
        $this->actingAs($first)->delete('/account/push-subscriptions', ['endpoint' => self::ENDPOINT]);
        $this->assertSame(1, $second->pushSubscriptions()->count());
        $this->actingAs($second)->delete('/account/push-subscriptions', ['endpoint' => self::ENDPOINT]);
        $this->assertSame(0, $second->pushSubscriptions()->count());
    }

    public function test_only_the_major_push_services_are_accepted_as_endpoints(): void
    {
        $this->allowPush();
        $user = User::factory()->create();

        foreach (['http://fcm.googleapis.com/fcm/send/x', 'https://127.0.0.1/hook', 'https://evilfcm.googleapis.com.example.com/x', 'https://intranet.local/x'] as $endpoint) {
            $this->actingAs($user)->post('/account/push-subscriptions', $this->subscription($endpoint))->assertSessionHasErrors('endpoint');
        }

        foreach (['https://updates.push.services.mozilla.com/wpush/v2/x', 'https://web.push.apple.com/x', 'https://wns2-sg2p.notify.windows.com/w/?token=x'] as $endpoint) {
            $this->actingAs($user)->post('/account/push-subscriptions', $this->subscription($endpoint))->assertSessionHasNoErrors();
        }
        $this->assertSame(3, $user->pushSubscriptions()->count());
    }

    public function test_bell_notifications_are_pushed_to_active_accounts_with_a_device_and_say_nothing_personal(): void
    {
        $user = User::factory()->create();
        $user->updatePushSubscription(self::ENDPOINT, 'key', 'token');
        $notification = new HrUpdate('Payslip approved', 'Your payslip for October is ready.', '/my/payslips');

        // Not allowed yet.
        $this->assertSame(['database'], $notification->via($user));

        $this->allowPush();
        $this->assertSame(['database', WebPushChannel::class], $notification->via($user));
        $this->assertSame(['database'], $notification->via(User::factory()->create()));

        $payload = $notification->toWebPush($user)->toArray();
        $this->assertSame('You have a new HR requests notification. Open IMIS to read it.', $payload['body']);
        $this->assertSame(['url' => '/my/payslips'], $payload['data']);
        $this->assertStringNotContainsString('Payslip', json_encode($payload));

        // Never a link to another site.
        $this->assertSame(['url' => '/notifications'], (new HrUpdate('x', 'y', 'https://example.com'))->toWebPush($user)->toArray()['data']);

        $user->forceFill(['status' => UserStatus::Inactive])->save();
        $this->assertSame(['database'], $notification->via($user->fresh()));
    }

    public function test_a_test_push_goes_only_to_your_own_devices(): void
    {
        $this->allowPush();
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/account/push-subscriptions/test')->assertSessionHas('error');
        Notification::assertNothingSent();

        $user->updatePushSubscription(self::ENDPOINT, 'key', 'token');
        $this->actingAs($user)->post('/account/push-subscriptions/test')->assertSessionHas('status');
        Notification::assertSentTo($user, PushTest::class, fn (PushTest $notification) => $notification->via($user) === [WebPushChannel::class]);
    }

    private function allowPush(): void
    {
        $setting = Setting::query()->where('key', 'push_notifications_enabled')->firstOrFail();
        $setting->value = $setting->type->serialize(true);
        $setting->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(string $endpoint = self::ENDPOINT): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPublicKey', 'auth' => 'AuthSecret'], 'contentEncoding' => 'aes128gcm'];
    }
}
