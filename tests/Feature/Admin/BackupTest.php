<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Legacy "Backup Database": Super Administrators only, password re-check,
 * audited, and secrets left out of the dump.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_holding_the_permission_without_being_a_super_admin_is_not_enough(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'backups.create'], ['display_name' => 'Backups']));
        $user->roles()->attach($role);

        $this->actingAs($user)->get('/admin/backup')->assertForbidden();
        $this->actingAs($user)->post('/admin/backup', ['password' => 'password'])->assertForbidden();
    }

    public function test_page_lists_tables_and_marks_structure_only_ones(): void
    {
        $this->actingAs($this->superAdmin())->get('/admin/backup')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Backup/Index')
            ->where('tables', fn ($tables) => collect($tables)->firstWhere('name', 'sessions')['structure_only'] === true
                && collect($tables)->firstWhere('name', 'users')['rows'] >= 1));
    }

    public function test_download_requires_the_current_password(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/admin/backup', ['password' => 'wrong-one'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('audit_logs', ['event' => 'system.backup_downloaded']);
    }

    public function test_download_streams_a_dump_without_secrets_and_is_audited(): void
    {
        $admin = $this->superAdmin();
        $admin->forceFill(['remember_token' => 'secret-remember-token'])->save();
        DB::table('password_reset_tokens')->insert(['email' => 'x@example.test', 'token' => 'secret-reset-token', 'created_at' => now()]);

        $response = $this->actingAs($admin)->post('/admin/backup', ['password' => 'password']);

        $response->assertOk();
        $this->assertStringContainsString('attachment; filename=imis-backup-', $response->headers->get('Content-Disposition'));
        $sql = $response->streamedContent();

        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString($admin->username, $sql);
        $this->assertStringNotContainsString('secret-remember-token', $sql);
        $this->assertStringNotContainsString('secret-reset-token', $sql);
        $this->assertDatabaseHas('audit_logs', ['event' => 'system.backup_downloaded', 'actor_id' => $admin->id]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->firstOrCreate(['name' => RoleName::SuperAdmin->value], ['display_name' => 'Super Administrator']);
        $user->roles()->attach($role);

        return $user;
    }
}
