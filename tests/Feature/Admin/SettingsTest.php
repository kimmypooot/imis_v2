<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\Settings;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingSeeder::class);
    }

    // --- Authorization -------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/settings')->assertRedirect('/login');
        $this->put('/admin/settings/general', ['settings' => ['portal_name' => 'X']])->assertRedirect('/login');

        $this->assertSame('CSC RO VIII IMIS', $this->stored('portal_name'));
    }

    public function test_user_without_permission_cannot_view_or_update(): void
    {
        $user = $this->userWithPermissions('users.view', 'audit_logs.view');

        $this->actingAs($user)->get('/admin/settings')->assertForbidden();
        $this->actingAs($user)
            ->put('/admin/settings/general', ['settings' => ['portal_name' => 'Hijacked']])
            ->assertForbidden();

        $this->assertSame('CSC RO VIII IMIS', $this->stored('portal_name'));
        $this->assertSame(0, AuditLog::count());
    }

    public function test_view_only_user_sees_settings_but_cannot_update_them(): void
    {
        $user = $this->userWithPermissions('settings.view');

        $this->actingAs($user)->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Settings/Index')
                ->where('canUpdate', false)
                ->has('groups', 9)
                ->where('groups.0.key', 'general')
                ->where('groups.0.settings.0.key', 'organization_name')
                ->where('groups.0.settings.0.editable', false));

        // A hand-crafted request is refused even though the form is hidden.
        $this->actingAs($user)
            ->put('/admin/settings/general', ['settings' => ['portal_name' => 'Changed']])
            ->assertForbidden();

        $this->assertSame('CSC RO VIII IMIS', $this->stored('portal_name'));
    }

    public function test_update_permission_alone_cannot_reach_the_index(): void
    {
        $user = $this->userWithPermissions('settings.update');

        $this->actingAs($user)->get('/admin/settings')->assertForbidden();
    }

    public function test_settings_view_grants_admin_landing(): void
    {
        $this->actingAs($this->userWithPermissions('settings.view'))->get('/admin')->assertOk();
    }

    public function test_unknown_group_is_not_found(): void
    {
        $this->actingAs($this->editor())
            ->put('/admin/settings/database', ['settings' => ['portal_name' => 'X']])
            ->assertNotFound();
    }

    // --- Persistence & typed values ------------------------------------

    public function test_authorized_user_can_update_a_group_with_typed_values(): void
    {
        $this->actingAs($this->editor())
            ->from('/admin/settings')
            ->put('/admin/settings/portal', ['settings' => [
                'portal_welcome_message' => 'Hello, RO VIII.',
                'portal_home_announcement_limit' => '5',
                'portal_notice_enabled' => true,
                'portal_notice' => 'Maintenance on Saturday.',
            ]])
            ->assertRedirect('/admin/settings')
            ->assertSessionHasNoErrors();

        $this->assertSame('5', $this->stored('portal_home_announcement_limit'));
        $this->assertSame('1', $this->stored('portal_notice_enabled'));

        $this->assertSame(5, Settings::get('portal_home_announcement_limit'));
        $this->assertTrue(Settings::get('portal_notice_enabled'));
        $this->assertSame('Hello, RO VIII.', Settings::get('portal_welcome_message'));
        $this->assertSame('Maintenance on Saturday.', Settings::get('portal_notice'));
    }

    public function test_only_submitted_keys_are_changed(): void
    {
        $this->actingAs($this->editor())
            ->put('/admin/settings/contact', ['settings' => ['contact_phone' => '(053) 123-4567']])
            ->assertSessionHasNoErrors();

        $this->assertSame('(053) 123-4567', $this->stored('contact_phone'));
        $this->assertNull($this->stored('contact_email'));
    }

    public function test_boolean_can_be_switched_off_and_empty_values_are_stored_as_null(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->put('/admin/settings/portal', ['settings' => [
            'portal_notice_enabled' => true,
            'portal_notice' => 'Notice',
        ]]);
        $this->actingAs($editor)->put('/admin/settings/portal', ['settings' => [
            'portal_notice_enabled' => false,
            'portal_notice' => '',
        ]])->assertSessionHasNoErrors();

        $this->assertSame('0', $this->stored('portal_notice_enabled'));
        $this->assertNull($this->stored('portal_notice'));
        $this->assertFalse(Settings::get('portal_notice_enabled'));
    }

    public function test_cleared_value_falls_back_to_its_default(): void
    {
        $this->actingAs($this->editor())
            ->put('/admin/settings/portal', ['settings' => ['portal_welcome_message' => '']])
            ->assertSessionHasNoErrors();

        $this->assertSame(SettingRegistry::default('portal_welcome_message'), Settings::get('portal_welcome_message'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: mixed}>
     */
    public static function invalidValues(): array
    {
        return [
            'integer: not a number' => ['portal', 'portal_home_announcement_limit', 'abc'],
            'integer: decimal' => ['portal', 'portal_home_announcement_limit', '2.5'],
            'integer: below minimum' => ['portal', 'portal_home_announcement_limit', 0],
            'integer: above maximum' => ['portal', 'portal_home_announcement_limit', 11],
            'integer: required' => ['portal', 'portal_home_announcement_limit', ''],
            'boolean: not a boolean' => ['portal', 'portal_notice_enabled', 'maybe'],
            'boolean: missing value' => ['portal', 'portal_notice_enabled', null],
            'text: too long' => ['portal', 'portal_notice', str_repeat('a', 501)],
            'text: not a string' => ['portal', 'portal_notice', ['nested' => 'array']],
            'string: required' => ['general', 'portal_name', ''],
            'string: too long' => ['general', 'portal_name', str_repeat('a', 61)],
            'url: not a url' => ['contact', 'contact_website_url', 'not a url'],
            'url: javascript scheme' => ['contact', 'contact_website_url', 'javascript:alert(1)'],
            'url: ftp scheme' => ['contact', 'contact_website_url', 'ftp://csc.gov.ph'],
            'email: invalid' => ['contact', 'contact_email', 'not-an-email'],
            'select: not an option' => ['human_resources', 'hr_certifying_officer_position', 'Regional Director'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_values_are_validated_by_type(string $group, string $key, mixed $value): void
    {
        $before = $this->stored($key);

        $this->actingAs($this->editor())
            ->put("/admin/settings/{$group}", ['settings' => [$key => $value]])
            ->assertSessionHasErrors("settings.{$key}");

        $this->assertSame($before, $this->stored($key));
        $this->assertSame(0, AuditLog::count());
    }

    public function test_valid_url_and_email_are_accepted(): void
    {
        $this->actingAs($this->editor())
            ->put('/admin/settings/contact', ['settings' => [
                'contact_email' => 'helpdesk@example.gov.ph',
                'contact_website_url' => 'https://example.gov.ph/ro8',
            ]])
            ->assertSessionHasNoErrors();

        $this->assertSame('https://example.gov.ph/ro8', Settings::get('contact_website_url'));
    }

    public function test_certifying_officer_position_is_one_of_its_options_or_unset(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->get('/admin/settings')->assertInertia(fn ($page) => $page
            ->where('groups.4.settings.1.type', 'select')
            ->where('groups.4.settings.1.options', [
                'Chief Human Resource Specialist, Human Resource Division',
                'Director IV',
                'Director III',
            ]));

        $this->actingAs($editor)
            ->put('/admin/settings/human_resources', ['settings' => ['hr_certifying_officer_position' => 'Director IV']])
            ->assertSessionHasNoErrors();
        $this->assertSame('Director IV', Settings::get('hr_certifying_officer_position'));

        $this->actingAs($editor)
            ->put('/admin/settings/human_resources', ['settings' => ['hr_certifying_officer_position' => '']])
            ->assertSessionHasNoErrors();
        $this->assertNull(Settings::get('hr_certifying_officer_position'));
    }

    public function test_one_invalid_value_saves_nothing_in_the_group(): void
    {
        $this->actingAs($this->editor())
            ->put('/admin/settings/portal', ['settings' => [
                'portal_welcome_message' => 'Valid',
                'portal_home_announcement_limit' => 'invalid',
            ]])
            ->assertSessionHasErrors('settings.portal_home_announcement_limit');

        $this->assertSame(SettingRegistry::default('portal_welcome_message'), $this->stored('portal_welcome_message'));
    }

    public function test_settings_payload_is_required(): void
    {
        $this->actingAs($this->editor())
            ->put('/admin/settings/general', [])
            ->assertSessionHasErrors('settings');
    }

    public function test_unknown_or_other_group_keys_are_rejected(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->put('/admin/settings/general', ['settings' => ['app_debug' => '1']])
            ->assertSessionHasErrors('settings.app_debug');

        $this->actingAs($editor)
            ->put('/admin/settings/general', ['settings' => ['portal_name' => 'Ok', 'contact_email' => 'a@b.ph']])
            ->assertSessionHasErrors('settings.contact_email');

        $this->assertSame('CSC RO VIII IMIS', $this->stored('portal_name'));
        $this->assertNull($this->stored('contact_email'));
        $this->assertSame(0, Setting::query()->where('key', 'app_debug')->count());
    }

    // --- Protected settings --------------------------------------------

    public function test_protected_setting_cannot_be_changed_by_an_editor(): void
    {
        $this->actingAs($this->editor())
            ->put('/admin/settings/general', ['settings' => ['organization_name' => 'Fake Agency']])
            ->assertSessionHasErrors('settings.organization_name');

        $this->assertSame('Civil Service Commission Regional Office VIII', $this->stored('organization_name'));
    }

    public function test_protected_setting_cannot_be_changed_even_by_super_admin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::where('name', RoleName::SuperAdmin->value)->value('id'));

        $this->actingAs($superAdmin)
            ->put('/admin/settings/general', ['settings' => [
                'portal_name' => 'Renamed',
                'organization_name' => 'Fake Agency',
            ]])
            ->assertSessionHasErrors('settings.organization_name');

        // The whole request is rejected, not just the protected key.
        $this->assertSame('Civil Service Commission Regional Office VIII', $this->stored('organization_name'));
        $this->assertSame('CSC RO VIII IMIS', $this->stored('portal_name'));
    }

    public function test_editable_flag_and_type_cannot_be_changed_through_the_request(): void
    {
        $this->actingAs($this->editor())
            ->put('/admin/settings/general', [
                'settings' => ['portal_name' => 'Renamed'],
                'editable' => true,
                'type' => 'text',
            ])
            ->assertSessionHasNoErrors();

        $protected = Setting::query()->where('key', 'organization_name')->first();
        $this->assertFalse($protected->editable);
        $this->assertSame('string', Setting::query()->where('key', 'portal_name')->first()->type->value);
    }

    public function test_setting_model_is_not_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);

        Setting::query()->where('key', 'organization_name')->first()->update(['editable' => true]);
    }

    // --- Cache -----------------------------------------------------------

    public function test_values_are_cached_after_first_read(): void
    {
        Settings::get('portal_name');

        $this->assertTrue(Cache::has(Settings::CACHE_KEY));
    }

    public function test_update_invalidates_the_cache(): void
    {
        $this->assertSame('CSC RO VIII IMIS', Settings::get('portal_name'));
        $this->assertTrue(Cache::has(Settings::CACHE_KEY));

        $this->actingAs($this->editor())
            ->put('/admin/settings/general', ['settings' => ['portal_name' => 'RO VIII Portal']])
            ->assertSessionHasNoErrors();

        $this->assertSame('RO VIII Portal', Settings::get('portal_name'));
    }

    public function test_next_request_sees_the_new_value_in_shared_props(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->get('/admin/settings')
            ->assertInertia(fn ($page) => $page->where('portal.name', 'CSC RO VIII IMIS'));

        $this->actingAs($editor)->put('/admin/settings/general', ['settings' => ['portal_name' => 'RO VIII Portal']]);

        $this->actingAs($editor)->get('/admin/settings')
            ->assertInertia(fn ($page) => $page->where('portal.name', 'RO VIII Portal'));
    }

    public function test_any_model_save_invalidates_the_cache(): void
    {
        Settings::get('portal_name');

        $setting = Setting::query()->where('key', 'portal_name')->first();
        $setting->value = 'Saved directly';
        $setting->save();

        $this->assertFalse(Cache::has(Settings::CACHE_KEY));
        $this->assertSame('Saved directly', Settings::get('portal_name'));
    }

    public function test_rejected_update_leaves_cached_value_in_place(): void
    {
        Settings::get('portal_name');

        $this->actingAs($this->editor())
            ->put('/admin/settings/general', ['settings' => ['portal_name' => '']])
            ->assertSessionHasErrors();

        $this->assertTrue(Cache::has(Settings::CACHE_KEY));
        $this->assertSame('CSC RO VIII IMIS', Settings::get('portal_name'));
    }

    // --- Audit -----------------------------------------------------------

    public function test_changes_are_audited_per_setting(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->put('/admin/settings/portal', ['settings' => [
            'portal_home_announcement_limit' => 4,
            'portal_notice_enabled' => false, // unchanged
        ]]);

        $logs = AuditLog::all();
        $this->assertCount(1, $logs);

        $log = $logs->first();
        $this->assertSame(AuditEvent::SettingUpdated, $log->event);
        $this->assertSame($editor->id, $log->actor_id);
        $this->assertSame('setting', $log->target_type);
        $this->assertSame('Announcements on portal home', $log->target_label);
        $this->assertSame('portal_home_announcement_limit', $log->metadata['key']);
        $this->assertSame(['from' => '3', 'to' => '4'], $log->metadata['changes']['value']);
    }

    public function test_unchanged_save_records_nothing(): void
    {
        $this->actingAs($this->editor())
            ->put('/admin/settings/general', ['settings' => ['portal_name' => 'CSC RO VIII IMIS']])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, AuditLog::count());
    }

    // --- Secrets ---------------------------------------------------------

    public function test_settings_page_exposes_no_environment_secrets(): void
    {
        config(['database.connections.mysql.password' => 'db-password-should-not-leak']);

        $response = $this->actingAs($this->editor())->get('/admin/settings')->assertOk();

        $this->assertNotEmpty(config('app.key'));
        $response->assertDontSee(config('app.key'), false);
        $response->assertDontSee('db-password-should-not-leak', false);

        $keys = collect($response->viewData('page')['props']['groups'])
            ->flatMap(fn ($group) => collect($group['settings'])->pluck('key'));

        $this->assertEqualsCanonicalizing(array_keys(SettingRegistry::definitions()), $keys->all());
    }

    public function test_no_defined_setting_looks_like_a_credential(): void
    {
        // `password_policy_*` keys describe password rules, so only what
        // follows the prefix is checked (as SettingRegistry::sync() does).
        foreach (array_keys(SettingRegistry::definitions()) as $key) {
            $this->assertDoesNotMatchRegularExpression('/password|secret|token|api_?key|credential|^db_|^mail_/i', str($key)->after('password_policy_')->value());
        }
    }

    public function test_shared_portal_props_are_a_whitelist(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('portal', fn ($portal) => $portal
                    ->where('name', 'CSC RO VIII IMIS')
                    ->where('organization', 'Civil Service Commission Regional Office VIII')
                    ->where('notice', null)));
    }

    // --- Sync ------------------------------------------------------------

    public function test_sync_keeps_edited_values_resets_protected_and_removes_obsolete(): void
    {
        Setting::query()->where('key', 'portal_name')->first()->forceFill(['value' => 'Edited'])->save();
        Setting::query()->where('key', 'organization_name')->first()->forceFill(['value' => 'Tampered'])->save();
        (new Setting)->forceFill([
            'key' => 'obsolete_setting', 'type' => 'string', 'group' => 'general', 'label' => 'Old',
        ])->save();

        SettingRegistry::sync();

        $this->assertSame('Edited', $this->stored('portal_name'));
        $this->assertSame('Civil Service Commission Regional Office VIII', $this->stored('organization_name'));
        $this->assertFalse(Setting::query()->where('key', 'obsolete_setting')->exists());
    }

    public function test_unseeded_settings_fall_back_to_defaults(): void
    {
        Setting::query()->delete();

        $this->assertSame('CSC RO VIII IMIS', Settings::get('portal_name'));
        $this->assertSame(3, Settings::get('portal_home_announcement_limit'));
        $this->assertFalse(Settings::get('portal_notice_enabled'));
    }

    public function test_reading_an_undefined_setting_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Settings::get('app_key');
    }

    // --- Settings take effect --------------------------------------------

    public function test_portal_notice_is_shared_only_when_enabled_and_signed_in(): void
    {
        $this->actingAs($this->editor())->put('/admin/settings/portal', ['settings' => [
            'portal_notice_enabled' => true,
            'portal_notice' => 'System maintenance tonight.',
        ]]);

        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('portal.notice', 'System maintenance tonight.'));

        auth()->logout();
        $this->get('/login')->assertInertia(fn ($page) => $page->where('portal.notice', null));
    }

    public function test_portal_home_uses_announcement_limit_and_welcome_message(): void
    {
        Announcement::factory()->published()->count(3)->create();

        $this->actingAs($this->editor())->put('/admin/settings/portal', ['settings' => [
            'portal_home_announcement_limit' => 1,
            'portal_welcome_message' => 'Mabuhay!',
        ]]);

        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('announcements', 1)
                ->where('welcomeMessage', 'Mabuhay!'));
    }

    // --- Helpers ---------------------------------------------------------

    private function stored(string $key): ?string
    {
        return Setting::query()->where('key', $key)->value('value');
    }

    private function editor(): User
    {
        return $this->userWithPermissions('settings.view', 'settings.update');
    }

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
