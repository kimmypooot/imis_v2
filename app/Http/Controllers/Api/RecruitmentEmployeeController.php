<?php

namespace App\Http\Controllers\Api;

use App\Enums\EmployeeDocumentType;
use App\Enums\LdApprovalStatus;
use App\Enums\PdsSubmissionStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\User;
use App\Support\HR\EmployeeDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the Recruitment Portal reads about an employee who applies for a vacancy:
 * the verified PDS and the few 201-file documents an application needs.
 * Called server-to-server (AuthenticateRecruitmentPortal) with the IMIS user id
 * that the sign-in hand-off gave the portal.
 *
 * Only data HR has vouched for is sent: the latest *verified* PDS submission
 * (the employee's signed PDS, never a draft or an unreviewed one) and approved
 * learning and development. The rest of the 201 file (birth and marriage
 * certificates, medical, SALN, …) is never exposed.
 */
class RecruitmentEmployeeController extends Controller
{
    /** The 201-file types an application uses. Nothing else leaves IMIS. */
    public const SHARED_DOCUMENT_TYPES = [
        EmployeeDocumentType::PersonalDataSheet,
        EmployeeDocumentType::TranscriptOfRecords,
        EmployeeDocumentType::PerformanceRating,
    ];

    public function pds(User $user): JsonResponse
    {
        $employee = $this->employee($user);

        $submission = $employee->submissions()
            ->where('status', PdsSubmissionStatus::Verified)
            ->latest('reviewed_at')->latest('id')
            ->first();

        $sections = $submission?->snapshot['sections'] ?? [];

        return response()->json(['data' => [
            'source' => $submission ? 'verified_pds' : null,
            'verified_at' => $submission?->reviewed_at?->toIso8601String(),
            'identity' => [
                'first_name' => $user->first_name,
                'middle_name' => $user->middle_name,
                'last_name' => $user->last_name,
                'name_extension' => $user->name_extension,
                'sex' => $user->sex,
                'birthday' => $user->birthday?->toDateString(),
                'email' => $user->email,
                'employee_number' => $employee->employee_number,
                'position' => $user->position,
            ],
            'personal' => $this->only($sections['personal'] ?? null, [
                'place_of_birth', 'civil_status', 'civil_status_other', 'height_m', 'weight_kg', 'blood_type',
                'umid_no', 'pagibig_no', 'philhealth_no', 'philsys_no', 'tin_no',
                'citizenship', 'dual_citizenship_by', 'dual_citizenship_country',
                'residential_house_no', 'residential_street', 'residential_subdivision', 'residential_barangay',
                'residential_city', 'residential_province', 'residential_zip_code',
                'permanent_house_no', 'permanent_street', 'permanent_subdivision', 'permanent_barangay',
                'permanent_city', 'permanent_province', 'permanent_zip_code',
                'telephone_no', 'mobile_no',
            ]),
            'education' => $this->rows($sections['education'] ?? [], [
                'level', 'school', 'degree', 'attendance_from', 'attendance_to', 'highest_level_units', 'year_graduated', 'honors',
            ]),
            'eligibility' => $this->rows($sections['eligibility'] ?? [], [
                'eligibility', 'rating', 'exam_date', 'exam_place', 'license_number', 'license_valid_until',
            ]),
            'work_experience' => $this->rows($sections['work_experience'] ?? [], [
                'date_from', 'date_to', 'position_title', 'agency', 'monthly_salary', 'salary_grade', 'appointment_status', 'is_government',
            ]),
            // Trainings are approved by HR on their own, so they do not wait for a PDS submission.
            'learning_development' => PdsLearningDevelopment::query()
                ->where('employee_id', $employee->id)
                ->where('approval_status', LdApprovalStatus::Approved)
                ->orderBy('date_from')
                ->get()
                ->map(fn (PdsLearningDevelopment $row) => [
                    'title' => $row->title,
                    'date_from' => $row->date_from?->toDateString(),
                    'date_to' => $row->date_to?->toDateString(),
                    'hours' => $row->hours !== null ? (float) $row->hours : null,
                    'ld_type' => $row->ld_type?->value,
                    'sponsor' => $row->sponsor,
                ])->values(),
        ]]);
    }

    public function documents(User $user): JsonResponse
    {
        $employee = $this->employee($user);

        return response()->json(['data' => $this->shared($employee)
            ->latest('document_date')->latest('id')
            ->get()
            ->map(fn (EmployeeDocument $document) => [
                'id' => $document->id,
                'document_type' => $document->document_type->value,
                'title' => $document->title,
                'document_date' => $document->document_date?->toDateString(),
                'original_name' => $document->original_name,
                'mime_type' => $document->mime_type,
                'size' => $document->size,
                'sha256' => $document->sha256,
            ])->values()]);
    }

    public function document(User $user, int $document): StreamedResponse
    {
        $record = $this->shared($this->employee($user))->whereKey($document)->firstOrFail();

        abort_unless(Storage::disk(EmployeeDocuments::DISK)->exists($record->file_path), 404);

        return Storage::disk(EmployeeDocuments::DISK)->download(
            $record->file_path,
            app(EmployeeDocuments::class)->downloadName($record),
            ['Content-Type' => $record->mime_type ?: 'application/octet-stream'],
        );
    }

    /** The user's employee record; a person who is not a current employee has none to share. */
    private function employee(User $user): Employee
    {
        $employee = $user->employee;

        abort_if($employee === null || $employee->separation_date !== null, 404, 'No current employee record.');

        return $employee;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<EmployeeDocument>
     */
    private function shared(Employee $employee)
    {
        return EmployeeDocument::query()->active()
            ->where('employee_id', $employee->id)
            ->whereIn('document_type', array_map(fn (EmployeeDocumentType $type) => $type->value, self::SHARED_DOCUMENT_TYPES));
    }

    /**
     * @param  array<string, mixed>|null  $section
     * @param  list<string>  $keys
     * @return array<string, mixed>|null
     */
    private function only(?array $section, array $keys): ?array
    {
        return $section === null ? null : collect($keys)->mapWithKeys(fn ($key) => [$key => $section[$key] ?? null])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows, array $keys): array
    {
        return collect($rows)->map(fn ($row) => collect($keys)->mapWithKeys(fn ($key) => [$key => $row[$key] ?? null])->all())->values()->all();
    }
}
