<?php

namespace App\Support\HR;

use App\Enums\DataCorrectionStatus;
use App\Enums\LdApprovalStatus;
use App\Enums\LeaveApplicationStatus;
use App\Enums\PayrollRunStatus;
use App\Enums\PayslipRequestStatus;
use App\Enums\PdsSubmissionStatus;
use App\Enums\PermissionName;
use App\Enums\SalnStatus;
use App\Models\DataCorrectionRequest;
use App\Models\LeaveApplication;
use App\Models\PayrollRun;
use App\Models\PayslipRequest;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\Pds\PdsSubmission;
use App\Models\Saln;
use App\Models\User;
use App\Support\HR\Payroll\PayrollAccounting;
use Illuminate\Support\Facades\Cache;

/**
 * How many items wait on HR staff, for the counts beside the HR menu
 * entries (the shared `badges` prop). A count is given only to someone who
 * may open the queue behind it, and is cached for a minute because it is
 * read on every page.
 */
final class HrQueues
{
    /**
     * @return array{pdsVerifications: int, ldReviews: int, leaveApplications: int, dataCorrections: int, payslipRequests: int, payrolls: int, payrollTaxReviews: int, salnReviews: int}
     */
    public static function countsFor(User $user): array
    {
        return Cache::remember("hr-queues:{$user->id}", 60, fn () => [
            'pdsVerifications' => self::when($user, PermissionName::PdsVerify, fn () => PdsSubmission::query()->where('status', PdsSubmissionStatus::Submitted)->count()),
            'ldReviews' => self::when($user, PermissionName::LdApprove, fn () => PdsLearningDevelopment::query()->where('approval_status', LdApprovalStatus::Pending)->count()),
            'leaveApplications' => self::when($user, PermissionName::LeaveManage, fn () => LeaveApplication::query()->where('status', LeaveApplicationStatus::ForReview)->count()),
            'dataCorrections' => self::when($user, PermissionName::DataCorrectionsReview, fn () => DataCorrectionRequest::query()->where('status', DataCorrectionStatus::Pending)->count()),
            'payslipRequests' => self::when($user, PermissionName::PayrollManage, fn () => PayslipRequest::query()->where('status', PayslipRequestStatus::Pending)->count()),
            // Payrolls back from Accounting, waiting for HRD to finalize.
            'payrolls' => self::when($user, PermissionName::PayrollManage, fn () => PayrollRun::query()->where('status', PayrollRunStatus::Returned)->count()),
            // Payrolls waiting for Accounting's withholding tax (the Accountant III, or `payroll.tax`).
            'payrollTaxReviews' => app(PayrollAccounting::class)->isReviewer($user)
                ? PayrollRun::query()->where('status', PayrollRunStatus::WithAccounting)->count() : 0,
            // SALN hard copies HRD received, for the SALN Committee (or HRD) to review; never one's own.
            'salnReviews' => $user->hasAnyPermission([PermissionName::SalnReview->value, PermissionName::SalnManage->value])
                ? Saln::query()->where('status', SalnStatus::Received)->whereDoesntHave('employee', fn ($query) => $query->where('user_id', $user->id))->count() : 0,
        ]);
    }

    /**
     * Drops these people's cached counts, so a hand-off shows on their
     * menu at once.
     *
     * @param  iterable<int, User>  $users
     */
    public static function forget(iterable $users): void
    {
        foreach ($users as $user) {
            Cache::forget("hr-queues:{$user->id}");
        }
    }

    /**
     * @param  callable(): int  $count
     */
    private static function when(User $user, PermissionName $permission, callable $count): int
    {
        return $user->hasPermission($permission->value) ? $count() : 0;
    }
}
