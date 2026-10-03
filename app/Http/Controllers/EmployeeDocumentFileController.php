<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\EmployeeDocument;
use App\Support\HR\EmployeeDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR-2c: serving one 201-file document — to view in the browser or to
 * download — only to its owner or HR (EmployeeDocumentPolicy). Personal
 * data: never cached, never sniffed as something else, and every access
 * by someone other than the employee is audited.
 */
class EmployeeDocumentFileController extends Controller
{
    public function show(Request $request, EmployeeDocument $document, EmployeeDocuments $documents): StreamedResponse
    {
        return $this->serve($request, $document, $documents, 'inline');
    }

    public function download(Request $request, EmployeeDocument $document, EmployeeDocuments $documents): StreamedResponse
    {
        return $this->serve($request, $document, $documents, 'attachment');
    }

    private function serve(Request $request, EmployeeDocument $document, EmployeeDocuments $documents, string $disposition): StreamedResponse
    {
        $this->authorize('view', $document);
        abort_unless(Storage::disk(EmployeeDocuments::DISK)->exists($document->file_path), 404);

        if ($document->employee()->value('user_id') !== $request->user()->id) {
            $this->audit()->record(
                AuditEvent::EmployeeDocumentViewed,
                ($disposition === 'inline' ? 'Opened ' : 'Downloaded ').$document->label,
                $document,
            );
        }

        $name = $documents->downloadName($document);

        return Storage::disk(EmployeeDocuments::DISK)->response($document->file_path, $name, [
            'Content-Type' => $document->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $disposition);
    }
}
