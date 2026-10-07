<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayslipRequest;
use App\Support\HR\Payroll\PayslipApprovers;
use App\Support\HR\Payroll\PayslipRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-8 self-service: the months whose payslip you can ask for, your
 * requests, and the approved payslips to view and print. Requests go to
 * the head of HRD.
 */
class MyPayslipController extends Controller
{
    public function index(Request $request, PayslipRequests $requests, PayslipApprovers $approvers): Response
    {
        $employee = $this->employee($request);

        return Inertia::render('Portal/MyPayslips', [
            'months' => $requests->months($employee),
            'requests' => PayslipRequest::query()->with(['employee.user', 'entry.run', 'decider', 'actingFor'])
                ->where('employee_id', $employee->id)->latest()->get()
                ->map(fn (PayslipRequest $item) => $requests->present($item)),
            'approvers' => $approvers->approvers()->reject(fn ($user) => $user->id === $request->user()->id)->pluck('name'),
        ]);
    }

    public function store(Request $request, PayslipRequests $requests): RedirectResponse
    {
        $employee = $this->employee($request);
        $data = $request->validate([
            'payroll_entry_id' => ['required', 'integer', 'exists:payroll_entries,id'],
            'purpose' => ['nullable', 'string', 'max:255'],
        ]);

        $item = $requests->request($employee, PayrollEntry::query()->with('run')->findOrFail($data['payroll_entry_id']), $data['purpose'] ?? null);
        $this->audit()->record(AuditEvent::PayslipRequested, "Requested {$item->label}", $item);

        return back()->with('status', "Request {$item->reference_number} was sent to the head of HRD.");
    }

    public function cancel(Request $request, PayslipRequest $payslipRequest, PayslipRequests $requests): RedirectResponse
    {
        abort_unless($payslipRequest->employee_id === $this->employee($request)->id, 403);

        $requests->cancel($payslipRequest);
        $this->audit()->record(AuditEvent::PayslipRequestCancelled, "Cancelled {$payslipRequest->label}", $payslipRequest);

        return back()->with('status', "Request {$payslipRequest->reference_number} was cancelled.");
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403, 'HR hasn\'t set up your employee record yet.');

        return $employee;
    }
}
