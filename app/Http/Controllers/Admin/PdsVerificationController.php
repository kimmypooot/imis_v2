<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\PdsSubmissionStatus;
use App\Http\Controllers\Controller;
use App\Models\Pds\PdsSubmission;
use App\Support\HR\Pds\PdsSchema;
use App\Support\HR\Pds\PdsSubmissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-2b: the PDS verification queue. HR reviews exactly what each
 * employee e-signed — highlighting what changed since their last
 * verified PDS — and verifies it (into the 201 file) or returns it with
 * remarks. Never their own.
 */
class PdsVerificationController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', PdsSubmission::class);

        $verifiedEmployees = PdsSubmission::query()->where('status', PdsSubmissionStatus::Verified)->distinct()->pluck('employee_id');

        $pending = PdsSubmission::query()
            ->where('status', PdsSubmissionStatus::Submitted)
            ->with('employee.user.organizationalUnit')
            ->oldest('signed_at')
            ->get()
            ->map(fn (PdsSubmission $submission) => [
                'id' => $submission->id,
                'name' => $submission->employee->user->name,
                'position' => $submission->employee->user->position,
                'unit' => $submission->employee->user->organizationalUnit?->short_name ?: $submission->employee->user->organizationalUnit?->name,
                'signed_at' => $submission->signed_at->toIso8601String(),
                'kind' => $verifiedEmployees->contains($submission->employee_id) ? 'Update' : 'First submission',
            ]);

        return Inertia::render('Admin/HR/PdsVerifications/Index', ['pending' => $pending]);
    }

    public function show(Request $request, PdsSubmission $submission, PdsSubmissions $submissions): Response
    {
        $this->authorize('view', $submission);

        $submission->load('employee.user', 'signer:id,name', 'reviewer:id,name');
        $employee = $submission->employee;

        $previous = $employee->submissions()
            ->where('status', PdsSubmissionStatus::Verified)
            ->where('id', '<', $submission->id)
            ->latest('id')
            ->first();

        if ($employee->user_id !== $request->user()->id) {
            $this->audit()->record(AuditEvent::PdsViewed, "Viewed a PDS submission of {$employee->user->name}", $submission);
        }

        return Inertia::render('Admin/HR/PdsVerifications/Show', [
            'employee' => ['id' => $employee->id, 'name' => $employee->user->name],
            'schema' => PdsSchema::forEditor(),
            'submission' => [
                ...$submissions->summary($submission),
                'pds' => $submission->snapshot,
                'signer' => $submission->signer->name,
                'signed_ip' => $submission->signed_ip,
                'photo_url' => $submission->photo_path ? "/pds/submissions/{$submission->id}/photo" : null,
            ],
            // What to look at: sections that differ from the last verified PDS.
            'changedSections' => $previous ? $submissions->changedSections($submission->snapshot, $previous->snapshot) : null,
            'previousVerifiedAt' => $previous?->reviewed_at?->toIso8601String(),
            'canReview' => $request->user()->can('review', $submission),
        ]);
    }

    public function verify(Request $request, PdsSubmission $submission, PdsSubmissions $submissions): RedirectResponse
    {
        return $this->decide($request, $submission, $submissions, PdsSubmissionStatus::Verified);
    }

    public function sendBack(Request $request, PdsSubmission $submission, PdsSubmissions $submissions): RedirectResponse
    {
        return $this->decide($request, $submission, $submissions, PdsSubmissionStatus::Returned);
    }

    private function decide(Request $request, PdsSubmission $submission, PdsSubmissions $submissions, PdsSubmissionStatus $verdict): RedirectResponse
    {
        $this->authorize('review', $submission);
        abort_unless($submission->isPending(), 409);

        $remarks = $request->validate([
            // Returning needs a reason the employee can act on.
            'remarks' => [$verdict === PdsSubmissionStatus::Returned ? 'required' : 'nullable', 'string', 'max:2000'],
        ])['remarks'] ?? null;

        $submissions->review($submission, $request->user(), $verdict, $remarks);

        $name = $submission->employee->user->name;
        $verified = $verdict === PdsSubmissionStatus::Verified;

        $this->audit()->record(
            $verified ? AuditEvent::PdsVerified : AuditEvent::PdsReturned,
            ($verified ? 'Verified' : 'Returned')." the PDS of {$name}",
            $submission,
        );

        return to_route('admin.hr.pds-verifications.index')
            ->with('status', $verified ? "The PDS of {$name} was verified and filed." : "The PDS of {$name} was returned with your remarks.");
    }
}
