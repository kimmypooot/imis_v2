<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-2c: 201-file documents — private storage, who can open them, the
 * access trail, archive instead of delete, and the self-service page.
 */
class EmployeeDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_hr_uploads_a_document_privately_and_it_is_audited(): void
    {
        $employee = Employee::factory()->create();
        $hr = $this->userWith(['employee_documents.view', 'employee_documents.manage']);

        $this->actingAs($hr)->post("/admin/hr/employees/{$employee->id}/documents", [
            'document_type' => 'oath_of_office',
            'document_date' => '2021-03-15',
            'file' => UploadedFile::fake()->create('scan of my oath.pdf', 200, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $document = EmployeeDocument::query()->sole();
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertStringStartsWith("hr/201/{$employee->id}/", $document->file_path);
        // Stored under a random name, never the uploaded one.
        $this->assertStringNotContainsString('oath', $document->file_path);
        $this->assertSame('scan of my oath.pdf', $document->original_name);
        $this->assertSame(64, strlen($document->sha256));
        $this->assertSame($hr->id, $document->uploaded_by);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee_document.uploaded', 'actor_id' => $hr->id, 'target_id' => $document->id]);
    }

    public function test_uploads_are_validated(): void
    {
        $employee = Employee::factory()->create();
        $hr = $this->userWith(['employee_documents.manage']);
        $url = "/admin/hr/employees/{$employee->id}/documents";

        $this->actingAs($hr)->post($url, ['document_type' => 'diploma', 'file' => UploadedFile::fake()->create('virus.exe', 10)])
            ->assertSessionHasErrors('file');
        $this->actingAs($hr)->post($url, ['document_type' => 'diploma', 'file' => UploadedFile::fake()->create('big.pdf', 20000, 'application/pdf')])
            ->assertSessionHasErrors('file');
        $this->actingAs($hr)->post($url, ['document_type' => 'other', 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('title');
        $this->actingAs($hr)->post($url, ['document_type' => 'not_a_type', 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('document_type');

        $this->assertSame(0, EmployeeDocument::query()->count());
    }

    public function test_hr_cannot_manage_their_own_201_file(): void
    {
        $hr = $this->userWith(['employee_documents.view', 'employee_documents.manage']);
        $own = Employee::factory()->for($hr)->create();

        $this->actingAs($hr)->post("/admin/hr/employees/{$own->id}/documents", [
            'document_type' => 'diploma',
            'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_the_owner_and_hr_can_open_a_document_and_hr_access_is_logged(): void
    {
        $document = $this->document();
        $owner = $document->employee->user;
        $hr = $this->userWith(['employee_documents.view']);

        $this->actingAs($owner)->get("/hr/documents/{$document->id}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseMissing('audit_logs', ['event' => 'employee_document.viewed']);

        $this->actingAs($hr)->get("/hr/documents/{$document->id}/download")
            ->assertOk()
            ->assertDownload('Diploma - 2010-04-01.pdf');
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee_document.viewed', 'actor_id' => $hr->id, 'target_id' => $document->id]);
    }

    public function test_supervisors_and_others_cannot_open_documents(): void
    {
        $office = OrganizationalUnit::factory()->create();
        $document = $this->document(['organizational_unit_id' => $office->id]);
        $supervisor = $this->userWith(['employees.view_unit'], ['organizational_unit_id' => $office->id]);

        foreach ([$supervisor, User::factory()->create()] as $outsider) {
            $this->actingAs($outsider)->get("/hr/documents/{$document->id}")->assertForbidden();
            $this->actingAs($outsider)->get("/hr/documents/{$document->id}/download")->assertForbidden();
            $this->actingAs($outsider)->get("/admin/hr/employees/{$document->employee_id}/documents")->assertForbidden();
        }
    }

    public function test_documents_are_archived_with_a_reason_and_can_be_restored_never_deleted(): void
    {
        $document = $this->document();
        $owner = $document->employee->user;
        $hr = $this->userWith(['employee_documents.view', 'employee_documents.manage']);
        $base = "/admin/hr/employees/{$document->employee_id}/documents/{$document->id}";

        $this->actingAs($hr)->patch("{$base}/archive", [])->assertSessionHasErrors('reason');
        $this->actingAs($hr)->patch("{$base}/archive", ['reason' => 'Wrong person'])->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertTrue($document->isArchived());
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee_document.archived', 'target_id' => $document->id]);

        // Hidden from the owner, kept for HR.
        $this->actingAs($owner)->get("/hr/documents/{$document->id}")->assertForbidden();
        $this->actingAs($owner)->get('/my/201-file')->assertInertia(fn (Assert $page) => $page->has('documents', 0));
        $this->actingAs($hr)->get("/admin/hr/employees/{$document->employee_id}/documents")
            ->assertInertia(fn (Assert $page) => $page->where('documents.0.archive_reason', 'Wrong person'));

        $this->actingAs($hr)->patch("{$base}/restore")->assertSessionHasNoErrors();
        $this->assertFalse($document->fresh()->isArchived());
        $this->actingAs($owner)->get("/hr/documents/{$document->id}")->assertOk();
    }

    public function test_a_document_only_resolves_under_its_own_employee(): void
    {
        $document = $this->document();
        $someoneElse = Employee::factory()->create();
        $hr = $this->userWith(['employee_documents.view', 'employee_documents.manage']);

        $this->actingAs($hr)->patch("/admin/hr/employees/{$someoneElse->id}/documents/{$document->id}/archive", ['reason' => 'x'])
            ->assertNotFound();
    }

    public function test_my_201_file_lists_my_documents_and_verified_pds(): void
    {
        $document = $this->document();
        $owner = $document->employee->user;

        $this->actingAs($owner)->get('/my/201-file')->assertInertia(fn (Assert $page) => $page
            ->component('Portal/My201File')
            ->where('hasRecord', true)
            ->has('documents', 1)
            ->where('documents.0.type_label', 'Diploma')
            ->missing('documents.0.archive_reason')
            ->has('pdsHistory', 0));

        $this->actingAs(User::factory()->create())->get('/my/201-file')
            ->assertInertia(fn (Assert $page) => $page->where('hasRecord', false));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $user
     */
    private function document(array $user = []): EmployeeDocument
    {
        $employee = Employee::factory()->for(User::factory()->state($user))->create();
        $uploader = $this->userWith(['employee_documents.manage']);

        $this->actingAs($uploader)->post("/admin/hr/employees/{$employee->id}/documents", [
            'document_type' => 'diploma',
            'document_date' => '2010-04-01',
            'file' => UploadedFile::fake()->create('diploma.pdf', 50, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        return EmployeeDocument::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<int, string>  $permissions
     * @param  array<string, mixed>  $attributes
     */
    private function userWith(array $permissions, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }

        $user->roles()->attach($role);

        return $user;
    }
}
