<?php

namespace Tests\Feature\Admin;

use App\Enums\Status;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_the_delete_permission_is_forbidden(): void
    {
        $actor = $this->userWithPermissions('applications.update', 'applications.archive');
        $application = Application::factory()->create(['status' => Status::Inactive]);

        $this->actingAs($actor)->delete("/admin/applications/{$application->id}")->assertForbidden();

        $this->assertModelExists($application);
    }

    public function test_an_unused_active_system_can_be_deleted_and_is_audited(): void
    {
        $actor = $this->userWithPermissions('applications.delete');
        $application = Application::factory()->create(['status' => Status::Active, 'name' => 'Trial System']);

        $this->actingAs($actor)->delete("/admin/applications/{$application->id}")->assertSessionHasNoErrors();

        $this->assertModelMissing($application);
        $this->assertSame('Deleted system Trial System', AuditLog::query()->where('event', 'application.deleted')->sole()->description);
    }

    public function test_a_retired_system_is_deleted_with_its_roles_and_access(): void
    {
        $actor = $this->userWithPermissions('applications.delete');
        $application = Application::factory()->create(['status' => Status::Inactive]);
        $role = ApplicationRole::factory()->create(['application_id' => $application->id]);
        $access = ApplicationAccess::factory()->create(['application_id' => $application->id, 'application_role_id' => $role->id]);

        $this->actingAs($actor)->delete("/admin/applications/{$application->id}");

        $this->assertModelMissing($application);
        $this->assertModelMissing($role);
        $this->assertModelMissing($access);
    }

    public function test_an_active_system_with_users_cannot_be_deleted(): void
    {
        $actor = $this->userWithPermissions('applications.delete');
        $application = Application::factory()->create(['status' => Status::Active]);
        $role = ApplicationRole::factory()->create(['application_id' => $application->id]);
        ApplicationAccess::factory()->create(['application_id' => $application->id, 'application_role_id' => $role->id]);

        $this->actingAs($actor)->delete("/admin/applications/{$application->id}")->assertSessionHas('error');

        $this->assertModelExists($application);
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
