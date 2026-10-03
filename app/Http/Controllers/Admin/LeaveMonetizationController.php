<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveMonetization;
use App\Models\LeaveType;
use App\Notifications\HrUpdate;
use App\Support\HR\Leave\LeaveMonetizations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-6c: monetization and terminal leave for HR (legacy
 * hrd/monetization.php) — requests by status; approving (the credits come
 * off), rejecting, marking paid; and filing terminal leave for someone
 * separated. Never on their own request.
 */
class LeaveMonetizationController extends Controller
{
    public function index(Request $request, LeaveMonetizations $monetizations): Response
    {
        $this->authorize('viewAny', LeaveType::class);

        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'released', 'cancelled'])]])['status'] ?? 'pending';
        $canManage = $request->user()->can('manage', LeaveType::class);

        return Inertia::render('Admin/HR/Leave/Monetization', [
            'status' => $status,
            'requests' => LeaveMonetization::query()->where('status', $status)->latest()->get()
                ->map(fn (LeaveMonetization $item) => [
                    ...$monetizations->present($item),
                    'own' => $item->employee->user_id === $request->user()->id,
                ]),
            'counts' => LeaveMonetization::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'canManage' => $canManage,
            'separated' => $canManage ? Employee::query()->with('user')->whereNotNull('separation_date')->get()
                ->map(fn (Employee $employee) => ['value' => $employee->id, 'label' => $employee->user->name.' (separated '.$employee->separation_date->toDateString().')'])
                ->sortBy('label')->values() : [],
        ]);
    }

    public function terminal(Request $request, LeaveMonetizations $monetizations): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);
        $data = $request->validate(['employee_id' => ['required', 'integer', 'exists:employees,id'], 'remarks' => ['nullable', 'string', 'max:1000']]);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        abort_if($employee->user_id === $request->user()->id, 403);

        $item = $monetizations->requestTerminal($employee, $data['remarks'] ?? null, $request->user());
        $this->audit()->record(AuditEvent::LeaveMonetizationRequested, "Filed {$item->label}", $item, ['vl' => (float) $item->vl_days, 'sl' => (float) $item->sl_days]);

        return back()->with('status', "Terminal leave {$item->reference_number} was filed.");
    }

    public function decide(Request $request, LeaveMonetization $monetization, LeaveMonetizations $monetizations): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);
        abort_if($monetization->employee->user_id === $request->user()->id, 403);

        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'remarks' => ['nullable', 'required_if:decision,rejected', 'string', 'max:1000']]);

        $data['decision'] === 'approved'
            ? $monetizations->approve($monetization, $request->user(), $data['remarks'] ?? null)
            : $monetizations->reject($monetization, $request->user(), $data['remarks']);

        $this->audit()->record(AuditEvent::LeaveMonetizationDecided, ucfirst($data['decision'])." {$monetization->label}", $monetization, ['decision' => $data['decision']]);
        $monetization->employee->user->notify(new HrUpdate(
            "Leave monetization {$data['decision']}",
            "Your request {$monetization->reference_number} was {$data['decision']}.".(! empty($data['remarks']) ? " Remarks: {$data['remarks']}" : ''),
            '/my/leave/monetization',
        ));

        return back()->with('status', "{$monetization->reference_number} was {$data['decision']}.");
    }

    public function release(Request $request, LeaveMonetization $monetization, LeaveMonetizations $monetizations): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);

        $monetizations->markReleased($monetization);
        $this->audit()->record(AuditEvent::LeaveMonetizationReleased, "Marked {$monetization->label} released", $monetization);

        return back()->with('status', "{$monetization->reference_number} was marked released.");
    }
}
