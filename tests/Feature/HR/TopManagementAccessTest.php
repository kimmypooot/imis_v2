<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\PlantillaItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HrRoleSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Top Management starter role (Director IV and III): view-only HR
 * oversight, never the PDS or 201 files, and no changes.
 */
class TopManagementAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_top_management_oversees_hr_without_the_pds_or_201_files_and_changes_nothing(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(HrRoleSeeder::class);

        $director = User::factory()->create();
        $director->roles()->attach(Role::query()->where('name', 'top-management')->sole());
        $employee = Employee::factory()->create();

        foreach ([
            '/admin/hr/employees',
            "/admin/hr/employees/{$employee->id}",
            "/admin/hr/employees/{$employee->id}/service-record",
            "/admin/hr/employees/{$employee->id}/learning-development",
            "/admin/hr/employees/{$employee->id}/leave",
            '/admin/hr/plantilla',
            '/admin/hr/positions',
            '/admin/hr/staffing',
            '/admin/hr/learning-development/dashboard',
            '/admin/hr/leave/reports',
        ] as $page) {
            $this->actingAs($director)->get($page)->assertOk();
        }

        // Never the PDS or the 201 file.
        $this->actingAs($director)->get("/admin/hr/employees/{$employee->id}/pds")->assertForbidden();
        $this->actingAs($director)->get("/admin/hr/employees/{$employee->id}/pds/print")->assertForbidden();
        $this->actingAs($director)->get("/admin/hr/employees/{$employee->id}/documents")->assertForbidden();

        // View only: no changes, and no spreadsheet completion.
        $this->actingAs($director)->post('/admin/hr/plantilla', ['position_title' => 'Clerk'])->assertForbidden();
        $this->actingAs($director)->get('/admin/hr/plantilla-sheet')->assertForbidden();
        $this->actingAs($director)->put("/admin/hr/employees/{$employee->id}", [])->assertForbidden();
        $this->assertSame(0, PlantillaItem::query()->count());
    }
}
