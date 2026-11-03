<?php

namespace Tests\Feature\HR;

use App\Enums\EmployeeDocumentType;
use App\Enums\LdApprovalStatus;
use App\Enums\PdsSubmissionStatus;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\Pds\PdsSubmission;
use App\Models\User;
use App\Support\HR\EmployeeDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What the Recruitment Portal reads about an employee who applies: the verified
 * PDS, approved trainings, and a short list of 201-file documents.
 */
class RecruitmentEmployeeApiTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        config(['recruitment.api_token' => 'secret-token']);
        Storage::fake(EmployeeDocuments::DISK);

        $this->employee = Employee::factory()->create();
    }

    private function fetch(string $path)
    {
        return $this->withToken('secret-token')->getJson("/api/v1/recruitment/employees/{$this->employee->user_id}/{$path}");
    }

    private function submission(PdsSubmissionStatus $status, array $sections, string $reviewedAt = '2026-10-01 08:00:00'): PdsSubmission
    {
        $submission = new PdsSubmission;
        $submission->forceFill([
            'employee_id' => $this->employee->id,
            'status' => $status,
            'snapshot' => ['sections' => $sections, 'identity' => [], 'form' => 'test'],
            'fingerprint' => str_repeat('a', 64),
            'signed_by' => $this->employee->user_id,
            'signed_at' => now(),
            'reviewed_at' => $reviewedAt,
        ])->save();

        return $submission;
    }

    private function document(EmployeeDocumentType $type, array $extra = []): EmployeeDocument
    {
        Storage::disk(EmployeeDocuments::DISK)->put($path = "hr/201/{$this->employee->id}/".uniqid().'.pdf', 'PDF-BYTES');

        $document = new EmployeeDocument(['document_type' => $type, 'title' => $type->label()]);
        $document->forceFill([
            'employee_id' => $this->employee->id,
            'file_path' => $path,
            'original_name' => 'scan.pdf',
            'mime_type' => 'application/pdf',
            'size' => 9,
            'sha256' => hash('sha256', 'PDF-BYTES'),
            'uploaded_by' => $this->employee->user_id,
            ...$extra,
        ])->save();

        return $document;
    }

    public function test_the_endpoints_need_the_shared_token(): void
    {
        $this->getJson("/api/v1/recruitment/employees/{$this->employee->user_id}/pds")->assertUnauthorized();
        $this->withToken('wrong')->getJson("/api/v1/recruitment/employees/{$this->employee->user_id}/documents")->assertUnauthorized();
    }

    public function test_the_latest_verified_pds_is_sent_and_nothing_unreviewed(): void
    {
        $this->submission(PdsSubmissionStatus::Verified, [
            'personal' => ['civil_status' => 'Single', 'tin_no' => 'OLD'],
            'education' => [['level' => 'College', 'school' => 'Old U']],
        ], '2026-01-01 08:00:00');
        $this->submission(PdsSubmissionStatus::Verified, [
            'personal' => ['civil_status' => 'Married', 'tin_no' => '123-456', 'secret_extra' => 'x'],
            'education' => [['level' => 'College', 'school' => 'UP Tacloban', 'degree' => 'BS Statistics', 'year_graduated' => '2012']],
            'eligibility' => [['eligibility' => 'Career Service Professional', 'rating' => '85.2']],
            'work_experience' => [['position_title' => 'Admin Aide', 'agency' => 'CSC RO VIII', 'is_government' => true]],
        ]);
        $this->submission(PdsSubmissionStatus::Submitted, ['personal' => ['civil_status' => 'Widowed']], '2026-12-01 08:00:00');

        $data = $this->fetch('pds')->assertOk()->json('data');

        $this->assertSame('verified_pds', $data['source']);
        $this->assertSame('Married', $data['personal']['civil_status']);
        $this->assertSame('123-456', $data['personal']['tin_no']);
        $this->assertArrayNotHasKey('secret_extra', $data['personal']);
        $this->assertSame('UP Tacloban', $data['education'][0]['school']);
        $this->assertSame('Career Service Professional', $data['eligibility'][0]['eligibility']);
        $this->assertSame('Admin Aide', $data['work_experience'][0]['position_title']);
        $this->assertSame($this->employee->user->email, $data['identity']['email']);
    }

    public function test_without_a_verified_pds_only_identity_and_trainings_are_sent(): void
    {
        $this->submission(PdsSubmissionStatus::Submitted, ['personal' => ['civil_status' => 'Single']]);

        $data = $this->fetch('pds')->assertOk()->json('data');

        $this->assertNull($data['source']);
        $this->assertNull($data['personal']);
        $this->assertSame([], $data['education']);
        $this->assertSame($this->employee->user->email, $data['identity']['email']);
    }

    public function test_only_approved_trainings_are_sent(): void
    {
        foreach ([['Approved course', LdApprovalStatus::Approved], ['Pending course', LdApprovalStatus::Pending], ['Rejected course', LdApprovalStatus::Rejected]] as [$title, $status]) {
            $row = new PdsLearningDevelopment(['title' => $title]);
            $row->forceFill(['employee_id' => $this->employee->id, 'approval_status' => $status, 'hours' => 8, 'date_from' => '2025-03-03', 'date_to' => '2025-03-04'])->save();
        }

        $titles = array_column($this->fetch('pds')->assertOk()->json('data.learning_development'), 'title');

        $this->assertSame(['Approved course'], $titles);
    }

    public function test_only_the_shared_document_types_are_listed_and_never_archived_ones(): void
    {
        $pds = $this->document(EmployeeDocumentType::PersonalDataSheet);
        $this->document(EmployeeDocumentType::TranscriptOfRecords);
        $this->document(EmployeeDocumentType::PerformanceRating, ['archived_at' => now()]);
        $this->document(EmployeeDocumentType::BirthCertificate);
        $this->document(EmployeeDocumentType::Saln);
        $this->document(EmployeeDocumentType::MedicalCertificate);

        $listed = $this->fetch('documents')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['personal_data_sheet', 'transcript_of_records'], array_column($listed, 'document_type'));
        $this->assertSame(hash('sha256', 'PDF-BYTES'), $listed[0]['sha256']);
        $this->assertContains($pds->id, array_column($listed, 'id'));
    }

    public function test_a_shared_document_downloads_and_others_do_not(): void
    {
        $tor = $this->document(EmployeeDocumentType::TranscriptOfRecords);
        $birth = $this->document(EmployeeDocumentType::BirthCertificate);
        $other = Employee::factory()->create();
        $theirs = new EmployeeDocument(['document_type' => EmployeeDocumentType::TranscriptOfRecords, 'title' => 'x']);
        $theirs->forceFill(['employee_id' => $other->id, 'file_path' => 'x.pdf', 'original_name' => 'x.pdf', 'mime_type' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('b', 64), 'uploaded_by' => $other->user_id])->save();

        $this->fetch("documents/{$tor->id}/file")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->fetch("documents/{$birth->id}/file")->assertNotFound();
        $this->fetch("documents/{$theirs->id}/file")->assertNotFound();
    }

    public function test_a_user_with_no_current_employee_record_has_nothing_to_share(): void
    {
        $this->employee->forceFill(['separation_date' => now()->subDay()->toDateString()])->save();
        $this->fetch('pds')->assertNotFound();

        $outsider = User::factory()->create();
        $this->withToken('secret-token')->getJson("/api/v1/recruitment/employees/{$outsider->id}/pds")->assertNotFound();
    }
}
