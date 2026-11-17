<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\ApprovalDelegation;
use App\Models\LeaveApplicationStep;
use App\Models\User;
use App\Support\HR\Leave\LeaveApplications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-6b: Leave Approvals (legacy supervisor-queue.php and HR's
 * applications page) — the applications waiting for this person:
 * supervisors (Part B), HR (`leave.certify`, Part A), and the Head of
 * Agency or an officer-in-charge (`leave.approve`, Parts C/D) — and
 * acting on one. Who may act is decided per step by
 * LeaveApplications::authority(); nobody acts on their own.
 */
class LeaveApprovalController extends Controller
{
    public function index(Request $request, LeaveApplications $applications): Response
    {
        $user = $request->user();

        return Inertia::render('Portal/LeaveApprovals', [
            'queue' => $applications->queueFor($user)->map(fn (LeaveApplicationStep $step) => [
                ...$applications->present($step->application),
                'step_role' => $step->role,
                'step_label' => $step->roleLabel(),
                'waiting_since' => $step->created_at->toIso8601String(),
            ]),
            // Out-of-office: who I handed my supervisor step to, and who handed theirs to me.
            'delegations' => ApprovalDelegation::query()->currentOrUpcoming()->where('delegator_id', $user->id)->with('delegate:id,name')
                ->orderBy('date_from')->get()->map(fn (ApprovalDelegation $delegation) => [
                    'id' => $delegation->id,
                    'delegate' => $delegation->delegate->name,
                    'date_from' => $delegation->date_from->toDateString(),
                    'date_to' => $delegation->date_to->toDateString(),
                    'reason' => $delegation->reason,
                    'active' => $delegation->date_from->lte(today()),
                ]),
            'delegatedToMe' => ApprovalDelegation::query()->activeOn()->where('delegate_id', $user->id)->with('delegator:id,name')->get()
                ->map(fn (ApprovalDelegation $delegation) => ['delegator' => $delegation->delegator->name, 'date_to' => $delegation->date_to->toDateString()]),
            'userOptions' => User::query()->where('status', 'active')->whereKeyNot($user->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $person) => ['value' => $person->id, 'label' => $person->name]),
            'recent' => LeaveApplicationStep::query()->where('acted_by', $user->id)->with('application.leaveType', 'application.employee.user')
                ->latest('acted_at')->limit(15)->get()
                ->map(fn (LeaveApplicationStep $step) => [
                    ...$applications->present($step->application),
                    'my_action' => $step->action->label(),
                    'acted_at' => $step->acted_at?->toIso8601String(),
                ]),
        ]);
    }

    public function act(Request $request, LeaveApplicationStep $step, LeaveApplications $applications): RedirectResponse
    {
        abort_unless($applications->authority($request->user(), $step)['can'], 403);

        $application = $applications->act($step, $request->user(), $request->validate($applications->actionRules($step), [], ['days_with_pay' => 'days with pay']));

        $this->audit()->record(AuditEvent::LeaveApplicationActed, "{$step->fresh()->action->label()}: {$application->label}", $application, array_filter([
            'step' => $step->role,
            'action' => $request->input('action'),
            'acting_for' => $step->fresh()->acted_for,
        ]));

        return to_route('leave.approvals')->with('status', "{$application->reference_number}: {$step->fresh()->action->label()}.");
    }
}
