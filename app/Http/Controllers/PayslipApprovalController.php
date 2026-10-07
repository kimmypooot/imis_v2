<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Models\PayslipRequest;
use App\Support\HR\Payroll\PayslipApprovers;
use App\Support\HR\Payroll\PayslipRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-8: the head of HRD's queue of payslip requests (or whoever is
 * designated head of HRD), and deciding one. Nobody decides their own.
 */
class PayslipApprovalController extends Controller
{
    public function index(Request $request, PayslipRequests $requests, PayslipApprovers $approvers): Response
    {
        $user = $request->user();
        abort_unless($approvers->isApprover($user) || $user->hasRole(RoleName::SuperAdmin), 403);

        return Inertia::render('Portal/PayslipApprovals', [
            'requests' => $requests->queueFor($user)->map(fn (PayslipRequest $item) => $requests->present($item)),
        ]);
    }

    public function decide(Request $request, PayslipRequest $payslipRequest, PayslipRequests $requests): RedirectResponse
    {
        abort_unless($requests->canDecide($request->user(), $payslipRequest), 403);
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'remarks' => ['nullable', 'required_if:decision,rejected', 'string', 'max:1000'],
        ]);

        $requests->decide($payslipRequest, $request->user(), $data['decision'] === 'approved', $data['remarks'] ?? null);
        $this->audit()->record(AuditEvent::PayslipRequestDecided, ucfirst($data['decision'])." {$payslipRequest->label}", $payslipRequest, ['decision' => $data['decision']]);

        return back()->with('status', "{$payslipRequest->reference_number} was {$data['decision']}.");
    }
}
