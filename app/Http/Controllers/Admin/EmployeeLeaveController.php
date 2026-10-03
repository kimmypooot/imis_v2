<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\LedgerEntryKind;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\User;
use App\Support\HR\Leave\LeaveCredits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-6a: one person's leave card for HR — balances, the year's ledger
 * with running balances, opening balances and adjustments, reversals,
 * and their leave settings (immediate supervisor, solo parent). Opening
 * someone else's card is audited.
 */
class EmployeeLeaveController extends Controller
{
    public function show(Request $request, Employee $employee, LeaveCredits $credits): Response
    {
        $this->authorize('viewLeave', $employee);
        $employee->load(['user', 'immediateSupervisor:id,name']);

        $year = $request->integer('year', (int) today()->year);
        $year = $year >= 1990 && $year <= 2100 ? $year : (int) today()->year;

        if ($employee->user_id !== $request->user()->id) {
            $this->audit()->record(AuditEvent::LeaveCardViewed, "Viewed the leave card of {$employee->user->name}", $employee, ['year' => $year]);
        }

        $canManage = $request->user()->can('manageLeave', $employee);

        return Inertia::render('Admin/HR/Employees/Leave', [
            'employee' => ['id' => $employee->id, 'name' => $employee->user->name, 'status' => $employee->employment_status?->label() ?? 'Not set'],
            ...$credits->cardPage($employee, $year),
            'settings' => [
                'immediate_supervisor_id' => $employee->immediate_supervisor_id,
                'supervisor' => $employee->immediateSupervisor?->name,
                'is_solo_parent' => $employee->is_solo_parent,
                // The ID number itself only reaches those who may edit it.
                'solo_parent_id_number' => $canManage ? $employee->solo_parent_id_number : null,
                'solo_parent_id_expiry' => $employee->solo_parent_id_expiry?->toDateString(),
                'solo_parent_valid' => $employee->hasValidSoloParentId(),
            ],
            'canManage' => $canManage,
            'manageOptions' => $canManage ? [
                'kindOptions' => LedgerEntryKind::manualOptions(),
                'supervisorOptions' => User::query()->whereKeyNot($employee->user_id)->where('status', 'active')->orderBy('name')->get(['id', 'name'])
                    ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name]),
            ] : null,
        ]);
    }

    public function store(Request $request, Employee $employee, LeaveCredits $credits): RedirectResponse
    {
        $this->authorize('manageLeave', $employee);

        $entry = $credits->postManual($employee, $request->validate($credits->manualRules(), [], ['leave_type_id' => 'leave type', 'entry_date' => 'date']), $request->user());

        $this->audit()->record(AuditEvent::LeaveCreditPosted, "Posted a {$entry->kind->label()} to the leave card of {$employee->user->name}", $entry, [
            'kind' => $entry->kind->value, 'type' => $entry->leaveType->code, 'amount' => (float) $entry->amount,
        ]);

        return back()->with('status', "{$entry->kind->label()} posted.");
    }

    public function reverse(Request $request, Employee $employee, LeaveLedgerEntry $entry, LeaveCredits $credits): RedirectResponse
    {
        $this->authorize('manageLeave', $employee);

        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $reversal = $credits->reverse($entry, $data['reason'], $request->user());

        $this->audit()->record(AuditEvent::LeaveCreditReversed, "Reversed a leave credit entry of {$employee->user->name}", $reversal, ['reversed_entry' => $entry->id, 'reason' => $data['reason']]);

        return back()->with('status', 'The entry was reversed.');
    }

    public function updateSettings(Request $request, Employee $employee, LeaveCredits $credits): RedirectResponse
    {
        $this->authorize('manageLeave', $employee);

        $changed = $credits->updateSettings($employee, $request->validate($credits->settingsRules(), [], [
            'immediate_supervisor_id' => 'immediate supervisor', 'solo_parent_id_number' => 'Solo Parent ID number', 'solo_parent_id_expiry' => 'ID expiry',
        ]));

        if ($changed !== []) {
            // Field names only — never the ID number.
            $this->audit()->record(AuditEvent::LeaveSettingsUpdated, "Updated the leave settings of {$employee->user->name}", $employee, ['fields' => $changed]);
        }

        return back()->with('status', 'Leave settings saved.');
    }
}
