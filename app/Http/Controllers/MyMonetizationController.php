<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\Employee;
use App\Models\LeaveMonetization;
use App\Support\HR\Leave\LeaveMonetizations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-6c self-service: monetizing Vacation Leave (legacy
 * leave/monetization.php) — what you could convert, at what rate, your
 * requests, and filing or cancelling one.
 */
class MyMonetizationController extends Controller
{
    public function show(Request $request, LeaveMonetizations $monetizations): Response
    {
        $employee = $this->employee($request);

        return Inertia::render('Portal/MyMonetization', [
            'terms' => $monetizations->partialTerms($employee),
            'rules' => ['share' => LeaveMonetizations::MAX_SHARE_OF_VL * 100, 'keep' => LeaveMonetizations::DAYS_TO_KEEP, 'divisor' => LeaveMonetizations::WORKING_DAYS_A_MONTH],
            'requests' => LeaveMonetization::query()->where('employee_id', $employee->id)->latest()->get()
                ->map(fn (LeaveMonetization $item) => $monetizations->present($item)),
        ]);
    }

    public function store(Request $request, LeaveMonetizations $monetizations): RedirectResponse
    {
        $employee = $this->employee($request);
        $data = $request->validate(['days' => ['required', 'numeric', 'gt:0', 'max:999'], 'remarks' => ['nullable', 'string', 'max:1000']]);

        $item = $monetizations->requestPartial($employee, (float) $data['days'], $data['remarks'] ?? null, $request->user());
        $this->audit()->record(AuditEvent::LeaveMonetizationRequested, "Requested {$item->label}", $item, ['days' => (float) $item->vl_days]);

        return back()->with('status', "Request {$item->reference_number} was filed with HR.");
    }

    public function cancel(Request $request, LeaveMonetization $monetization, LeaveMonetizations $monetizations): RedirectResponse
    {
        abort_unless($monetization->employee_id === $this->employee($request)->id, 403);

        $monetizations->cancel($monetization);
        $this->audit()->record(AuditEvent::LeaveMonetizationCancelled, "Cancelled {$monetization->label}", $monetization);

        return back()->with('status', "Request {$monetization->reference_number} was cancelled.");
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403, 'HR hasn\'t set up your employee record yet.');

        return $employee;
    }
}
