<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\Pds\PdsSubmission;
use App\Support\HR\Pds\PdsPrintout;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-2b: printing a signed PDS exactly as it was e-signed — for its
 * owner, or HR — on CS Form No. 212, or downloading it on CSC's own
 * workbook. Printing or downloading someone else's is audited.
 */
class PdsSubmissionPrintController extends Controller
{
    public function __invoke(Request $request, PdsSubmission $submission, PdsPrintout $printout): Response
    {
        $this->authorize('view', $submission);
        $this->trail($request, $submission, 'Printed');

        return Inertia::render('HR/PdsPrint', $printout->page(
            $submission->snapshot,
            $submission->photo_path ? "/pds/submissions/{$submission->id}/photo" : null,
            $this->signature($submission),
            route('pds.submissions.print.xlsx', $submission, false),
        ));
    }

    public function workbook(Request $request, PdsSubmission $submission, PdsPrintout $printout): HttpResponse
    {
        $this->authorize('view', $submission);
        $this->trail($request, $submission, 'Downloaded (Excel)');

        return $printout->download($submission->snapshot, $submission->photo_path, $this->signature($submission));
    }

    private function trail(Request $request, PdsSubmission $submission, string $what): void
    {
        $submission->load('employee.user', 'signer:id,name');

        if ($submission->employee->user_id !== $request->user()->id) {
            $this->audit()->record(AuditEvent::PdsPrinted, "{$what} a signed PDS of {$submission->employee->user->name}", $submission);
        }
    }

    /**
     * @return array{name: string, signed_at: string, status: string}
     */
    private function signature(PdsSubmission $submission): array
    {
        return [
            'name' => $submission->signer->name,
            'signed_at' => $submission->signed_at->toIso8601String(),
            'status' => $submission->status->label(),
        ];
    }
}
