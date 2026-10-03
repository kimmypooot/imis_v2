<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\EmployeeDocumentType;
use App\Enums\PdsSubmissionStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\HR\EmployeeDocuments;
use App\Support\HR\Pds\PdsSubmissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-2c: an employee's 201 file for HR — the documents (upload, archive,
 * restore with `employee_documents.manage`, never in your own 201 file)
 * and the verified PDS history. Opening a file is audited separately
 * (EmployeeDocumentFileController).
 */
class EmployeeDocumentController extends Controller
{
    public function index(Request $request, Employee $employee, EmployeeDocuments $documents, PdsSubmissions $submissions): Response
    {
        $this->authorize('viewDocuments', $employee);

        $employee->load('user');

        return Inertia::render('Admin/HR/Employees/Documents', [
            'employee' => ['id' => $employee->id, 'name' => $employee->user->name],
            'documents' => $employee->documents()
                ->with('uploader:id,name', 'archiver:id,name')
                ->orderByRaw('archived_at is not null')
                ->orderByDesc('document_date')
                ->orderByDesc('id')
                ->get()
                ->map(fn (EmployeeDocument $document) => $documents->present($document)),
            'pdsHistory' => collect($submissions->history($employee))
                ->where('status', PdsSubmissionStatus::Verified->value)
                ->values(),
            'typeOptions' => EmployeeDocumentType::options(),
            'canManage' => $request->user()->can('manageDocuments', $employee),
            'maxKilobytes' => EmployeeDocuments::MAX_KILOBYTES,
        ]);
    }

    public function store(Request $request, Employee $employee, EmployeeDocuments $documents): RedirectResponse
    {
        $this->authorize('manageDocuments', $employee);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:'.implode(',', EmployeeDocuments::EXTENSIONS), 'max:'.EmployeeDocuments::MAX_KILOBYTES],
            'document_type' => ['required', new Enum(EmployeeDocumentType::class)],
            // "Other" needs a title to say what it is.
            'title' => ['nullable', 'required_if:document_type,other', 'string', 'max:255'],
            'document_date' => ['nullable', 'date', 'after:1900-01-01', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ], [
            'file.mimes' => 'Upload a PDF, JPG or PNG file.',
            'title.required_if' => 'Describe the document when its type is "Other".',
        ]);

        $document = $documents->store($employee, $request->file('file'), collect($validated)->except('file')->all(), $request->user());

        $this->audit()->record(AuditEvent::EmployeeDocumentUploaded, "Uploaded {$document->label}", $document, [
            'type' => $document->document_type->value,
            'size' => $document->size,
        ]);

        return back()->with('status', "{$document->document_type->label()} was added to the 201 file.");
    }

    public function archive(Request $request, Employee $employee, EmployeeDocument $document, EmployeeDocuments $documents): RedirectResponse
    {
        $this->authorize('manageDocuments', $employee);
        abort_if($document->isArchived(), 409);

        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        $documents->archive($document, $request->user(), $reason);

        $this->audit()->record(AuditEvent::EmployeeDocumentArchived, "Archived {$document->label}", $document, ['reason' => $reason]);

        return back()->with('status', "{$document->document_type->label()} was archived. It stays in the 201 file's history.");
    }

    public function restore(Employee $employee, EmployeeDocument $document, EmployeeDocuments $documents): RedirectResponse
    {
        $this->authorize('manageDocuments', $employee);
        abort_unless($document->isArchived(), 409);

        $documents->restore($document);

        $this->audit()->record(AuditEvent::EmployeeDocumentRestored, "Restored {$document->label}", $document);

        return back()->with('status', "{$document->document_type->label()} was restored.");
    }
}
