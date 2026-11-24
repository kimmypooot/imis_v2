<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\LeaveStepAction;
use App\Models\LeaveApplication;
use App\Models\User;
use App\Support\HR\Leave\LeaveApplications;
use App\Support\HR\Leave\LeaveForm;
use App\Support\HR\Leave\LeaveFormWorkbook;
use App\Support\HR\Signatures;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR-6b: one leave application — for the employee, the people on its
 * approval chain, and HR (LeaveApplicationPolicy::view) — with its
 * approval history, the step's action form when it's yours to act on,
 * the printable CS Form No. 6 (and its Excel copy), and the attachment. Anyone other than the
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
            'hasSignature' => $viewer->signature_path !== null,
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

    public function print(Request $request, LeaveApplication $application, LeaveForm $form): Response
    {
        $this->authorize('view', $application);
        $this->trail($request, $application, AuditEvent::LeaveApplicationViewed, 'Printed');

        return Inertia::render('HR/LeaveFormPrint', [
            'form' => $form->present($application, $request->user()),
            'types' => collect(LeaveForm::TYPES)->map(fn (array $type, string $code) => ['code' => $code, 'name' => $type[0], 'basis' => $type[1]])->values(),
        ]);
    }

    /** The same form as an Excel workbook on CSC's own template, set for Folio. */
    public function workbook(Request $request, LeaveApplication $application, LeaveForm $form, LeaveFormWorkbook $workbook): HttpResponse
    {
        $this->authorize('view', $application);
        $this->trail($request, $application, AuditEvent::LeaveApplicationViewed, 'Downloaded CS Form No. 6 (Excel) of');

        return response($workbook->build($form->present($application, $request->user())), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="CS Form No. 6 - '.$application->reference_number.'.xlsx"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The e-signature image kept with the applicant's filing or an approval step. */
    public function signature(Request $request, LeaveApplication $application, string $signer): StreamedResponse
    {
        $this->authorize('view', $application);

        $path = $signer === 'applicant'
            ? $application->signature_path
            : $application->steps()->whereKey((int) $signer)->value('signature_path');
        abort_unless(Signatures::exists($path), 404);

        return Storage::disk(Signatures::DISK)->response($path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function attachment(Request $request, LeaveApplication $application): StreamedResponse
    {
        return $this->file($request, $application, 'attachment');
    }

    /** The clearance from money, property and work accountabilities (leave of 30 days or more). */
    public function clearance(Request $request, LeaveApplication $application): StreamedResponse
    {
        return $this->file($request, $application, 'clearance');
    }

    /**
     * @param  'attachment'|'clearance'  $slot
     */
    private function file(Request $request, LeaveApplication $application, string $slot): StreamedResponse
    {
        $this->authorize('view', $application);
        $path = $application->{"{$slot}_path"};
        abort_unless($path && Storage::disk(LeaveApplications::DISK)->exists($path), 404);
        $this->trail($request, $application, AuditEvent::LeaveAttachmentViewed, $slot === 'clearance' ? 'Opened the clearance of' : 'Opened the attachment of');

        return Storage::disk(LeaveApplications::DISK)->response($path, $application->{"{$slot}_name"}, [
            'Content-Type' => $application->{"{$slot}_mime"},
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
