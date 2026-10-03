<?php

namespace App\Support\HR\Leave;

use App\Enums\LeaveApplicationStatus;
use App\Enums\LedgerEntryKind;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * HR-6c: the legacy LMS reports (api/admin/reports.php) that aren't
 * already a page of their own — the balance summary is Leave Credits and
 * the applications register is Leave Applications.
 */
class LeaveReports
{
    public const REPORTS = [
        'on_leave' => 'On leave on a day',
        'utilization' => 'Leave utilization by type',
        'forced_leave' => 'Forced leave compliance',
        'without_pay' => 'Leave without pay',
        'adjustments' => 'Manual credit entries (audit)',
    ];

    /** Forced leave: whoever has this much VL must take 5 days a year. */
    public const FORCED_LEAVE_VL_THRESHOLD = 10.0;

    public const FORCED_LEAVE_DAYS = 5.0;

    public function __construct(private readonly LeaveCredits $credits) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function onLeave(Carbon $day): Collection
    {
        return $this->approved()
            ->whereDate('date_from', '<=', $day)->whereDate('date_to', '>=', $day)
            ->get()
            ->map(fn (LeaveApplication $application) => [
                'id' => $application->id,
                'reference_number' => $application->reference_number,
                'name' => $application->employee->user->name,
                'office' => $application->employee->user->organizationalUnit?->short_name ?: $application->employee->user->organizationalUnit?->name,
                'type' => $application->leaveType->name,
                'date_from' => $application->date_from->toDateString(),
                'date_to' => $application->date_to->toDateString(),
                'days' => (float) $application->days,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * Applications filed for the year, per type: how many in each status,
     * and the days approved.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function utilization(int $year): Collection
    {
        $applications = LeaveApplication::query()->whereYear('date_from', $year)->get(['leave_type_id', 'status', 'days', 'days_with_pay', 'days_without_pay']);

        return LeaveType::query()->ordered()->get()->map(function (LeaveType $type) use ($applications) {
            $own = $applications->where('leave_type_id', $type->id);
            $approved = $own->where('status', LeaveApplicationStatus::Approved);

            return [
                'code' => $type->code,
                'type' => $type->name,
                'filed' => $own->count(),
                'approved' => $approved->count(),
                'disapproved' => $own->where('status', LeaveApplicationStatus::Disapproved)->count(),
                'pending' => $own->filter(fn ($application) => $application->status->isOpen())->count(),
                'days_with_pay' => round((float) $approved->sum('days_with_pay'), 3),
                'days_without_pay' => round((float) $approved->sum('days_without_pay'), 3),
            ];
        })->filter(fn (array $row) => $row['filed'] > 0)->values();
    }

    /**
     * Everyone in service with at least 10 days of VL who hasn't taken 5
     * days of forced or vacation leave in the year (legacy
     * forced_leave_compliance).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forcedLeave(int $year): Collection
    {
        $vl = LeaveType::query()->where('code', 'VL')->first();
        if (! $vl) {
            return collect();
        }

        $codes = LeaveType::query()->whereIn('code', ['VL', 'FL'])->pluck('id');
        $taken = LeaveApplication::query()->where('status', LeaveApplicationStatus::Approved)->whereIn('leave_type_id', $codes)->whereYear('date_from', $year)
            ->get(['employee_id', 'days_with_pay'])->groupBy('employee_id')->map(fn ($rows) => (float) $rows->sum('days_with_pay'));

        return Employee::query()->with('user.organizationalUnit')->get()
            ->reject(fn (Employee $employee) => $employee->isSeparated())
            ->map(fn (Employee $employee) => [
                'employee_id' => $employee->id,
                'name' => $employee->user->name,
                'office' => $employee->user->organizationalUnit?->short_name ?: $employee->user->organizationalUnit?->name,
                'vl_balance' => $this->credits->balance($employee, $vl),
                'taken' => round($taken[$employee->id] ?? 0, 3),
            ])
            ->filter(fn (array $row) => $row['vl_balance'] >= self::FORCED_LEAVE_VL_THRESHOLD && $row['taken'] < self::FORCED_LEAVE_DAYS)
            ->map(fn (array $row) => [...$row, 'short' => round(self::FORCED_LEAVE_DAYS - $row['taken'], 3)])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function withoutPay(int $year): Collection
    {
        return $this->approved()->where('days_without_pay', '>', 0)->whereYear('date_from', $year)->get()
            ->map(fn (LeaveApplication $application) => [
                'id' => $application->id,
                'reference_number' => $application->reference_number,
                'name' => $application->employee->user->name,
                'type' => $application->leaveType->name,
                'date_from' => $application->date_from->toDateString(),
                'date_to' => $application->date_to->toDateString(),
                'days_without_pay' => (float) $application->days_without_pay,
            ])
            ->sortBy('date_from')->values();
    }

    /**
     * Opening balances and adjustments posted by hand in the year.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function adjustments(int $year): Collection
    {
        return LeaveLedgerEntry::query()
            ->whereIn('kind', [LedgerEntryKind::Opening, LedgerEntryKind::Adjustment])
            ->whereYear('created_at', $year)
            ->with(['employee.user', 'leaveType', 'recorder:id,name'])
            ->latest()->get()
            ->map(fn (LeaveLedgerEntry $entry) => [
                'id' => $entry->id,
                'posted_at' => $entry->created_at->toIso8601String(),
                'name' => $entry->employee->user->name,
                'code' => $entry->leaveType->code,
                'kind' => $entry->kind->label(),
                'entry_date' => $entry->entry_date->toDateString(),
                'amount' => (float) $entry->amount,
                'particulars' => $entry->particulars,
                'recorded_by' => $entry->recorder?->name,
            ]);
    }

    private function approved()
    {
        return LeaveApplication::query()->where('status', LeaveApplicationStatus::Approved)
            ->with(['employee.user.organizationalUnit', 'leaveType']);
    }
}
