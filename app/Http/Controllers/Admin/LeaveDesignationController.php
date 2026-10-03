<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\LeaveDesignation;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-6b: officers-in-charge for the Head of Agency (legacy
 * hrd/designations.php) — while a designation runs, the designee can act
 * on the Head's step of leave applications.
 */
class LeaveDesignationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LeaveType::class);

        return Inertia::render('Admin/HR/Leave/Designations', [
            'designations' => LeaveDesignation::query()->with(['designee:id,name', 'actingFor:id,name'])->latest('date_from')->get()
                ->map(fn (LeaveDesignation $designation) => [
                    'id' => $designation->id,
                    'name' => $designation->designee->name,
                    'designee_id' => $designation->designee_id,
                    'acting_for' => $designation->actingFor?->name,
                    'acting_for_id' => $designation->acting_for_id,
                    'date_from' => $designation->date_from->toDateString(),
                    'date_to' => $designation->date_to->toDateString(),
                    'order_number' => $designation->order_number,
                    'remarks' => $designation->remarks,
                    'active' => $designation->date_from->lte(today()) && $designation->date_to->gte(today()),
                ]),
            'userOptions' => User::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name]),
            'canManage' => $request->user()->can('manage', LeaveType::class),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);

        $designation = new LeaveDesignation($this->validated($request));
        $designation->forceFill(['recorded_by' => $request->user()->id])->save();
        $this->audit()->record(AuditEvent::LeaveDesignationSaved, "Recorded the {$designation->label}", $designation);

        return back()->with('status', 'Designation recorded.');
    }

    public function update(Request $request, LeaveDesignation $designation): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);

        $designation->fill($this->validated($request))->save();
        $this->audit()->recordChanges(AuditEvent::LeaveDesignationSaved, "Updated the {$designation->label}", $designation);

        return back()->with('status', 'Designation saved.');
    }

    public function destroy(LeaveDesignation $designation): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);

        $designation->delete();
        $this->audit()->record(AuditEvent::LeaveDesignationDeleted, "Removed the {$designation->label}", null, [
            'designee_id' => $designation->designee_id, 'date_from' => $designation->date_from->toDateString(), 'date_to' => $designation->date_to->toDateString(),
        ]);

        return back()->with('status', 'Designation removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'designee_id' => ['required', 'integer', 'exists:users,id', 'different:acting_for_id'],
            'acting_for_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'order_number' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ], [], ['designee_id' => 'officer-in-charge', 'acting_for_id' => 'acting for']);
    }
}
