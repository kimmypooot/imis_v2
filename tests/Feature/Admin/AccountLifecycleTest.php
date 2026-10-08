<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\SettingRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin-3, account lifecycle: separated employees and dormant accounts
 * are deactivated overnight, and deactivating signs a person out at once.
 */
class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingRegistry::sync();
    }

    public function test_separated_employees_lose_their_accounts(): void
    {
        $gone = Employee::factory()->create(['separation_date' => today()->subDay(), 'separation_reason' => 'Retirement']);
        $leaving = Employee::factory()->create(['separation_date' => today()->addWeek(), 'separation_reason' => 'Resignation']);
        $staying = Employee::factory()->create();

        $this->artisan('imis:account-lifecycle')->assertSuccessful();

        $this->assertSame(UserStatus::Inactive, $gone->user->fresh()->status);
        $this->assertSame(UserStatus::Active, $leaving->user->fresh()->status);
        $this->assertSame(UserStatus::Active, $staying->user->fresh()->status);

        $entry = AuditLog::query()->where('event', 'user.status_changed')->sole();
        $this->assertNull($entry->actor_id);
        $this->assertTrue($entry->metadata['automatic']);
        $this->assertStringContainsString('Separated from service', $entry->metadata['reason']);
    }

    public function test_dormant_accounts_are_deactivated_only_when_turned_on(): void
    {
        $dormant = User::factory()->create(['created_at' => now()->subYear(), 'last_login_at' => now()->subDays(200)]);
        $recent = User::factory()->create(['created_at' => now()->subYear(), 'last_login_at' => now()->subDays(10)]);

        $this->artisan('imis:account-lifecycle');
        $this->assertSame(UserStatus::Active, $dormant->fresh()->status);

        $this->set('account_inactive_days', 90);
        $this->artisan('imis:account-lifecycle');

        $this->assertSame(UserStatus::Inactive, $dormant->fresh()->status);
        $this->assertSame(UserStatus::Active, $recent->fresh()->status);
    }

    public function test_a_reactivated_account_is_not_caught_again_the_next_night(): void
    {
        $this->set('account_inactive_days', 90);
        $user = User::factory()->create(['created_at' => now()->subYear(), 'last_login_at' => now()->subDays(200)]);

        $this->artisan('imis:account-lifecycle');
        $this->assertSame(UserStatus::Inactive, $user->fresh()->status);

        $user->fresh()->update(['status' => UserStatus::Active]);
        $this->artisan('imis:account-lifecycle');

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }

    public function test_super_administrators_are_never_deactivated_automatically(): void
    {
        $this->set('account_inactive_days', 90);
        $admin = User::factory()->create(['created_at' => now()->subYear(), 'last_login_at' => now()->subDays(200)]);
        $admin->roles()->attach(Role::query()->create(['name' => RoleName::SuperAdmin->value, 'display_name' => 'Super Administrator', 'status' => 'active']));

        $this->artisan('imis:account-lifecycle')->expectsOutputToContain('left active')->assertSuccessful();

        $this->assertSame(UserStatus::Active, $admin->fresh()->status);
    }

    public function test_deactivating_signs_the_person_out_everywhere(): void
    {
        $target = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'session-a', 'user_id' => $target->id, 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'session-b', 'user_id' => $target->id, 'payload' => '', 'last_activity' => now()->timestamp],
        ]);
        $admin = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'users.delete'], ['display_name' => 'Users Delete']));
        $admin->roles()->attach($role);

        $this->actingAs($admin)->patch("/users/{$target->id}/status", ['status' => 'inactive'])
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'signed out of 2 sessions'));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
    }

    private function set(string $key, mixed $value): void
    {
        $setting = Setting::query()->where('key', $key)->firstOrFail();
        $setting->value = $setting->type->serialize($value);
        $setting->save();
    }
}
