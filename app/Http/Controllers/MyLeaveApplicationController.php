<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\LeaveDetail;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Support\HR\Leave\LeaveApplications;
use App\Support\HR\Leave\LeaveCredits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-6b self-service: filing leave on CS Form No. 6 (legacy leave/apply.php),
 * your applications (my-applications.php), and correcting a returned one.
 * The form previews the days and credits as you fill it (a partial
 * reload of `preview`).
 */
class MyLeaveApplicationController extends Controller
{
    public function index(Request $request, LeaveApplications $applications): Response
    {
        $employee = $this->employee($request);

        return Inertia::render('Portal/MyLeaveApplications', [
            'applications' => LeaveApplication::query()->where('employee_id', $employee->id)
                ->with(['leaveType', 'employee.user'])->latest('date_from')->latest('id')->get()
                ->map(fn (LeaveApplication $application) => $applications->present($application)),
        ]);
    }

    public function create(Request $request, LeaveApplications $applications, LeaveCredits $credits): Response
    {
        $employee = $this->employee($request);
        $returned = $request->filled('resubmit')
            ? LeaveApplication::query()->where('employee_id', $employee->id)->findOrFail($request->integer('resubmit'))
            : null;

        abort_if($returned && $request->user()->cannot('update', $returned), 403);

        return Inertia::render('Portal/LeaveApply', [
            'types' => LeaveType::query()->active()->ordered()->get()->map(fn (LeaveType $type) => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'allows_half_day' => $type->allows_half_day,
                'counts_calendar_days' => $type->counts_calendar_days,
                'advance_filing_days' => $type->advance_filing_days,
                'medical_certificate_after_days' => $type->medical_certificate_after_days,
                'supporting_documents' => $type->supporting_documents,
                'ineligible' => $credits->ineligibilityReasons($employee, $type),
            ]),
            'detailOptions' => LeaveDetail::optionsByCode(),
            'balances' => $credits->balances($employee),
            'supervisor' => $employee->immediateSupervisor?->name,
            'resubmitting' => $returned ? [
                ...$applications->present($returned),
                'returned_remarks' => $returned->steps()->where('action', 'returned')->latest('acted_at')->value('remarks'),
            ] : null,
            // Recomputed as the dates change (partial reload of `preview`).
            'preview' => fn () => $request->filled(['leave_type_id', 'date_from'])
                ? $applications->preview($employee, $request->only(['leave_type_id', 'date_from', 'date_to', 'is_half_day', 'detail', 'detail_text']), $returned)
                : null,
            'attachmentKilobytes' => LeaveApplications::ATTACHMENT_KILOBYTES,
        ]);
    }

    public function store(Request $request, LeaveApplications $applications): RedirectResponse
    {
        $employee = $this->employee($request);
        $application = $applications->file($employee, $this->validated($request, $applications), $request->user());

        $this->audit()->record(AuditEvent::LeaveApplicationFiled, "Filed {$application->label}", $application, [
            'type' => $application->leaveType->code, 'days' => (float) $application->days,
        ]);

        return to_route('leave.applications.show', $application)->with('status', "Application {$application->reference_number} was submitted.");
    }

    /** Correcting and resubmitting a returned application (a new round). */
    public function update(Request $request, LeaveApplication $application, LeaveApplications $applications): RedirectResponse
    {
        $this->authorize('update', $application);
        abort_unless($application->status->value === 'returned', 409);

        $applications->file($application->employee, $this->validated($request, $applications), $request->user(), $application);
        $this->audit()->record(AuditEvent::LeaveApplicationResubmitted, "Resubmitted {$application->label}", $application);

        return to_route('leave.applications.show', $application)->with('status', "Application {$application->reference_number} was resubmitted.");
    }

    public function cancel(Request $request, LeaveApplication $application, LeaveApplications $applications): RedirectResponse
    {
        $this->authorize('update', $application);

        $applications->cancel($application);
        $this->audit()->record(AuditEvent::LeaveApplicationCancelled, "Cancelled {$application->label}", $application);

        return back()->with('status', "Application {$application->reference_number} was cancelled.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, LeaveApplications $applications): array
    {
        return $request->validate($applications->rules(), [], LeaveApplications::attributes());
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403, 'HR hasn\'t set up your employee record yet.');

        return $employee->load(['user', 'immediateSupervisor:id,name']);
    }
}
