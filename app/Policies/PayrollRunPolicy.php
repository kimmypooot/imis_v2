<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\PayslipRequest;
use App\Models\User;

/**
 * HR-8 payroll: payrolls, pay components, pay profiles, loans, payslips.
 * `payroll.manage` also reads. Super Administrators pass every check
 * through Gate::before.
 */
class PayrollRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([PermissionName::PayrollView->value, PermissionName::PayrollManage->value]);
    }

    public function view(User $user, PayrollRun $run): bool
    {
        return $this->viewAny($user);
    }

    /** Pay components, and creating, generating, finalizing or reopening payrolls. */
    public function manage(User $user): bool
    {
        return $user->hasPermission(PermissionName::PayrollManage->value);
    }

    /**
     * Someone's pay profile and loans, or adjusting their payroll entry:
     * never your own (separation of duties).
     */
    public function managePayOf(User $user, Employee $employee): bool
    {
        return $employee->user_id !== $user->id && $this->manage($user);
    }

    /**
     * A payslip: payroll staff see anyone's; the employee sees their own
     * once a request for it is approved.
     */
    public function viewPayslip(User $user, PayrollEntry $entry): bool
    {
        if ($this->viewAny($user)) {
            return $entry->run->isFinalized() || $user->hasPermission(PermissionName::PayrollManage->value);
        }

        return $entry->employee->user_id === $user->id && $entry->run->isFinalized() && ! $entry->isCancelled()
            && PayslipRequest::query()->where('payroll_entry_id', $entry->id)->where('status', 'approved')->exists();
    }
}
