<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\ApprovalDelegation;
use App\Models\User;
use App\Support\HR\Leave\LeaveApplications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Out-of-office delegation: an approver hands their supervisor step on
 * leave applications to a colleague for a period, and can end it early.
 * Only the person delegating can create or end their own delegation.
 */
class LeaveDelegationController extends Controller
{
    public function store(Request $request, LeaveApplications $applications): RedirectResponse
    {
        $user = $request->user();
        abort_unless($applications->isApprover($user), 403);

        $data = $request->validate([
            'delegate_id' => ['required', 'integer', Rule::notIn([$user->id]), function (string $attribute, mixed $value, \Closure $fail) {
                if (! User::query()->whereKey($value)->where('status', 'active')->exists()) {
                    $fail('Choose an active user.');
                }
            }],
            'date_from' => ['required', 'date', 'after_or_equal:today'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'reason' => ['nullable', 'string', 'max:200'],
        ], [], ['delegate_id' => 'person']);

        $overlaps = ApprovalDelegation::query()->currentOrUpcoming()->where('delegator_id', $user->id)
            ->whereDate('date_from', '<=', $data['date_to'])->whereDate('date_to', '>=', $data['date_from'])->exists();

        if ($overlaps) {
            return back()->withErrors(['date_from' => 'You already have a delegation for some of these dates. End it first.']);
        }

        $delegation = ApprovalDelegation::query()->create([...$data, 'delegator_id' => $user->id]);
        $delegation->load('delegate:id,name');

        $this->audit()->record(
            AuditEvent::ApprovalDelegationCreated,
            "Delegated leave approvals to {$delegation->delegate->name}",
            $delegation,
            ['date_from' => $data['date_from'], 'date_to' => $data['date_to']],
        );

        return back()->with('status', "{$delegation->delegate->name} can act on your leave approvals from {$delegation->date_from->format('M j')} to {$delegation->date_to->format('M j')}.");
    }

    public function destroy(Request $request, ApprovalDelegation $delegation): RedirectResponse
    {
        abort_unless($delegation->delegator_id === $request->user()->id, 403);

        if ($delegation->ended_at === null) {
            $delegation->forceFill(['ended_at' => now()])->save();
            $this->audit()->record(AuditEvent::ApprovalDelegationEnded, 'Ended a delegation of leave approvals', $delegation);
        }

        return back()->with('status', 'Delegation ended.');
    }
}
