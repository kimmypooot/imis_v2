<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditEvent;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Committee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * That the right actions leave the right entries — and nothing secret.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_successful_login_is_recorded_with_actor_target_and_request_metadata(): void
    {
        $user = User::factory()->create();

        $this->withHeader('User-Agent', 'AuditTest/1.0')
            ->post('/login', ['username' => $user->username, 'password' => 'password']);

        $log = $this->singleLog(AuditEvent::AuthLogin);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame('user', $log->target_type);
        $this->assertSame($user->id, $log->target_id);
        $this->assertSame('login', $log->action);
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertSame('AuditTest/1.0', $log->user_agent);
        $this->assertNotNull($log->created_at);
    }

    public function test_failed_login_is_recorded_without_the_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['username' => $user->username, 'password' => 'Wrong-Secret-123']);

        $log = $this->singleLog(AuditEvent::AuthLoginFailed);
        $this->assertNull($log->actor_id);
        $this->assertSame($user->id, $log->target_id);
        $this->assertSame($user->username, $log->metadata['username']);
        $this->assertSame('wrong_password', $log->metadata['reason']);
        $this->assertStringNotContainsString('Wrong-Secret-123', json_encode($log->getAttributes()));
    }

    public function test_failed_login_for_unknown_username_has_no_target(): void
    {
        $this->post('/login', ['username' => 'nobody', 'password' => 'whatever1']);

        $log = $this->singleLog(AuditEvent::AuthLoginFailed);
        $this->assertNull($log->target_id);
        $this->assertSame('unknown_account', $log->metadata['reason']);
    }

    public function test_blocked_login_for_inactive_account_is_recorded(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Inactive]);

        $this->post('/login', ['username' => $user->username, 'password' => 'password']);

        $log = $this->singleLog(AuditEvent::AuthLoginBlocked);
        $this->assertNull($log->actor_id);
        $this->assertSame($user->id, $log->target_id);
        $this->assertSame(0, AuditLog::where('event', AuditEvent::AuthLogin)->count());
    }

    public function test_lockout_is_recorded(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 6) as $attempt) {
            $this->post('/login', ['username' => $user->username, 'password' => 'wrong-pass-1']);
        }

        $this->assertSame(1, AuditLog::where('event', AuditEvent::AuthLockout)->count());
    }

    public function test_logout_is_recorded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        $log = $this->singleLog(AuditEvent::AuthLogout);
        $this->assertSame($user->id, $log->actor_id);
    }

    public function test_user_creation_is_attributed_to_the_admin_not_the_new_user(): void
    {
        $admin = $this->userWithPermissions('users.create');

        $this->actingAs($admin)->post('/users', [
            'name' => 'New Person',
            'email' => 'new.person@example.test',
            'role_ids' => [],
        ]);

        $created = User::where('email', 'new.person@example.test')->firstOrFail();
        $log = $this->singleLog(AuditEvent::UserCreated);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($created->id, $log->target_id);
        $this->assertSame('New Person', $log->target_label);
    }

    public function test_actor_cannot_be_forged_through_request_input(): void
    {
        $admin = $this->userWithPermissions('services.update');
        $victim = User::factory()->create();
        $service = Service::factory()->create(['name' => 'Before']);

        $this->actingAs($admin)->put("/admin/services/{$service->id}", [
            'name' => 'After',
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
            'actor_id' => $victim->id,
            'actor_label' => 'Someone Else',
            'user_id' => $victim->id,
        ]);

        $log = $this->singleLog(AuditEvent::ServiceUpdated);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertStringContainsString($admin->email, $log->actor_label);
    }

    public function test_updates_record_before_and_after_values(): void
    {
        $admin = $this->userWithPermissions('services.update');
        $service = Service::factory()->create(['name' => 'Before']);

        $this->actingAs($admin)->put("/admin/services/{$service->id}", [
            'name' => 'After',
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
        ]);

        $log = $this->singleLog(AuditEvent::ServiceUpdated);
        $this->assertSame(['from' => 'Before', 'to' => 'After'], $log->metadata['changes']['name']);
        $this->assertArrayNotHasKey('updated_at', $log->metadata['changes']);
    }

    public function test_saving_without_changes_records_nothing(): void
    {
        $admin = $this->userWithPermissions('services.update');
        $service = Service::factory()->create(['name' => 'Same', 'sort_order' => 0]);

        $this->actingAs($admin)->put("/admin/services/{$service->id}", [
            'name' => 'Same',
            'description' => $service->description,
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
        ]);

        $this->assertSame(0, AuditLog::count());
    }

    public function test_account_deactivation_is_recorded(): void
    {
        $admin = $this->userWithPermissions('users.delete');
        $user = User::factory()->create();

        $this->actingAs($admin)->patch("/users/{$user->id}/status", ['status' => 'inactive']);

        $log = $this->singleLog(AuditEvent::UserStatusChanged);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($user->id, $log->target_id);
        $this->assertStringStartsWith('Deactivated', $log->description);
        $this->assertSame(['from' => 'active', 'to' => 'inactive'], $log->metadata['changes']['status']);
    }

    public function test_role_assignment_records_added_and_removed_role_names(): void
    {
        $admin = $this->userWithPermissions('users.update');
        $user = User::factory()->create();
        $old = Role::factory()->create(['name' => 'old-role']);
        $new = Role::factory()->create(['name' => 'new-role']);
        $user->roles()->attach($old);

        $this->actingAs($admin)->put("/users/{$user->id}/roles", ['role_ids' => [$new->id]]);

        $log = $this->singleLog(AuditEvent::UserRolesChanged);
        $this->assertSame($user->id, $log->target_id);
        $this->assertSame(['new-role'], $log->metadata['added']);
        $this->assertSame(['old-role'], $log->metadata['removed']);
    }

    public function test_permission_assignment_is_recorded(): void
    {
        // Phase 9's escalation guard only lets an actor grant permissions they hold.
        $admin = $this->userWithPermissions('permissions.manage', 'services.view');
        $role = Role::factory()->create();
        $permission = Permission::query()->firstOrCreate(['name' => 'services.view'], ['display_name' => 'x']);

        $this->actingAs($admin)->put("/roles/{$role->id}/permissions", ['permission_ids' => [$permission->id]]);

        $log = $this->singleLog(AuditEvent::RolePermissionsChanged);
        $this->assertSame('role', $log->target_type);
        $this->assertSame($role->id, $log->target_id);
        $this->assertSame(['services.view'], $log->metadata['added']);
    }

    public function test_committee_membership_is_recorded_against_the_committee(): void
    {
        $admin = $this->userWithPermissions('committees.update');
        $committee = Committee::factory()->create();
        $member = User::factory()->create();

        $this->actingAs($admin)->post("/admin/committees/{$committee->id}/members", [
            'user_id' => $member->id,
            'position' => 'Chairperson',
            'effective_from' => '2026-10-01',
            'status' => 'active',
        ]);

        $log = $this->singleLog(AuditEvent::CommitteeMemberAdded);
        $this->assertSame('committee', $log->target_type);
        $this->assertSame($committee->id, $log->target_id);
        $this->assertSame($member->id, $log->metadata['member_user_id']);
        $this->assertSame('Chairperson', $log->metadata['position']);
    }

    public function test_announcement_publication_is_recorded(): void
    {
        $admin = $this->userWithPermissions('announcements.publish');
        $announcement = Announcement::factory()->create();

        $this->actingAs($admin)->patch("/admin/announcements/{$announcement->id}/status", ['status' => 'published']);

        $log = $this->singleLog(AuditEvent::AnnouncementPublished);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('announcement', $log->target_type);
        $this->assertSame($announcement->id, $log->target_id);
        $this->assertSame('draft', $log->metadata['from']);
        $this->assertSame('published', $log->metadata['to']);
    }

    public function test_announcement_unpublish_archive_and_restore_get_distinct_events(): void
    {
        $admin = $this->userWithPermissions('announcements.publish', 'announcements.archive');
        $announcement = Announcement::factory()->published()->create();
        $url = "/admin/announcements/{$announcement->id}/status";

        $this->actingAs($admin)->patch($url, ['status' => 'draft']);
        $this->actingAs($admin)->patch($url, ['status' => 'archived']);
        $this->actingAs($admin)->patch($url, ['status' => 'draft']);

        $this->assertSame(
            ['announcement.unpublished', 'announcement.archived', 'announcement.restored'],
            AuditLog::orderBy('id')->pluck('event')->map->value->all(),
        );
    }

    public function test_rejected_actions_are_not_recorded(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->create();

        $this->actingAs($user)->patch("/admin/services/{$service->id}/status", ['status' => 'inactive'])
            ->assertForbidden();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_sensitive_metadata_keys_are_stripped_and_long_values_truncated(): void
    {
        $this->actingAs(User::factory()->create());

        $log = app(AuditLogger::class)->record(AuditEvent::UserUpdated, 'Test', null, [
            'password' => 'hunter2',
            'password_confirmation' => 'hunter2',
            'remember_token' => 'abc',
            '_token' => 'csrf',
            'api_key' => 'key',
            'nested' => ['secret' => 'shh', 'access_token' => 'tok', 'kept' => 'yes'],
            'long' => str_repeat('a', 1000),
            'remember' => true,
        ]);

        $metadata = $log->fresh()->metadata;
        $this->assertSame(['nested', 'long', 'remember'], array_keys($metadata));
        $this->assertSame(['kept' => 'yes'], $metadata['nested']);
        $this->assertLessThanOrEqual(258, strlen($metadata['long']));
        $this->assertStringNotContainsString('hunter2', json_encode($metadata));
    }

    public function test_password_and_remember_token_changes_are_never_recorded(): void
    {
        $this->actingAs(User::factory()->create());
        $user = User::factory()->create();
        $user->update(['password' => 'Another-Pass-123', 'remember_token' => 'new-token', 'name' => 'Renamed']);

        $log = app(AuditLogger::class)->recordChanges(AuditEvent::UserUpdated, 'Test', $user);

        $this->assertSame(['name'], array_keys($log->metadata['changes']));
    }

    public function test_password_reset_completion_is_recorded_without_the_token_or_password(): void
    {
        $user = User::factory()->create();
        $token = app('auth.password.broker')->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Brand-New-Pass-123',
            'password_confirmation' => 'Brand-New-Pass-123',
        ]);

        $log = $this->singleLog(AuditEvent::AuthPasswordReset);
        $this->assertSame($user->id, $log->actor_id);
        $serialized = json_encode($log->getAttributes());
        $this->assertStringNotContainsString($token, $serialized);
        $this->assertStringNotContainsString('Brand-New-Pass-123', $serialized);
    }

    private function singleLog(AuditEvent $event): AuditLog
    {
        $logs = AuditLog::where('event', $event)->get();
        $this->assertCount(1, $logs, "Expected exactly one {$event->value} entry.");

        return $logs->first();
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
