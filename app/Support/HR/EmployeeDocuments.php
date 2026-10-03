<?php

namespace App\Support\HR;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * HR-2c: the only writer of 201-file documents. Files go to the private
 * disk under a random name (never the uploaded file name), with a SHA-256
 * of their content. Nothing is deleted — archive and restore only.
 */
class EmployeeDocuments
{
    public const DISK = 'local';

    /**
     * Accepted by content, not by the name the uploader gave it.
     */
    public const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    public const MAX_KILOBYTES = 10240;

    /**
     * @param  array<string, mixed>  $details  document_type, title, document_date, remarks
     */
    public function store(Employee $employee, UploadedFile $file, array $details, User $uploader): EmployeeDocument
    {
        $extension = $file->guessExtension() === 'jpeg' ? 'jpg' : $file->guessExtension();
        $path = $file->storeAs("hr/201/{$employee->id}", Str::uuid().'.'.$extension, self::DISK);

        $document = new EmployeeDocument($details);
        $document->forceFill([
            'employee_id' => $employee->id,
            'file_path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $uploader->id,
        ])->save();

        return $document;
    }

    public function archive(EmployeeDocument $document, User $by, string $reason): void
    {
        $document->forceFill(['archived_at' => now(), 'archived_by' => $by->id, 'archive_reason' => $reason])->save();
    }

    public function restore(EmployeeDocument $document): void
    {
        $document->forceFill(['archived_at' => null, 'archived_by' => null, 'archive_reason' => null])->save();
    }

    /**
     * A safe name for the downloaded file: "Oath of Office - 2021-03-15.pdf".
     */
    public function downloadName(EmployeeDocument $document): string
    {
        $base = Str::of($document->title ?: $document->document_type->label())
            ->append($document->document_date ? ' - '.$document->document_date->toDateString() : '')
            ->replaceMatches('/[^\pL\pN\s\-().,]/u', '')
            ->squish()
            ->limit(120, '');

        return $base.'.'.pathinfo($document->file_path, PATHINFO_EXTENSION);
    }

    /**
     * What the pages show for one document.
     *
     * @return array<string, mixed>
     */
    public function present(EmployeeDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_type' => $document->document_type->value,
            'type_label' => $document->document_type->label(),
            'title' => $document->title,
            'document_date' => $document->document_date?->toDateString(),
            'remarks' => $document->remarks,
            'mime_type' => $document->mime_type,
            'size' => $document->size,
            'uploaded_by' => $document->uploader?->name,
            'uploaded_at' => $document->created_at->toIso8601String(),
            'archived_at' => $document->archived_at?->toIso8601String(),
            'archived_by' => $document->archiver?->name,
            'archive_reason' => $document->archive_reason,
        ];
    }
}
