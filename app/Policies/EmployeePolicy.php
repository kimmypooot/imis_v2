<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Employee;
use App\Models\User;

/**
 * HR-1 employee records. Super Administrators pass every check through
 * Gate::before, so the self-edit guard below never blocks them.
 */
class EmployeePolicy
{
    /**
     * The HR list: everyone's records or, for supervisors, their office's.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([
            PermissionName::EmployeesView->value,
            PermissionName::EmployeesViewUnit->value,
        ]);
    }

    /**
     * Same rule as Employee::scopeVisibleTo(): your own record always, your
     * office's with `view_unit`, anyone's with `view`.
     */
    public function view(User $user, Employee $employee): bool
    {
        if ($employee->user_id === $user->id || $user->hasPermission(PermissionName::EmployeesView->value)) {
            return true;
        }

        return $user->organizational_unit_id !== null
            && $user->hasPermission(PermissionName::EmployeesViewUnit->value)
            && $employee->user()->value('organizational_unit_id') === $user->organizational_unit_id;
    }

    /**
     * The PDS: your own always; anyone's only with `pds.view` (HR).
     * Supervisors' `employees.view_unit` does not reach it.
     */
    public function viewPds(User $user, Employee $employee): bool
    {
        return $employee->user_id === $user->id || $user->hasPermission(PermissionName::PdsView->value);
    }

    /**
     * Employees keep their own PDS current; HR (`pds.update`) can edit
     * someone else's on their behalf.
     */
    public function updatePds(User $user, Employee $employee): bool
    {
        return $employee->user_id === $user->id || $user->hasPermission(PermissionName::PdsUpdate->value);
    }

    /**
     * The 201 file's documents: your own always; anyone's with
     * `employee_documents.view` (HR). Never supervisors.
     */
    public function viewDocuments(User $user, Employee $employee): bool
    {
        return $employee->user_id === $user->id || $user->hasPermission(PermissionName::EmployeeDocumentsView->value);
    }

    /**
     * Uploading, archiving and restoring: HR, and never in their own 201
     * file (separation of duties).
     */
    public function manageDocuments(User $user, Employee $employee): bool
    {
        return $employee->user_id !== $user->id && $user->hasPermission(PermissionName::EmployeeDocumentsManage->value);
    }

    /**
     * HR-3b: someone's L&D log for HRD (`ld.view` or `ld.manage`). Your own
     * is "My Learning & Development"; supervisors never see it.
     */
    public function viewLd(User $user, Employee $employee): bool
    {
        return $employee->user_id === $user->id
            || $user->hasAnyPermission([PermissionName::LdView->value, PermissionName::LdManage->value]);
    }

    /**
     * Adding trainings on someone's behalf (approved at once): HRD, never
     * to their own log.
     */
    public function manageLd(User $user, Employee $employee): bool
    {
        return $employee->user_id !== $user->id && $user->hasPermission(PermissionName::LdManage->value);
    }

    /**
     * HR-1b: recording someone's reassignments, details and designations —
     * HR, never their own (separation of duties). Reading the history
     * follows view().
     */
    public function reassign(User $user, Employee $employee): bool
    {
        return $employee->user_id !== $user->id && $user->hasPermission(PermissionName::EmployeesReassign->value);
    }

    /**
     * HR-5: the Service Record — your own always; anyone's with
     * `service_records.view` (HR). Not supervisors: it shows salaries.
     */
    public function viewServiceRecord(User $user, Employee $employee): bool
    {
        return $employee->user_id === $user->id || $user->hasPermission(PermissionName::ServiceRecordsView->value);
    }

    /**
     * HR-6: someone's leave card — your own always; anyone's with
     * `leave.view` or `leave.manage` (HR).
     */
    public function viewLeave(User $user, Employee $employee): bool
    {
        return $employee->user_id === $user->id
            || $user->hasAnyPermission([PermissionName::LeaveView->value, PermissionName::LeaveManage->value]);
    }

    /**
     * Opening balances, adjustments and leave settings: HR, never on their
     * own card (separation of duties).
     */
    public function manageLeave(User $user, Employee $employee): bool
    {
        return $employee->user_id !== $user->id && $user->hasPermission(PermissionName::LeaveManage->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionName::EmployeesCreate->value);
    }

    /**
     * HR can't change their own employment record (status, separation, …)
     * — separation of duties, like UserPolicy's self-guards. Another HR
     * officer or a Super Administrator edits it.
     */
    public function update(User $user, Employee $employee): bool
    {
        return $employee->user_id !== $user->id
            && $user->hasPermission(PermissionName::EmployeesUpdate->value);
    }
}
