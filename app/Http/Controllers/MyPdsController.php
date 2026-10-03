<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Http\Controllers\Concerns\SavesPdsSections;
use App\Models\Employee;
use App\Models\Pds\PdsSubmission;
use App\Support\HR\Pds\PdsReader;
use App\Support\HR\Pds\PdsSchema;
use App\Support\HR\Pds\PdsSubmissions;
use App\Support\HR\Pds\PdsWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-2 self-service: every employee keeps their own PDS (CS Form No.
 * 212, Revised 2026) current, e-signs and submits it, and sees HR's
 * verdict and their PDS history. Needs an HR-1 employee record first.
 */
class MyPdsController extends Controller
{
    use SavesPdsSections;

    public function show(Request $request, PdsReader $reader, PdsSubmissions $submissions): Response
    {
        $employee = $this->employee($request);

        return Inertia::render('Portal/MyPds', [
            'schema' => $employee ? PdsSchema::forEditor() : null,
            'pds' => $employee ? $reader->read($employee) : null,
            'status' => $employee ? $submissions->status($employee) : null,
            'history' => $employee ? $submissions->history($employee) : [],
            'photoUrl' => $employee ? $this->photoUrl($employee) : null,
        ]);
    }

    public function update(Request $request, string $section, PdsWriter $writer): RedirectResponse
    {
        $employee = $this->requireEmployee($request);
        $this->authorize('updatePds', $employee);

        return $this->savePdsSection($request, $employee, $section, $writer);
    }

    /**
     * The e-signature: accepting item 42's declaration and re-entering
     * the account password, then freezing the PDS as a submission.
     */
    public function submit(Request $request, PdsSubmissions $submissions): RedirectResponse
    {
        $employee = $this->requireEmployee($request);
        $this->authorize('updatePds', $employee);

        $request->validate([
            'declaration' => ['accepted'],
            'password' => ['required', 'current_password'],
        ], [
            'declaration.accepted' => 'You need to accept the declaration to sign your PDS.',
            'password.current_password' => 'That password is incorrect.',
        ]);

        if (in_array($submissions->status($employee)['state'], ['submitted', 'verified'], true)) {
            throw ValidationException::withMessages([
                'declaration' => 'Nothing has changed since your last submission.',
            ]);
        }

        $submission = $submissions->submit($employee, $request->user(), $request->ip());

        $this->audit()->record(AuditEvent::PdsSubmitted, 'E-signed and submitted their PDS for verification', $submission);

        return back()->with('status', 'Your PDS was signed and submitted. HR will verify it.');
    }

    /**
     * A past submission of your own, read-only.
     */
    public function submission(Request $request, PdsSubmission $submission, PdsSubmissions $submissions): Response
    {
        abort_unless($submission->employee_id === $this->employee($request)?->id, 404);

        return Inertia::render('Portal/MyPdsSubmission', [
            'schema' => PdsSchema::forEditor(),
            'submission' => [
                ...$submissions->summary($submission->load('reviewer:id,name')),
                'pds' => $submission->snapshot,
                'photo_url' => $submission->photo_path ? "/pds/submissions/{$submission->id}/photo" : null,
            ],
        ]);
    }

    public function print(Request $request, PdsReader $reader): Response
    {
        $employee = $this->requireEmployee($request);

        return Inertia::render('HR/PdsPrint', [
            'schema' => PdsSchema::forEditor(),
            'pds' => $reader->read($employee),
            'photoUrl' => $this->photoUrl($employee),
            'signature' => null,
        ]);
    }

    private function employee(Request $request): ?Employee
    {
        return $request->user()->employee;
    }

    private function requireEmployee(Request $request): Employee
    {
        return $this->employee($request) ?? abort(404);
    }

    private function photoUrl(Employee $employee): ?string
    {
        $path = $employee->pdsProfile?->photo_path;

        // The path changes with every upload, so it doubles as a cache key.
        return $path ? '/my/pds/photo?v='.substr(md5($path), 0, 8) : null;
    }
}
