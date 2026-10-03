<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\LeaveStepAction;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\HR\Leave\LeaveApplications;
use App\Support\HR\Plantilla\Plantilla;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR-6b: one leave application — for the employee, the people on its
 * approval chain, and HR (LeaveApplicationPolicy::view) — with its
 * approval history, the step's action form when it's yours to act on,
 * the printable CS Form No. 6, and the attachment. Anyone other than the
 * employee opening it is on the access trail.
 */
class LeaveApplicationController extends Controller
{
    public function show(Request $request, LeaveApplication $application, LeaveApplications $applications): Response
    {
        $this->authorize('view', $application);
        $viewer = $request->user();
        $this->trail($request, $application, AuditEvent::LeaveApplicationViewed, 'Viewed');

        $step = $application->pendingStep();
        $authority = $step ? $applications->authority($viewer, $step) : ['can' => false, 'acting_for' => null];

        return Inertia::render('Portal/LeaveApplication', [
            'application' => $applications->present($application),
            'steps' => $applications->presentSteps($application),
            'isOwn' => $application->employee->user_id === $viewer->id,
            'canUpdate' => $viewer->can('update', $application),
            // The step waiting for this viewer, with what they may do.
            'action' => $authority['can'] ? [
                'step_id' => $step->id,
                'role' => $step->role,
                'role_label' => $step->roleLabel(),
                'acting_for' => $authority['acting_for'] ? User::query()->whereKey($authority['acting_for'])->value('name') : null,
                'options' => array_map(fn (LeaveStepAction $action) => ['value' => $action->value, 'label' => $action->label(), 'needs_remarks' => $action->needsRemarks()],
                    LeaveStepAction::forRole($step->role)),
            ] : null,
        ]);
    }

    public function print(Request $request, LeaveApplication $application, LeaveApplications $applications, Plantilla $plantilla): Response
    {
        $this->authorize('view', $application);
        $this->trail($request, $application, AuditEvent::LeaveApplicationViewed, 'Printed');

        $employee = $application->employee()->with(['user.organizationalUnit'])->first();
        $user = $employee->user;
        $current = $plantilla->historyOf($employee)->firstWhere('current', true);

        return Inertia::render('HR/LeaveFormPrint', [
            'application' => $applications->present($application),
            'steps' => $applications->presentSteps($application)->where('round', $application->currentRound())->values(),
            'person' => [
                'office' => $user->organizationalUnit?->name,
                'surname' => $user->last_name ?? $user->name,
                'first_name' => $user->first_name,
                'middle_name' => $user->middle_name,
                'position' => $user->position,
                'monthly_salary' => $current['monthly_salary'] ?? null,
            ],
            'types' => LeaveType::query()->active()->ordered()->get(['id', 'code', 'name']),
        ]);
    }

    public function attachment(Request $request, LeaveApplication $application): StreamedResponse
    {
        $this->authorize('view', $application);
        abort_unless($application->attachment_path && Storage::disk(LeaveApplications::DISK)->exists($application->attachment_path), 404);
        $this->trail($request, $application, AuditEvent::LeaveAttachmentViewed, 'Opened the attachment of');

        return Storage::disk(LeaveApplications::DISK)->response($application->attachment_path, $application->attachment_name, [
            'Content-Type' => $application->attachment_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    private function trail(Request $request, LeaveApplication $application, AuditEvent $event, string $verb): void
    {
        if ($application->employee()->value('user_id') !== $request->user()->id) {
            $this->audit()->record($event, "{$verb} {$application->label}", $application);
        }
    }
}
