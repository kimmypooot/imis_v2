<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Backup\BackupStore;
use App\Support\System\HealthChecks;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Backups kept on the server: the nightly imis:backup, "Back up now",
 * keep-count pruning, downloading and deleting them, and the health check.
 */
class BackupStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingSeeder::class);
        Storage::fake('local');
    }

    public function test_command_saves_a_compressed_restorable_backup_and_audits_it(): void
    {
        $this->artisan('imis:backup')->assertSuccessful();

        $backups = app(BackupStore::class)->all();
        $this->assertCount(1, $backups);
        $this->assertTrue($backups[0]['automatic']);
        $this->assertStringContainsString('CREATE TABLE', gzdecode(file_get_contents(app(BackupStore::class)->path($backups[0]['name']))));
        $this->assertDatabaseHas('audit_logs', ['event' => 'system.backup_created', 'actor_id' => null]);
        $this->assertSame([], glob(Storage::disk('local')->path('backups/*.partial')));
    }

    public function test_scheduled_run_is_skipped_while_the_setting_is_off(): void
    {
        $this->set('backup_schedule_enabled', false);

        $this->artisan('imis:backup --if-enabled')->assertSuccessful();

        $this->assertSame([], app(BackupStore::class)->all());
    }

    public function test_only_the_newest_backups_are_kept(): void
    {
        $this->set('backup_keep_count', 2);
        foreach (['2026-01-01-020000-auto', '2026-01-02-020000-auto', '2026-01-03-020000-manual'] as $stamp) {
            Storage::disk('local')->put("backups/imis-backup-{$stamp}.sql.gz", 'x');
        }
        Storage::disk('local')->put('backups/notes.txt', 'not a backup');

        $this->artisan('imis:backup')->assertSuccessful();

        $names = array_column(app(BackupStore::class)->all(), 'name');
        $this->assertCount(2, $names);
        $this->assertSame('imis-backup-2026-01-03-020000-manual.sql.gz', $names[1]);
        Storage::disk('local')->assertExists('backups/notes.txt');
    }

    public function test_page_lists_stored_backups_and_back_up_now_saves_one(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post('/admin/backup/files')->assertRedirect()->assertSessionHas('status');

        $this->actingAs($admin)->get('/admin/backup')->assertInertia(fn (Assert $page) => $page
            ->has('backups', 1)
            ->where('backups.0.automatic', false)
            ->where('schedule.enabled', true)
            ->where('schedule.keep', 14));
        $this->assertDatabaseHas('audit_logs', ['event' => 'system.backup_created', 'actor_id' => $admin->id]);
    }

    public function test_downloading_a_stored_backup_needs_the_password_and_is_audited(): void
    {
        $admin = $this->superAdmin();
        $name = 'imis-backup-2026-01-01-020000-auto.sql.gz';
        Storage::disk('local')->put("backups/{$name}", 'dump');

        $this->actingAs($admin)->postJson("/admin/backup/files/{$name}/download", ['password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->actingAs($admin)->post("/admin/backup/files/{$name}/download", ['password' => 'password'])
            ->assertOk()->assertDownload($name);
        $this->assertDatabaseHas('audit_logs', ['event' => 'system.backup_downloaded', 'actor_id' => $admin->id]);
    }

    public function test_only_backup_files_can_be_reached(): void
    {
        $admin = $this->superAdmin();
        Storage::disk('local')->put('backups/other.sql', 'secret');
        Storage::disk('local')->put('secret.sql.gz', 'secret');

        $this->actingAs($admin)->post('/admin/backup/files/other.sql/download', ['password' => 'password'])->assertNotFound();
        $this->actingAs($admin)->post('/admin/backup/files/..%2Fsecret.sql.gz/download', ['password' => 'password'])->assertNotFound();
        $this->actingAs($admin)->delete('/admin/backup/files/other.sql')->assertNotFound();
        Storage::disk('local')->assertExists('backups/other.sql');
    }

    public function test_a_stored_backup_can_be_deleted(): void
    {
        $admin = $this->superAdmin();
        $name = 'imis-backup-2026-01-01-020000-auto.sql.gz';
        Storage::disk('local')->put("backups/{$name}", 'dump');

        $this->actingAs($admin)->delete("/admin/backup/files/{$name}")->assertRedirect();

        Storage::disk('local')->assertMissing("backups/{$name}");
        $this->assertDatabaseHas('audit_logs', ['event' => 'system.backup_deleted']);
    }

    public function test_holders_of_the_permission_who_are_not_super_admins_are_refused(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'backups.create'], ['display_name' => 'Backups']));
        $user->roles()->attach($role);
        $name = 'imis-backup-2026-01-01-020000-auto.sql.gz';
        Storage::disk('local')->put("backups/{$name}", 'dump');

        $this->actingAs($user)->post('/admin/backup/files')->assertForbidden();
        $this->actingAs($user)->post("/admin/backup/files/{$name}/download", ['password' => 'password'])->assertForbidden();
        $this->actingAs($user)->delete("/admin/backup/files/{$name}")->assertForbidden();
        Storage::disk('local')->assertExists("backups/{$name}");
    }

    public function test_health_check_reports_backup_age(): void
    {
        $this->assertSame('warn', $this->backupCheck()['status']);

        Storage::disk('local')->put('backups/imis-backup-'.now()->subHours(2)->format('Y-m-d-His').'-auto.sql.gz', 'x');
        $this->assertSame('ok', $this->backupCheck()['status']);

        Storage::disk('local')->deleteDirectory('backups');
        Storage::disk('local')->put('backups/imis-backup-'.now()->subDays(4)->format('Y-m-d-His').'-auto.sql.gz', 'x');
        $this->assertSame('fail', $this->backupCheck()['status']);
    }

    private function backupCheck(): array
    {
        return collect(app(HealthChecks::class)->run())->firstWhere('label', 'Automatic backups');
    }

    private function set(string $key, mixed $value): void
    {
        $setting = Setting::query()->where('key', $key)->firstOrFail();
        $setting->value = $setting->type->serialize($value);
        $setting->save();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->firstOrCreate(['name' => RoleName::SuperAdmin->value], ['display_name' => 'Super Administrator']);
        $user->roles()->attach($role);

        return $user;
    }
}
