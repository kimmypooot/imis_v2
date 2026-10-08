<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ExecutiveAlert;
use App\Notifications\HrUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Admin-3, notification settings: the bell always; email per kind when
 * the person asks for it.
 */
class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_see_only_the_kinds_offered_to_them(): void
    {
        $this->actingAs(User::factory()->create())->get('/account/notifications')->assertInertia(fn (Assert $page) => $page
            ->component('Account/Notifications')
            ->where('kinds', fn ($kinds) => collect($kinds)->pluck('kind')->all() === ['leave', 'hr']));

        $this->actingAs($this->userWith(['executive.view']))->get('/account/notifications')->assertInertia(fn (Assert $page) => $page
            ->where('kinds', fn ($kinds) => collect($kinds)->pluck('kind')->all() === ['leave', 'hr', 'executive', 'executive_digest']
                && collect($kinds)->firstWhere('kind', 'executive_digest')['email'] === true));
    }

    public function test_email_follows_the_persons_choice(): void
    {
        $user = User::factory()->create();
        $notification = new HrUpdate('Payslip approved', 'Your payslip for October is ready.', '/my/payslips');

        $this->assertSame(['database'], $notification->via($user));

        $this->actingAs($user)->put('/account/notifications', ['email' => ['hr' => true, 'leave' => false, 'executive' => true]])->assertSessionHasNoErrors();
        $user->refresh();

        $this->assertSame(['database', 'mail'], $notification->via($user));
        $this->assertSame('Payslip approved', $notification->toMail($user)->subject);
        // Not offered to this person, so not saved.
        $this->assertArrayNotHasKey('executive', $user->notification_preferences);
        $this->assertSame(['database'], (new ExecutiveAlert('x', 'y'))->via($user));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }

        $user->roles()->attach($role);

        return $user;
    }
}
