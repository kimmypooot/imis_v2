<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\EmailTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\TestEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Admin-3, email templates: the office's wording, a new account's
 * set-password email, and the editor's rules.
 */
class EmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_account_gets_the_set_password_email_and_others_the_reset_one(): void
    {
        $new = User::factory()->create(['first_name' => 'Ana', 'username' => 'areyes', 'last_login_at' => null]);
        $returning = User::factory()->create(['last_login_at' => now()->subDay()]);

        $setup = (new ResetPassword('token'))->toMail($new);
        $reset = (new ResetPassword('token'))->toMail($returning);

        $this->assertStringContainsString('account is ready', $setup->subject);
        $this->assertTrue(collect($setup->introLines)->contains(fn ($line) => str_contains($line, 'areyes')));
        $this->assertSame('Set your password', $setup->actionText);
        $this->assertStringContainsString('Reset your', $reset->subject);
    }

    public function test_the_offices_wording_is_used_once_saved(): void
    {
        $admin = $this->userWith(['settings.view', 'settings.update']);

        $this->actingAs($admin)->put('/admin/email-templates/password_reset', [
            'subject' => 'IMIS password for {username}',
            'intro' => "Hello {first_name}.\n\nClick below.",
            'button' => 'Go',
            'outro' => '',
        ])->assertSessionHasNoErrors();

        $mail = (new ResetPassword('token'))->toMail(User::factory()->create(['first_name' => 'Ana', 'username' => 'areyes', 'last_login_at' => now()]));

        $this->assertSame('IMIS password for areyes', $mail->subject);
        $this->assertSame(['Hello Ana.', 'Click below.'], $mail->introLines);
        $this->assertTrue(AuditLog::query()->where('event', 'setting.email_template_updated')->exists());

        $this->actingAs($admin)->delete('/admin/email-templates/password_reset');
        $this->assertSame(0, EmailTemplate::query()->count());
    }

    public function test_unknown_placeholders_and_templates_are_refused(): void
    {
        $admin = $this->userWith(['settings.view', 'settings.update']);

        $this->actingAs($admin)->put('/admin/email-templates/password_reset', ['subject' => 'Hi {frist_name}', 'intro' => 'x'])
            ->assertSessionHasErrors('subject');
        $this->actingAs($admin)->put('/admin/email-templates/nonexistent', ['subject' => 'x'])->assertNotFound();
    }

    public function test_viewers_see_the_templates_and_only_updaters_change_them(): void
    {
        $viewer = $this->userWith(['settings.view']);

        $this->actingAs($viewer)->get('/admin/email-templates')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/EmailTemplates')
            ->where('canUpdate', false)
            ->has('templates', 4));
        $this->actingAs($viewer)->put('/admin/email-templates/password_reset', ['subject' => 'x', 'intro' => 'y'])->assertForbidden();
    }

    public function test_a_test_goes_to_the_administrator_only(): void
    {
        Notification::fake();
        $admin = $this->userWith(['settings.view', 'settings.update']);

        $this->actingAs($admin)->post('/admin/email-templates/account_setup/test')->assertSessionHas('status');

        Notification::assertSentTo(new AnonymousNotifiable, TestEmail::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $admin->email
            && str_starts_with($notification->toMail($notifiable)->subject, '[Test]'));
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
