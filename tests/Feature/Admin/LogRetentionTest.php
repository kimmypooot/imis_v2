<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * System Settings → Log Retention, applied nightly by imis:prune-logs.
 */
class LogRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingSeeder::class);
    }

    public function test_login_and_access_logs_older_than_their_period_are_deleted(): void
    {
        $user = User::factory()->create();
        $this->login($user, now()->subMonths(25));
        $this->login($user, now()->subMonths(23));
        $this->access($user, now()->subMonths(30));
        $this->access($user, now()->subDay());

        $this->artisan('imis:prune-logs')->assertSuccessful();

        $this->assertSame(1, DB::table('login_histories')->count());
        $this->assertSame(1, DB::table('system_access_logs')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'system.logs_pruned']);
    }

    public function test_audit_entries_are_kept_forever_by_default(): void
    {
        AuditLog::factory()->create(['event' => AuditEvent::UserCreated, 'action' => 'created', 'created_at' => now()->subYears(10)]);

        $this->artisan('imis:prune-logs')->assertSuccessful();

        $this->assertSame(1, AuditLog::count());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'system.logs_pruned']);
    }

    public function test_personal_data_access_has_its_own_period(): void
    {
        $this->set('retention_audit_log_months', 12);
        AuditLog::factory()->create(['event' => AuditEvent::UserCreated, 'action' => 'created', 'created_at' => now()->subMonths(13)]);
        AuditLog::factory()->create(['event' => AuditEvent::PdsViewed, 'action' => 'viewed', 'created_at' => now()->subMonths(13)]);

        $this->artisan('imis:prune-logs')->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.created']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'pds.viewed']);

        $this->set('retention_personal_data_access_months', 36);
        AuditLog::factory()->create(['event' => AuditEvent::PdsViewed, 'action' => 'viewed', 'created_at' => now()->subMonths(37)]);

        $this->artisan('imis:prune-logs')->assertSuccessful();

        $this->assertSame(1, AuditLog::query()->where('event', 'pds.viewed')->count());
    }

    public function test_a_period_below_its_floor_is_raised_to_the_floor(): void
    {
        // Bypasses the settings form, which refuses this.
        Setting::query()->where('key', 'retention_login_history_months')->update(['value' => '1']);
        $user = User::factory()->create();
        $this->login($user, now()->subMonths(3));

        $this->artisan('imis:prune-logs')->assertSuccessful();

        $this->assertSame(1, DB::table('login_histories')->count());
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->login(User::factory()->create(), now()->subYears(5));

        $this->artisan('imis:prune-logs --dry-run')->assertSuccessful();

        $this->assertSame(1, DB::table('login_histories')->count());
    }

    private function login(User $user, $at): void
    {
        DB::table('login_histories')->insert(['user_id' => $user->id, 'username' => $user->username, 'logged_in_at' => $at]);
    }

    private function access(User $user, $at): void
    {
        DB::table('system_access_logs')->insert([
            'user_id' => $user->id, 'username' => $user->username, 'application_name' => 'Test', 'accessed_at' => $at,
        ]);
    }

    private function set(string $key, mixed $value): void
    {
        $setting = Setting::query()->where('key', $key)->firstOrFail();
        $setting->value = $setting->type->serialize($value);
        $setting->save();
    }
}
