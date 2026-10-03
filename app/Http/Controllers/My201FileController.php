<?php

namespace App\Http\Controllers;

use App\Enums\PdsSubmissionStatus;
use App\Models\EmployeeDocument;
use App\Support\HR\EmployeeDocuments;
use App\Support\HR\Pds\PdsSubmissions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-2c self-service: your own 201 file — the documents HR keeps (not the
 * archived ones) and your verified PDS history. Read-only; HR uploads.
 */
class My201FileController extends Controller
{
    public function show(Request $request, EmployeeDocuments $documents, PdsSubmissions $submissions): Response
    {
        $employee = $request->user()->employee;

        return Inertia::render('Portal/My201File', [
            'hasRecord' => $employee !== null,
            'documents' => $employee
                ? $employee->documents()->active()->with('uploader:id,name')->orderByDesc('document_date')->orderByDesc('id')->get()
                    ->map(fn (EmployeeDocument $document) => [
                        // The owner doesn't need HR's archive details.
                        ...collect($documents->present($document))->except(['archived_at', 'archived_by', 'archive_reason'])->all(),
                    ])
                : [],
            'pdsHistory' => $employee
                ? collect($submissions->history($employee))->where('status', PdsSubmissionStatus::Verified->value)->values()
                : [],
        ]);
    }
}
