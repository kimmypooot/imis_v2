<?php

namespace App\Support\HR;

use App\Enums\LdApprovalStatus;
use App\Enums\LeaveApplicationStatus;
use App\Enums\PayslipRequestStatus;
use App\Enums\PdsSubmissionStatus;
use App\Enums\PermissionName;
use App\Models\LeaveApplication;
use App\Models\PayslipRequest;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\Pds\PdsSubmission;
use App\Models\User;
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
     * @return array{pdsVerifications: int, ldReviews: int, leaveApplications: int, payslipRequests: int}
     */
    public static function countsFor(User $user): array
    {
        return Cache::remember("hr-queues:{$user->id}", 60, fn () => [
            'pdsVerifications' => self::when($user, PermissionName::PdsVerify, fn () => PdsSubmission::query()->where('status', PdsSubmissionStatus::Submitted)->count()),
            'ldReviews' => self::when($user, PermissionName::LdApprove, fn () => PdsLearningDevelopment::query()->where('approval_status', LdApprovalStatus::Pending)->count()),
            'leaveApplications' => self::when($user, PermissionName::LeaveManage, fn () => LeaveApplication::query()->where('status', LeaveApplicationStatus::ForReview)->count()),
            'payslipRequests' => self::when($user, PermissionName::PayrollManage, fn () => PayslipRequest::query()->where('status', PayslipRequestStatus::Pending)->count()),
        ]);
    }

    /**
     * @param  callable(): int  $count
     */
    private static function when(User $user, PermissionName $permission, callable $count): int
    {
        return $user->hasPermission($permission->value) ? $count() : 0;
    }
}
