<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\HR\Pds\PdsForm;
use App\Support\HR\Pds\PdsFormWorkbook;
use App\Support\HR\Pds\PdsWorkbookReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The PDS on CS Form No. 212 (Revised 2026): the entries placed in the
 * form's cells, continuation sheets for long lists, and the Excel copy on
 * CSC's own workbook (read back with the importer to prove every entry
 * lands where the form keeps it).
 */
class PdsFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_entries_go_in_the_forms_cells_and_long_lists_continue_on_their_sheets(): void
    {
        $pages = app(PdsForm::class)->pages($this->pds(), null);

        $this->assertSame(['C1', 'C2', 'C3', 'C4', 'C6', 'C6', 'C7', 'C8', 'C11'], array_column($pages, 'sheet'));

        $c1 = collect($pages[0]['cells'])->pluck('text', 'at');
        $this->assertSame('DELA CRUZ', $c1['D10:N10']);
        $this->assertSame('N/A', $c1['N11']);
        $this->assertSame('15/03/1990', $c1['D13:F14']);
        $this->assertSame('Japan', $c1['J16:M16']);
        $this->assertEqualsCanonicalizing(['D16', 'E17', 'K13', 'M14'], $pages[0]['checks']);

        // Work experience: 24 rows on C2, then 33 per C6 page.
        $c2 = collect($pages[1]['cells'])->pluck('text', 'at');
        $this->assertSame('POSITION 1', $c2['D18:F18']);
        $this->assertSame('PRESENT', $c2['C18']);
        $this->assertSame('₱25,000.00', $c2['J18']);
        $this->assertSame('POSITION 25', collect($pages[4]['cells'])->pluck('text', 'at')['D7:F7']);
        $this->assertSame('POSITION 58', collect($pages[5]['cells'])->pluck('text', 'at')['D7:F7']);

        // A second college goes to C8, labelled with its level.
        $this->assertSame('COLLEGE', collect($pages[7]['cells'])->pluck('text', 'at')['A6:C6']);

        // Question 35.b: Yes, with its date filed; the others No.
        $this->assertContains('H18', $pages[3]['checks']);
        $this->assertContains('J13', $pages[3]['checks']);
        $this->assertSame('01/02/2020', collect($pages[3]['cells'])->pluck('text', 'at')['L20:M20']);

        // Unsigned: no signature on any page.
        $this->assertFalse(collect($pages)->flatMap(fn ($page) => $page['cells'])->contains('kind', 'signature'));
    }

    public function test_it_follows_the_csc_guide_no_blanks_current_work_first_and_number_formats(): void
    {
        $pds = $this->pds();
        $pds['sections']['work_experience'] = [
            ['date_from' => '2010-06-01', 'date_to' => '2015-05-31', 'position_title' => 'CLERK', 'agency' => 'Civil Service Commission', 'monthly_salary' => 9000, 'salary_grade' => '3-1', 'is_government' => true],
            ['date_from' => '2015-06-01', 'date_to' => null, 'position_title' => 'ADMINISTRATIVE OFFICER', 'agency' => 'Civil Service Commission', 'monthly_salary' => 21877, 'salary_grade' => '9-2', 'is_government' => true],
        ];

        $pages = app(PdsForm::class)->pages($pds, null);
        $c1 = collect($pages[0]['cells'])->pluck('text', 'at');
        $c2 = collect($pages[1]['cells'])->pluck('text', 'at');
        $c3 = collect($pages[2]['cells'])->pluck('text', 'at');
        $c4 = collect($pages[3]['cells'])->pluck('text', 'at');

        // "Do not leave blank entries. Put N/A if not applicable."
        $this->assertSame('N/A', $c1['D27:F28']);
        $this->assertSame('N/A', $c1['D38:H38']);
        $this->assertSame('N/A', $c1['D56:F56']);
        $this->assertSame('N/A', $c2['L5']);
        $this->assertSame('N/A', $c3['A27:D27']);
        $this->assertSame('N/A', $c3['C39:H39']);
        // "If YES, give details": a No leaves its line blank, not N/A.
        $this->assertArrayNotHasKey('I15:M15', $c4);
        $this->assertArrayNotHasKey('I25:M25', $c4);
        $this->assertArrayNotHasKey('L19:M19', collect(app(PdsForm::class)->pages(['identity' => [], 'sections' => []], null)[3]['cells'])->pluck('text', 'at'));

        // Work experience starts from the current position; salary in figures, grade as "00-0".
        $this->assertSame('ADMINISTRATIVE OFFICER', $c2['D18:F18']);
        $this->assertSame('₱21,877.00', $c2['J18']);
        $this->assertSame('09-2', $c2['K18']);
        $this->assertSame('CLERK', $c2['D19:F19']);
    }

    public function test_a_signed_pds_carries_the_signature_on_every_page(): void
    {
        $pages = app(PdsForm::class)->pages($this->pds(), ['name' => 'Juan Dela Cruz', 'signed_at' => '2026-11-20T09:00:00+08:00']);

        foreach ($pages as $page) {
            [$sign, $date] = PdsForm::SIGNATURE[$page['sheet']];
            $cells = collect($page['cells'])->pluck('text', 'at');
            $this->assertSame('Electronically signed through IMIS by Juan Dela Cruz', $cells[$sign], $page['sheet']);
            $this->assertSame('20/11/2026', $cells[$date], $page['sheet']);
        }
    }

    public function test_the_excel_copy_reads_back_as_the_same_pds(): void
    {
        $pds = $this->pds();
        $file = tempnam(sys_get_temp_dir(), 'pds').'.xlsx';
        file_put_contents($file, app(PdsFormWorkbook::class)->build(app(PdsForm::class)->pages($pds, null)));

        try {
            $read = app(PdsWorkbookReader::class)->read($file);
        } finally {
            @unlink($file);
        }
        $sections = $read['sections'];

        $this->assertSame(['surname' => 'DELA CRUZ', 'first_name' => 'JUAN'], $read['identity']);
        $this->assertSame('married', $sections['personal']['civil_status']);
        $this->assertSame('dual', $sections['personal']['citizenship']);
        $this->assertSame('naturalization', $sections['personal']['dual_citizenship_by']);
        $this->assertSame('Japan', $sections['personal']['dual_citizenship_country']);
        $this->assertSame('Tacloban City', $sections['personal']['residential_city']);
        $this->assertTrue($sections['personal']['permanent_same_as_residential']);

        $this->assertCount(15, $sections['children']['rows']);
        $this->assertSame('CHILD 15', $sections['children']['rows'][14]['full_name']);
        $this->assertSame('2015-06-01', $sections['children']['rows'][14]['date_of_birth']);

        // 60 positions: 24 on C2 and 36 on C6, which grew past its 33 rows.
        $work = $sections['work_experience']['rows'];
        $this->assertCount(60, $work);
        $this->assertSame('POSITION 60', $work[59]['position_title']);
        $this->assertNull($work[0]['date_to']);
        $this->assertEquals(25000, $work[0]['monthly_salary']);

        $this->assertSame(['elementary', 'college', 'college'], array_column($sections['education']['rows'], 'level'));
        $this->assertCount(9, $sections['skills']['rows']);
        $this->assertTrue($sections['questions']['q35b']);
        $this->assertFalse($sections['questions']['q35a']);
        $this->assertSame('2020-02-01', $sections['questions']['q35b_date_filed']);
        $this->assertSame('Pending', $sections['questions']['q35b_case_status']);
        $this->assertSame('Maria Santos', $sections['references']['rows'][0]['name']);
        $this->assertSame('Passport', $sections['government_id']['government_id_type']);
    }

    public function test_employees_and_hr_download_the_excel_copy_and_hr_downloads_are_audited(): void
    {
        Storage::fake('local');
        $employee = Employee::factory()->for(User::factory())->create();

        $this->actingAs($employee->user)->get('/my/pds/print')
            ->assertInertia(fn (Assert $page) => $page->component('HR/PdsPrint')->where('pages.0.sheet', 'C1')->where('excelUrl', '/my/pds/print.xlsx'));
        $this->actingAs($employee->user)->get('/my/pds/print.xlsx')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertDatabaseMissing('audit_logs', ['event' => 'pds.printed']);

        $hr = $this->userWith(['pds.view']);
        $this->actingAs($hr)->get("/admin/hr/employees/{$employee->id}/pds/print.xlsx")->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'pds.printed')->where('actor_id', $hr->id)->count());

        $this->actingAs(User::factory()->create())->get("/admin/hr/employees/{$employee->id}/pds/print.xlsx")->assertForbidden();
    }

    /**
     * @return array{identity: array<string, mixed>, sections: array<string, mixed>}
     */
    private function pds(): array
    {
        $rows = fn (int $count, callable $row) => array_map($row, range(1, $count));

        return [
            'identity' => [
                'surname' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'Santos', 'name_extension' => null,
                'date_of_birth' => '1990-03-15', 'sex_at_birth' => 'male', 'agency_employee_no' => '2020-001',
            ],
            'sections' => [
                'personal' => [
                    'place_of_birth' => 'Tacloban City', 'civil_status' => 'married', 'citizenship' => 'dual',
                    'dual_citizenship_by' => 'naturalization', 'dual_citizenship_country' => 'Japan',
                    'height_m' => 1.7, 'weight_kg' => 65, 'blood_type' => 'O+',
                    'residential_house_no' => '12', 'residential_street' => 'Rizal St.', 'residential_barangay' => 'Barangay 5',
                    'residential_city' => 'Tacloban City', 'residential_province' => 'Leyte', 'residential_zip_code' => '6500',
                    'permanent_same_as_residential' => true, 'mobile_no' => '09171234567', 'email' => 'juan@example.com',
                ],
                'family' => ['spouse_surname' => 'Dela Cruz', 'spouse_first_name' => 'Ana', 'father_surname' => 'Dela Cruz', 'mother_surname' => 'Santos'],
                'children' => $rows(15, fn ($i) => ['full_name' => "CHILD {$i}", 'date_of_birth' => '2015-06-01']),
                'education' => [
                    ['level' => 'elementary', 'school' => 'Tacloban Central School', 'year_graduated' => 2002],
                    ['level' => 'college', 'school' => 'Leyte Normal University', 'degree' => 'BS Education', 'year_graduated' => 2010],
                    ['level' => 'college', 'school' => 'Eastern Visayas State University', 'degree' => 'BS Accountancy'],
                ],
                'eligibility' => [['eligibility' => 'Career Service Professional', 'rating' => '85.5', 'exam_date' => '2011-08-07', 'exam_place' => 'Tacloban City']],
                'work_experience' => $rows(60, fn ($i) => [
                    'date_from' => '2020-01-01', 'date_to' => $i === 1 ? null : '2020-12-31', 'position_title' => "POSITION {$i}",
                    'agency' => 'Civil Service Commission', 'monthly_salary' => 25000, 'salary_grade' => '11-1',
                    'appointment_status' => 'Permanent', 'is_government' => true,
                ]),
                'learning_development' => [['title' => 'Records Management', 'date_from' => '2024-05-01', 'date_to' => '2024-05-03', 'hours' => 24, 'ld_type' => 'Technical', 'sponsor' => 'CSC RO VIII']],
                'voluntary_work' => [],
                'skills' => $rows(9, fn ($i) => ['detail' => "SKILL {$i}"]),
                'distinctions' => [],
                'memberships' => [],
                'questions' => [
                    'q34a' => false, 'q34b' => false, 'q35a' => false, 'q35b' => true, 'q35b_details' => 'Estafa',
                    'q35b_date_filed' => '2020-02-01', 'q35b_case_status' => 'Pending', 'q36' => false, 'q37' => false,
                    'q38a' => false, 'q38b' => false, 'q39' => false, 'q40a' => false, 'q40b' => false, 'q40c' => false,
                ],
                'references' => [['name' => 'Maria Santos', 'address' => 'Tacloban City', 'contact' => '09170000000']],
                'government_id' => ['government_id_type' => 'Passport', 'government_id_number' => 'P1234567', 'government_id_issuance' => '01/01/2020, Manila'],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }

        $user->roles()->attach($role);

        return $user;
    }
}
