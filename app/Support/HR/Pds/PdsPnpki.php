<?php

namespace App\Support\HR\Pds;

use App\Enums\EmployeeDocumentType;
use App\Enums\PdsSubmissionStatus;
use App\Models\EmployeeDocument;
use App\Models\Pds\PdsSubmission;
use App\Models\User;
use App\Support\HR\EmployeeDocuments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The PNPKI-signed copy of a signed PDS: the employee prints it for PNPKI
 * signing, signs each page's box with their certificate outside IMIS, and
 * attaches the PDF here. It is accepted when every digital signature in it
 * is intact, nothing was added after the last one, and there is at least
 * one per page of the form; it is then filed in the 201 File as "Personal
 * Data Sheet (signed)", and a newer copy replaces it (the old one is
 * archived, never deleted). What was found is kept on the submission for
 * HR.
 */
class PdsPnpki
{
    public function __construct(private PdfSignatures $signatures, private EmployeeDocuments $documents, private PdsForm $form) {}

    /** Only a submission that still stands: awaiting verification, or verified. */
    public function canAttach(PdsSubmission $submission): bool
    {
        return in_array($submission->status, [PdsSubmissionStatus::Submitted, PdsSubmissionStatus::Verified], true);
    }

    /**
     * How many signatures the PDF needs: one per page of this PDS.
     */
    public function pagesOf(PdsSubmission $submission): int
    {
        return count($this->form->pages($submission->snapshot, null, PdsForm::PNPKI));
    }

    /**
     * @throws ValidationException when the PDF isn't a properly signed copy
     */
    public function attach(PdsSubmission $submission, UploadedFile $file, User $by): EmployeeDocument
    {
        $found = $this->signatures->inspect($file->getRealPath());
        $signatures = $found['signatures'];
        $pages = $this->pagesOf($submission);

        $problem = match (true) {
            $signatures === [] => 'This PDF has no digital signature. Sign each page\'s signature box with your PNPKI certificate, save, and attach the signed PDF.',
            ($broken = collect($signatures)->firstWhere('intact', false)) !== null => 'One of the signatures in this PDF failed its check: '.lcfirst($broken['problem'] ?? 'it could not be verified.'),
            ! $found['unchanged_since_last'] => 'This PDF was changed after it was last signed. Sign it again, or attach the copy saved right after signing.',
            count($signatures) < $pages => 'This PDF has '.count($signatures).' '.(count($signatures) === 1 ? 'signature' : 'signatures')." but your PDS has {$pages} pages. Sign the signature box on every page.",
            default => null,
        };
        if ($problem !== null) {
            throw ValidationException::withMessages(['file' => $problem]);
        }

        return DB::transaction(function () use ($submission, $file, $by, $signatures) {
            $signers = collect($signatures)->pluck('signer')->filter()->unique()->implode(', ');
            $document = $this->documents->store($submission->employee, $file, [
                'document_type' => EmployeeDocumentType::PersonalDataSheet,
                'title' => 'PDS digitally signed with PNPKI (signed in IMIS '.$submission->signed_at->format('d/m/Y').')',
                'document_date' => today()->toDateString(),
                'remarks' => count($signatures).' digital '.(count($signatures) === 1 ? 'signature' : 'signatures').($signers ? " by {$signers}" : '').'.',
            ], $by);

            if ($submission->pnpkiDocument && ! $submission->pnpkiDocument->isArchived()) {
                $this->documents->archive($submission->pnpkiDocument, $by, 'Replaced by a newer PNPKI-signed copy.');
            }

            $submission->forceFill([
                'pnpki_document_id' => $document->id,
                'pnpki_signatures' => ['checked_at' => now()->toIso8601String(), 'signatures' => $signatures],
            ])->save();

            return $document;
        });
    }

    /**
     * For the submission pages: the attached copy and its signatures, or null.
     *
     * @return array{document_id: int, checked_at: string, chain_checked: bool, signatures: array<int, array<string, mixed>>}|null
     */
    public function present(PdsSubmission $submission): ?array
    {
        if ($submission->pnpki_document_id === null || $submission->pnpki_signatures === null) {
            return null;
        }

        $signatures = $submission->pnpki_signatures['signatures'] ?? [];

        return [
            'document_id' => $submission->pnpki_document_id,
            'checked_at' => $submission->pnpki_signatures['checked_at'] ?? null,
            'chain_checked' => collect($signatures)->contains(fn (array $signature) => $signature['trusted'] !== null),
            'signatures' => $signatures,
        ];
    }
}
