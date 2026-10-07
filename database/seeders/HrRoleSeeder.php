<?php

namespace Database\Seeders;

use App\Enums\PermissionName as P;
use App\Enums\RoleStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCache;
use Illuminate\Database\Seeder;

/**
 * Starter roles for the HR module, so HR doesn't need a Super
 * Administrator account. Each is created only if it doesn't exist yet,
 * and an existing role is never touched — admins own them after that
 * (Roles & Permissions). Assigning them to people is up to the admins.
 *
 * Immediate supervisors need no role for leave (Part B goes to the
 * supervisor named on the employee's leave settings); "Supervisor" only
 * adds seeing their own office's employee records. The Head of Agency
 * role approves leave (Parts C/D); an officer-in-charge acts through a
 * designation instead.
 */
class HrRoleSeeder extends Seeder
{
    /**
     * @return array<string, array{display_name: string, description: string, permissions: array<int, P>}>
     */
    public static function roles(): array
    {
        return [
            'hr-officer' => [
                'display_name' => 'HR Officer',
                'description' => 'Human Resource staff: employee records, PDS verification, 201 files, L&D, plantilla, Service Records, leave (including certifying credits), and payroll.',
                'permissions' => [
                    P::EmployeesView, P::EmployeesCreate, P::EmployeesUpdate, P::EmployeesReassign,
                    P::PdsView, P::PdsUpdate, P::PdsVerify,
                    P::EmployeeDocumentsView, P::EmployeeDocumentsManage,
                    P::LdView, P::LdManage, P::LdApprove,
                    P::PlantillaView, P::PlantillaManage, P::PlantillaCorrect,
                    P::ServiceRecordsView, P::ServiceRecordsManage,
                    P::LeaveView, P::LeaveManage, P::LeaveCertify,
                    P::PayrollView, P::PayrollManage,
                ],
            ],
            // Director IV and III: read-only oversight for management
            // decisions. Never the PDS or 201 files (RA 10173 data
            // minimization; `pds.view` and `employee_documents.view` are
            // left out on purpose). Edits stay with HRD.
            'top-management' => [
                'display_name' => 'Top Management',
                'description' => 'Regional and Assistant Regional Directors: view-only HR oversight — employee records, plantilla, positions and staffing, Service Records, L&D and leave — and the Executive Dashboard. Not PDS or 201 files.',
                'permissions' => [
                    P::EmployeesView,
                    P::PlantillaView,
                    P::ServiceRecordsView,
                    P::LdView,
                    P::LeaveView,
                    P::ExecutiveView,
                ],
            ],
            'head-of-agency' => [
                'display_name' => 'Head of Agency',
                'description' => 'Regional Director: final approval of leave applications (CS Form No. 6, Parts C/D).',
                'permissions' => [P::LeaveApprove],
            ],
            'supervisor' => [
                'display_name' => 'Supervisor',
                'description' => 'Office head or supervisor: sees the employee records of their own office (never PDS or 201 files).',
                'permissions' => [P::EmployeesViewUnit],
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::roles() as $name => $definition) {
            if (Role::query()->where('name', $name)->exists()) {
                continue;
            }

            $role = Role::query()->create([
                'name' => $name,
                'display_name' => $definition['display_name'],
                'description' => $definition['description'],
                'status' => RoleStatus::Active,
            ]);

            $role->permissions()->sync(Permission::query()
                ->whereIn('name', array_map(fn (P $permission) => $permission->value, $definition['permissions']))
                ->pluck('id'));
        }

        PermissionCache::flush();
    }
}
