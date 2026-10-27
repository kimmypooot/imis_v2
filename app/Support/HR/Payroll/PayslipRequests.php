<?php

namespace App\Support\HR\Payroll;

use App\Enums\PayrollRunStatus;
use App\Enums\PayslipRequestStatus;
use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayslipRequest;
use App\Models\User;
use App\Notifications\HrUpdate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * HR-8: the single writer for payslip requests. An employee asks for the
 * payslip of a month whose payroll is finalized; the head of HRD (see
 * PayslipApprovers) approves or rejects it, and both sides are told under
 * the bell. An approved request lets the employee view and print that
 * payslip. One open (pending or approved) request per month; a rejected
 * or cancelled one can be asked again.
 */
class PayslipRequests
{
    public function __construct(private readonly PayslipApprovers $approvers) {}

    /**
     * The months the employee can ask for (finalized payrolls they're
     * on), newest first, with where each request stands.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function months(Employee $employee): Collection
    {
        $requests = PayslipRequest::query()->where('employee_id', $employee->id)
            ->whereIn('status', [PayslipRequestStatus::Pending, PayslipRequestStatus::Approved])
            ->get()->keyBy('payroll_entry_id');

        return PayrollEntry::query()->with('run')->where('employee_id', $employee->id)->whereNull('cancelled_at')
            ->whereHas('run', fn ($query) => $query->where('status', PayrollRunStatus::Finalized->value))
            ->get()
            ->sortByDesc(fn (PayrollEntry $entry) => $entry->run->month())
            ->map(fn (PayrollEntry $entry) => [
                'entry_id' => $entry->id,
                'period' => $entry->run->period,
                'request_status' => $requests->get($entry->id)?->status->value,
                'request_id' => $requests->get($entry->id)?->id,
            ])->values();
    }

    public function request(Employee $employee, PayrollEntry $entry, ?string $purpose): PayslipRequest
    {
        if ($entry->employee_id !== $employee->id || ! $entry->run->isFinalized() || $entry->isCancelled()) {
            throw ValidationException::withMessages(['payroll_entry_id' => 'Pick a month whose payroll is finalized.']);
        }
        if (PayslipRequest::query()->where('payroll_entry_id', $entry->id)
            ->whereIn('status', [PayslipRequestStatus::Pending, PayslipRequestStatus::Approved])->exists()) {
            throw ValidationException::withMessages(['payroll_entry_id' => "You already have a request for {$entry->run->period}."]);
        }

        $request = DB::transaction(function () use ($employee, $entry, $purpose) {
            $request = PayslipRequest::query()->create([
                'employee_id' => $employee->id,
                'payroll_entry_id' => $entry->id,
                'status' => PayslipRequestStatus::Pending,
                'purpose' => $purpose,
            ]);
            $request->forceFill(['reference_number' => sprintf('PS-%d-%05d', today()->year, $request->id)])->save();

            return $request;
        });

        $approvers = $this->approvers->approvers()->reject(fn (User $user) => $user->id === $employee->user_id);
        Notification::send($approvers, new HrUpdate(
            'Payslip request for your approval',
            "{$employee->user->name} asks for the payslip for {$entry->run->period} ({$request->reference_number}).",
            '/payslips/approvals',
        ));

        return $request;
    }

    public function cancel(PayslipRequest $request): void
    {
        if ($request->status !== PayslipRequestStatus::Pending) {
            throw ValidationException::withMessages(['request' => 'Only a pending request can be cancelled.']);
        }

        $request->forceFill(['status' => PayslipRequestStatus::Cancelled])->save();
    }

    public function canDecide(User $user, PayslipRequest $request): bool
    {
        return $request->status === PayslipRequestStatus::Pending
            && $request->employee->user_id !== $user->id
            && ($this->approvers->isApprover($user) || $user->hasRole(RoleName::SuperAdmin));
    }

    public function decide(PayslipRequest $request, User $by, bool $approve, ?string $remarks): void
    {
        if (! $this->canDecide($by, $request)) {
            throw ValidationException::withMessages(['request' => 'This request is not yours to decide.']);
        }

        $request->forceFill([
            'status' => $approve ? PayslipRequestStatus::Approved : PayslipRequestStatus::Rejected,
            'decided_by' => $by->id,
            'acting_for_id' => $this->approvers->actingFor($by),
            'decided_at' => now(),
            'remarks' => $remarks,
        ])->save();

        $period = $request->entry->run->period;
        $request->employee->user->notify(new HrUpdate(
            $approve ? 'Payslip request approved' : 'Payslip request rejected',
            $approve
                ? "Your payslip for {$period} ({$request->reference_number}) is ready to view and print."
                : "Your request for the {$period} payslip ({$request->reference_number}) was rejected.".($remarks ? " Remarks: {$remarks}" : ''),
            '/my/payslips',
        ));
    }

    /**
     * Pending requests the user may decide.
     *
     * @return Collection<int, PayslipRequest>
     */
    public function queueFor(User $user): Collection
    {
        if (! $this->approvers->isApprover($user) && ! $user->hasRole(RoleName::SuperAdmin)) {
            return collect();
        }

        return PayslipRequest::query()->with(['employee.user', 'entry.run'])
            ->where('status', PayslipRequestStatus::Pending)
            ->whereHas('employee', fn ($query) => $query->where('user_id', '!=', $user->id))
            ->oldest()->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PayslipRequest $request): array
    {
        return [
            'id' => $request->id,
            'reference_number' => $request->reference_number,
            'employee' => $request->employee->user->name,
            'position' => $request->entry->position,
            'office' => $request->entry->office,
            'entry_id' => $request->payroll_entry_id,
            'period' => $request->entry->run->period,
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'purpose' => $request->purpose,
            'requested_at' => $request->created_at?->toIso8601String(),
            'decided_by' => $request->decider?->name,
            'acting_for' => $request->actingFor?->name,
            'decided_at' => $request->decided_at?->toIso8601String(),
            'remarks' => $request->remarks,
        ];
    }
}
