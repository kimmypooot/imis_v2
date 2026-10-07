<?php

namespace App\Support\Executive\Sources;

use App\Enums\LeaveApplicationStatus;
use App\Enums\PermissionName;
use App\Models\LeaveApplication;

/**
 * Leave (HR-6): who is on approved leave today, what is waiting in the
 * approval chain, and leave days approved this month. Starter figures.
 */
class LeaveSource implements MetricSource
{
    public function key(): string
    {
        return 'leave';
    }

    public function label(): string
    {
        return 'Leave';
    }

    public function kind(): string
    {
        return 'module';
    }

    public function collect(): array
    {
        $today = today();
        $onLeave = LeaveApplication::query()->with('employee.user.organizationalUnit')
            ->where('status', LeaveApplicationStatus::Approved)
            ->whereDate('date_from', '<=', $today)->whereDate('date_to', '>=', $today)
            ->get();
        $approvedThisMonth = LeaveApplication::query()
            ->where('status', LeaveApplicationStatus::Approved)
            ->whereYear('date_from', $today->year)->whereMonth('date_from', $today->month);

        return [
            [
                'key' => 'leave.on_leave_today',
                'label' => 'On leave today',
                'value' => $onLeave->count(),
                'unit' => 'count',
                'breakdown' => Staff::byOffice($onLeave, fn (LeaveApplication $application) => Staff::office($application->employee)),
                'href' => '/admin/hr/leave/calendar',
                'permission' => PermissionName::LeaveView->value,
            ],
            [
                'key' => 'leave.in_review',
                'label' => 'Leave applications awaiting action',
                'value' => LeaveApplication::query()->where('status', LeaveApplicationStatus::ForReview)->count(),
                'unit' => 'count',
                'href' => '/admin/hr/leave/applications',
                'permission' => PermissionName::LeaveView->value,
            ],
            [
                'key' => 'leave.days_approved_month',
                'label' => 'Leave days approved this month',
                'value' => (float) (clone $approvedThisMonth)->sum('days'),
                'unit' => 'days',
                'period' => $today->format('F Y'),
                'href' => '/admin/hr/leave/reports',
                'permission' => PermissionName::LeaveView->value,
            ],
        ];
    }
}
