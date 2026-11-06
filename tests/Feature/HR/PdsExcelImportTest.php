<?php

namespace Tests\Feature\HR;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Pds\PdsEligibility;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\Pds\PdsOtherInformation;
use App\Models\Pds\PdsReference;
use App\Models\Pds\PdsWorkExperience;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

/**
 * HR-2: filling a PDS from the CSC's Excel form — upload, preview, then
 * confirm. The workbook here is built in the test with just the cells the
 * importer reads, laid out like the official CS Form No. 212 (Revised 2026).
 */
class PdsExcelImportTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'pds').'.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_uploading_the_form_shows_a_preview_and_saves_nothing(): void
    {
        [$employee, $hr] = $this->setUpEmployee();
        $this->workbook();

        $response = $this->actingAs($hr)->post("/admin/hr/employees/{$employee->id}/pds/import", ['file' => $this->upload()]);

        $response->assertSessionHas('pds_import');
        $preview = session('pds_import');
        $sections = collect($preview['sections'])->keyBy('key');

        $this->assertSame(1, $sections['work_experience']['count']);
        $this->assertSame(1, $sections['eligibility']['count']);
        $this->assertSame(1, $sections['references']['count']);
        $this->assertSame(1, $sections['skills']['count']);
        $this->assertSame([], $sections['work_experience']['errors']);
        $this->assertSame(0, PdsWorkExperience::query()->count());
    }

    public function test_confirming_saves_the_chosen_sections_and_replaces_their_rows(): void
    {
        [$employee, $hr] = $this->setUpEmployee();
        PdsWorkExperience::query()->forceCreate(['employee_id' => $employee->id, 'date_from' => '2010-01-01', 'position_title' => 'OLD', 'agency' => 'OLD', 'sort_order' => 0]);
        $this->workbook();

        $this->actingAs($hr)->post("/admin/hr/employees/{$employee->id}/pds/import", ['file' => $this->upload()]);
        $token = session('pds_import')['token'];

        $this->actingAs($hr)->post("/admin/hr/employees/{$employee->id}/pds/import/apply", ['token' => $token, 'sections' => ['work_experience', 'eligibility', 'skills']])
            ->assertSessionHas('status');

        $work = PdsWorkExperience::query()->where('employee_id', $employee->id)->get();
        $this->assertCount(1, $work);
        $this->assertSame('CLERK', $work->first()->position_title);
        $this->assertNull($work->first()->date_to);
        $this->assertSame('2020-10-19', $work->first()->date_from->toDateString());
        $this->assertSame(1, PdsEligibility::query()->count());
        $this->assertSame('COOKING', PdsOtherInformation::query()->where('type', 'skill')->value('detail'));
        // Sections that weren't chosen stay untouched.
        $this->assertSame(0, PdsReference::query()->count());
    }

    public function test_trainings_are_added_to_the_ld_log_as_pending_and_never_duplicated(): void
    {
        [$employee, $hr] = $this->setUpEmployee();
        $this->workbook();

        foreach ([1, 2] as $round) {
            $this->actingAs($hr)->post("/admin/hr/employees/{$employee->id}/pds/import", ['file' => $this->upload()]);
            $this->actingAs($hr)->post("/admin/hr/employees/{$employee->id}/pds/import/apply", ['token' => session('pds_import')['token'], 'sections' => ['learning_development']]);
        }

        $trainings = PdsLearningDevelopment::query()->where('employee_id', $employee->id)->get();
        $this->assertCount(1, $trainings);
        $this->assertSame('A TRAINING', $trainings->first()->title);
        $this->assertSame(8.0, (float) $trainings->first()->hours);
        $this->assertSame('pending', $trainings->first()->approval_status->value);
        $this->assertSame($hr->id, $trainings->first()->created_by);
    }

    public function test_trainings_already_logged_are_left_out_even_when_typed_differently(): void
    {
        [$employee, $hr] = $this->setUpEmployee();
        $this->workbook();

        // The workbook's training starts 2023-03-15, 8 hours, "A TRAINING".
        $log = fn (string $title, string $to = '2023-03-15', float $hours = 8) => PdsLearningDevelopment::query()->forceCreate([
            'employee_id' => $employee->id, 'title' => $title, 'date_from' => '2023-03-15', 'date_to' => $to, 'hours' => $hours,
            'ld_type' => 'technical', 'modality' => 'other', 'sponsor' => 'CSC', 'approval_status' => 'approved', 'sort_order' => 0,
        ]);
        $count = function () use ($employee, $hr) {
            $this->actingAs($hr)->post("/admin/hr/employees/{$employee->id}/pds/import", ['file' => $this->upload()]);

            return collect(session('pds_import')['sections'])->firstWhere('key', 'learning_development');
        };

        $this->assertSame(1, $count()['count']);

        // Same title apart from case and punctuation.
        $same = $log('a  training.');
        $section = $count();
        $this->assertSame(0, $section['count']);
        $this->assertSame('a  training.', $section['skipped_items'][0]['matches']);
        $same->delete();

        // Different wording, same dates and hours, mostly shared words.
        $similar = $log('Advanced Training Seminar');
        $this->assertSame(0, $count()['count']);
        $similar->delete();

        // Same wording-ish but a different length of training is a different training.
        $log('Advanced Training Seminar', hours: 16);
        $this->assertSame(1, $count()['count']);
    }

    public function test_the_audit_log_names_sections_but_never_values(): void
    {
        [$employee, $hr] = $this->setUpEmployee();
        $this->workbook();

        $this->actingAs($hr)->post("/admin/hr/employees/{$employee->id}/pds/import", ['file' => $this->upload()]);
        $this->actingAs($hr)->post("/admin/hr/employees/{$employee->id}/pds/import/apply", ['token' => session('pds_import')['token'], 'sections' => ['work_experience']]);

        $entry = AuditLog::query()->where('description', 'like', 'Imported sections%')->firstOrFail();
        $this->assertStringNotContainsString('CLERK', json_encode($entry->toArray()));
        $this->assertSame(['work_experience'], $entry->metadata['sections']);
    }

    public function test_a_section_with_errors_is_never_written(): void
    {
        [$employee, $hr] = $this->setUpEmployee();
        // A job with no agency breaks the section's rules.
        $this->workbook(['C2' => [18 => ['G' => '']]]);

        $this->actingAs($hr)->post("/admin/hr/employees/{$employee->id}/pds/import", ['file' => $this->upload()]);
        $work = collect(session('pds_import')['sections'])->firstWhere('key', 'work_experience');
        $this->assertNotEmpty($work['errors']);

        $this->actingAs($hr)->post("/admin/hr/employees/{$employee->id}/pds/import/apply", ['token' => session('pds_import')['token'], 'sections' => ['work_experience']]);

        $this->assertSame(0, PdsWorkExperience::query()->count());
    }

    public function test_a_workbook_for_someone_else_is_flagged(): void
    {
        [$employee, $hr] = $this->setUpEmployee();
        $this->workbook(['C1' => [10 => ['D' => 'SOMEONE'], 11 => ['D' => 'ELSE']]]);

        $this->actingAs($hr)->post("/admin/hr/employees/{$employee->id}/pds/import", ['file' => $this->upload()]);

        $this->assertStringContainsString('belongs to', implode(' ', session('pds_import')['warnings']));
    }

    public function test_other_files_are_rejected(): void
    {
        [$employee, $hr] = $this->setUpEmployee();
        $this->workbook(['C1' => [10 => ['B' => 'NOT THE FORM']]]);

        $this->actingAs($hr)->post("/admin/hr/employees/{$employee->id}/pds/import", ['file' => $this->upload()])
            ->assertSessionHasErrors('file');

        $this->actingAs($hr)->post("/admin/hr/employees/{$employee->id}/pds/import", ['file' => UploadedFile::fake()->create('pds.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('file');
    }

    public function test_it_needs_permission_and_an_unexpired_preview(): void
    {
        [$employee, $hr] = $this->setUpEmployee();
        $this->workbook();

        $this->actingAs(User::factory()->create())->post("/admin/hr/employees/{$employee->id}/pds/import", ['file' => $this->upload()])->assertForbidden();

        $this->actingAs($hr)->post("/admin/hr/employees/{$employee->id}/pds/import/apply", ['token' => str_repeat('a', 40), 'sections' => ['work_experience']])
            ->assertSessionHas('error');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * @return array{0: Employee, 1: User}
     */
    private function setUpEmployee(): array
    {
        $employee = Employee::factory()->for(User::factory()->state(['name' => 'Juan Dela Cruz']))->create();

        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'pds.update'], ['display_name' => 'pds.update']));
        $hr = User::factory()->create();
        $hr->roles()->attach($role);

        return [$employee, $hr];
    }

    private function upload(): UploadedFile
    {
        return new UploadedFile($this->file, 'pds.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * Writes a minimal CS Form 212 workbook; `$override` replaces cells
     * (sheet => row => column => value; an empty value removes the cell).
     *
     * @param  array<string, array<int, array<string, string>>>  $override
     */
    private function workbook(array $override = []): void
    {
        $sheets = [
            'C1' => [
                10 => ['B' => 'SURNAME', 'D' => 'DELA CRUZ'],
                11 => ['B' => 'FIRST NAME', 'D' => 'JUAN'],
                15 => ['D' => 'TACLOBAN CITY'],
                22 => ['B' => 'HEIGHT (m)', 'D' => '1.7'],
                24 => ['D' => '65'],
                25 => ['D' => 'O+'],
            ],
            'C2' => [
                4 => ['L' => 'NUMBER'],
                5 => ['A' => 'CAREER SERVICE PROFESSIONAL', 'F' => '80.5', 'G' => '14/05/2021', 'I' => 'CSC REGIONAL OFFICE'],
                12 => ['A' => '(Continue on sheet C9 if necessary)'],
                17 => ['A' => 'From', 'C' => 'To'],
                18 => ['A' => '19/10/2020', 'C' => 'PRESENT', 'D' => 'CLERK', 'G' => 'CSC REGIONAL OFFICE', 'J' => '20000', 'K' => '05-1', 'L' => 'PERMANENT', 'M' => 'Y'],
                42 => ['A' => '(Continue on sheet C6 if necessary)'],
            ],
            'C3' => [
                4 => ['E' => 'From', 'F' => 'To'],
                5 => ['A' => 'A TRAINING', 'E' => '45000', 'F' => '45000', 'G' => '8 HRS', 'H' => 'TECHNICAL', 'I' => 'CSC REGIONAL OFFICE'],
                22 => ['A' => '(Continue on sheet C5 if necessary)'],
                26 => ['E' => 'From', 'F' => 'To'],
                27 => ['A' => 'N/A', 'E' => 'N/A'],
                36 => ['A' => '(Continue on sheet C10 if necessary)'],
                38 => ['B' => 'SPECIAL SKILLS and HOBBIES'],
                39 => ['A' => 'COOKING', 'C' => 'BEST COOK AWARD'],
                46 => ['A' => '(Continue on sheet C11 if necessary)'],
            ],
            'C4' => [
                2 => ['A' => '34.'],
                3 => ['H' => '0', 'J' => '1'], 8 => ['H' => '0', 'J' => '1'], 13 => ['H' => '0', 'J' => '1'], 18 => ['H' => '0', 'J' => '1'],
                23 => ['H' => '0', 'J' => '1'], 27 => ['H' => '1', 'J' => '0'], 29 => ['I' => 'END OF CONTRACT'], 31 => ['H' => '0', 'J' => '1'],
                34 => ['H' => '0', 'J' => '1'], 37 => ['H' => '0', 'J' => '1'], 43 => ['H' => '0', 'J' => '1'], 45 => ['H' => '0', 'J' => '1'],
                47 => ['H' => '0', 'J' => '1'],
                51 => ['A' => 'NAME', 'G' => 'OFFICE / RESIDENTIAL ADDRESS'],
                52 => ['A' => 'REF ONE', 'G' => 'CSC REGIONAL OFFICE', 'H' => '09171234567'],
                55 => ['A' => '42.'],
            ],
        ];

        foreach ($override as $sheet => $rows) {
            foreach ($rows as $row => $cells) {
                $sheets[$sheet][$row] = array_filter([...($sheets[$sheet][$row] ?? []), ...$cells], fn ($value) => $value !== '');
            }
        }

        $zip = new ZipArchive;
        $zip->open($this->file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');

        $names = '';
        $rels = '';
        $index = 1;
        foreach ($sheets as $name => $rows) {
            $names .= "<sheet name=\"{$name}\" sheetId=\"{$index}\" r:id=\"rId{$index}\"/>";
            $rels .= "<Relationship Id=\"rId{$index}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$index}.xml\"/>";

            $xml = '';
            ksort($rows);
            foreach ($rows as $number => $cells) {
                $xml .= "<row r=\"{$number}\">";
                foreach ($cells as $column => $value) {
                    $xml .= "<c r=\"{$column}{$number}\" t=\"inlineStr\"><is><t>".htmlspecialchars($value, ENT_XML1).'</t></is></c>';
                }
                $xml .= '</row>';
            }
            $zip->addFromString("xl/worksheets/sheet{$index}.xml", "<worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\"><sheetData>{$xml}</sheetData></worksheet>");
            $index++;
        }

        $zip->addFromString('xl/workbook.xml', "<workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets>{$names}</sheets></workbook>");
        $zip->addFromString('xl/_rels/workbook.xml.rels', "<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">{$rels}</Relationships>");
        $zip->close();
    }
}
