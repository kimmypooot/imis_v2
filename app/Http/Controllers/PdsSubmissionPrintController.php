<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\Pds\PdsSubmission;
use App\Support\HR\Pds\PdsPrintout;
use App\Support\HR\Signatures;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $signature = $this->signature($submission);

        return Inertia::render('HR/PdsPrint', $printout->page(
            $submission->snapshot,
            $submission->photo_path ? "/pds/submissions/{$submission->id}/photo" : null,
            $signature,
            route('pds.submissions.print', $submission, false),
            route('pds.submissions.print.xlsx', $submission, false),
            $printout->mode($request, $signature),
        ));
    }

    public function workbook(Request $request, PdsSubmission $submission, PdsPrintout $printout): HttpResponse
    {
        $this->authorize('view', $submission);
        $this->trail($request, $submission, 'Downloaded (Excel)');
        $signature = $this->signature($submission);

        return $printout->download($submission->snapshot, $submission->photo_path, $signature, $printout->mode($request, $signature));
    }

    /** The e-signature image kept with the signed copy. */
    public function signatureImage(Request $request, PdsSubmission $submission): StreamedResponse
    {
        $this->authorize('view', $submission);
        abort_unless(Signatures::exists($submission->signature_path), 404);

        return Storage::disk(Signatures::DISK)->response($submission->signature_path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function trail(Request $request, PdsSubmission $submission, string $what): void
    {
        $submission->load('employee.user', 'signer:id,name');

        if ($submission->employee->user_id !== $request->user()->id) {
            $this->audit()->record(AuditEvent::PdsPrinted, "{$what} a signed PDS of {$submission->employee->user->name}", $submission);
        }
    }

    /**
     * Who signed and when, and their e-signature image when they had one
     * saved: its URL for the print page, its file for the Excel copy.
     *
     * @return array{name: string, signed_at: string, status: string, image_url: ?string, image_file: ?string}
     */
    private function signature(PdsSubmission $submission): array
    {
        $image = Signatures::exists($submission->signature_path);

        return [
            'name' => $submission->signer->name,
            'signed_at' => $submission->signed_at->toIso8601String(),
            'status' => $submission->status->label(),
            'image_url' => $image ? "/pds/submissions/{$submission->id}/signature" : null,
            'image_file' => $image ? Storage::disk(Signatures::DISK)->path($submission->signature_path) : null,
        ];
    }
}
