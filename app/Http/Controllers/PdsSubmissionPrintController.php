<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\Pds\PdsSubmission;
use App\Support\HR\Pds\PdsSchema;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-2b: printing a signed PDS exactly as it was e-signed — for its
 * owner, or HR. Printing someone else's is audited.
 */
class PdsSubmissionPrintController extends Controller
{
    public function __invoke(Request $request, PdsSubmission $submission): Response
    {
        $this->authorize('view', $submission);

        $submission->load('employee.user', 'signer:id,name');

        if ($submission->employee->user_id !== $request->user()->id) {
            $this->audit()->record(AuditEvent::PdsPrinted, "Printed a signed PDS of {$submission->employee->user->name}", $submission);
        }

        return Inertia::render('HR/PdsPrint', [
            'schema' => PdsSchema::forEditor(),
            'pds' => $submission->snapshot,
            'photoUrl' => $submission->photo_path ? "/pds/submissions/{$submission->id}/photo" : null,
            'signature' => [
                'name' => $submission->signer->name,
                'signed_at' => $submission->signed_at->toIso8601String(),
                'status' => $submission->status->label(),
            ],
        ]);
    }
}
