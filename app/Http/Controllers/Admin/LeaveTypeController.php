<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\EmploymentStatus;
use App\Enums\Sex;
use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-6a: leave types (CS Form No. 6) with their policy as data — HR edits
 * the entitlements and rules; nothing about a type is hard-coded.
 */
class LeaveTypeController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LeaveType::class);

        return Inertia::render('Admin/HR/Leave/Types', [
            'types' => LeaveType::query()->with('chargedTo')->ordered()->get()->map(fn (LeaveType $type) => [
                ...$type->only($type->getFillable()),
                'id' => $type->id,
                'charged_to' => $type->chargedTo?->code,
                'sex_label' => Sex::labelFor($type->sex),
                'has_credits' => $type->hasCredits(),
            ]),
            'canManage' => $request->user()->can('manage', LeaveType::class),
            'statusOptions' => EmploymentStatus::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);

        $type = LeaveType::query()->create($this->validated($request));
        $this->audit()->record(AuditEvent::LeaveTypeSaved, "Created {$type->label}", $type);

        return back()->with('status', "{$type->name} was added.");
    }

    public function update(Request $request, LeaveType $type): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);

        $type->fill($this->validated($request, $type))->save();
        $this->audit()->recordChanges(AuditEvent::LeaveTypeSaved, "Updated {$type->label}", $type);

        return back()->with('status', "{$type->name} was saved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?LeaveType $type = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_]+$/', Rule::unique('leave_types', 'code')->ignore($type)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'workflow' => ['required', 'in:standard,special'],
            'requires_balance' => ['boolean'],
            'charged_to_id' => ['nullable', 'integer', 'exists:leave_types,id', Rule::notIn([$type?->id])],
            'monthly_accrual' => ['nullable', 'numeric', 'between:0,31'],
            'annual_grant' => ['nullable', 'numeric', 'between:0,366'],
            'lapses_yearly' => ['boolean'],
            'max_days_per_year' => ['nullable', 'numeric', 'between:0,366'],
            'max_days_per_filing' => ['nullable', 'numeric', 'between:0,366'],
            'max_consecutive_days' => ['nullable', 'integer', 'between:1,366'],
            'sex' => ['nullable', 'in:male,female'],
            'eligible_statuses' => ['nullable', 'array'],
            'eligible_statuses.*' => [Rule::enum(EmploymentStatus::class)],
            'min_service_months' => ['nullable', 'integer', 'between:0,600'],
            'requires_solo_parent_id' => ['boolean'],
            'advance_filing_days' => ['nullable', 'integer', 'between:0,365'],
            'allows_emergency_filing' => ['boolean'],
            'allows_half_day' => ['boolean'],
            'counts_calendar_days' => ['boolean'],
            'medical_certificate_after_days' => ['nullable', 'integer', 'between:0,365'],
            'supporting_documents' => ['nullable', 'string', 'max:500'],
            'monetizable' => ['boolean'],
            'policy_reference' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'between:0,999'],
            'is_active' => ['boolean'],
        ], [], ['charged_to_id' => 'charged to', 'eligible_statuses' => 'eligible statuses']);

        return [
            ...$data,
            'code' => strtoupper($data['code']),
            'eligible_statuses' => ! empty($data['eligible_statuses']) ? array_values($data['eligible_statuses']) : null,
            'min_service_months' => (int) ($data['min_service_months'] ?? 0),
            'advance_filing_days' => (int) ($data['advance_filing_days'] ?? 0),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
