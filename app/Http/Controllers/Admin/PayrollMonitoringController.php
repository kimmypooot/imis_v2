<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PayslipRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\EmployeeLoan;
use App\Models\PayrollRun;
use App\Models\PayslipRequest;
use App\Support\HR\Payroll\Loans;
use App\Support\HR\Payroll\PayslipRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-8 monitoring: every loan with what's paid and what's left, and every
 * payslip request with where it stands. Read-only: loans are edited on
 * pay profiles, and requests are decided by the head of HRD.
 */
class PayrollMonitoringController extends Controller
{
    public function loans(Request $request, Loans $loans): Response
    {
        $this->authorize('viewAny', PayrollRun::class);

        return Inertia::render('Admin/HR/Payroll/Loans', [
            'loans' => $loans->presentMany(EmployeeLoan::query()->with(['employee.user', 'component'])->orderByDesc('start_month')->get())
                ->sortBy('employee', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'nearlyPaidMonths' => Loans::NEARLY_PAID_MONTHS,
        ]);
    }

    public function requests(Request $request, PayslipRequests $requests): Response
    {
        $this->authorize('viewAny', PayrollRun::class);
        $status = $request->validate(['status' => ['nullable', Rule::enum(PayslipRequestStatus::class)]])['status'] ?? 'pending';

        return Inertia::render('Admin/HR/Payroll/Requests', [
            'status' => $status,
            'requests' => PayslipRequest::query()->with(['employee.user', 'entry.run', 'decider', 'actingFor'])
                ->where('status', $status)->latest()->get()
                ->map(fn (PayslipRequest $item) => $requests->present($item)),
            'counts' => PayslipRequest::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }
}
