<?php

namespace Tests\Feature\HR;

use App\Enums\AppointmentNature;
use App\Enums\AuditEvent;
use App\Enums\EmploymentStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * `imis:import-plantilla`: updating IMIS from the HR Division's certified
 * Plantilla of Personnel workbook. The workbooks here are built in the
 * test with the same layout (title rows, a two-row heading, office
 * headings, one row per item, a summary table below), with made-up people.
 */
class ImportPlantillaTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $msd;

    private OrganizationalUnit $hrd;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-04 09:00:00');
        $this->msd = OrganizationalUnit::factory()->create(['name' => 'Management Services Division', 'short_name' => 'MSD']);
        $this->hrd = OrganizationalUnit::factory()->create(['name' => 'Human Resource Division', 'short_name' => 'HRD']);
        Position::query()->create(['title' => 'Administrative Officer IV', 'salary_grade' => 15]);
        Position::query()->create(['title' => 'Senior Human Resource Specialist', 'salary_grade' => 19]);
        $this->file = tempnam(sys_get_temp_dir(), 'plantilla').'.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_it_previews_without_saving_then_applies_items_appointments_and_staff_details(): void
    {
        $holder = $this->holder('Santos', 'Atty. Maria', 'Senior Human Resource Specialist', $this->hrd);
        $holder->user->forceFill(['middle_name' => null, 'sex' => 'male', 'birthday' => '2000-01-01'])->save();

        $this->workbook([
            ['B' => '29.0001 MANAGEMENT SERVICES DIVISION (MSD)'],
            // Promoted: the start date is the assumption date in the cell.
            ['A' => 'SRPS-1-2005', 'B' => 'SENIOR PS (SENIOR HRS)', 'C' => '19', 'F' => '2', 'H' => 'SANTOS', 'I' => 'MARIA', 'J' => 'DE LA CRUZ',
                'K' => 'F', 'L' => '31428', 'N' => '42738', 'O' => '6/22/2026 (A: 7/15/2026)', 'P' => 'P'],
            ['A' => 'ADOF4-9-2005', 'B' => 'ADMIN. OFFICER IV (AO II)', 'C' => '15', 'H' => 'VACANT'],
        ]);

        $this->artisan('imis:import-plantilla', ['file' => $this->file])
            ->expectsOutputToContain('Nothing was saved')
            ->assertSuccessful();
        $this->assertNull($holder->user->fresh()->middle_name);
        $this->assertSame(1, PlantillaItem::query()->count());

        $this->artisan('imis:import-plantilla', ['file' => $this->file, '--apply' => true])->assertSuccessful();

        $item = PlantillaItem::query()->where('item_number', 'SRPS-1-2005')->firstOrFail();
        $this->assertSame('Senior Human Resource Specialist', $item->position_title, 'IMIS keeps its own title');
        $this->assertSame($this->msd->id, $item->organizational_unit_id);

        $period = $item->incumbency;
        $this->assertSame(2, $period->step);
        $this->assertSame(EmploymentStatus::Permanent, $period->status);
        $this->assertSame(AppointmentNature::Promotion, $period->nature);
        $this->assertSame('2026-07-15', $period->date_from->toDateString());

        $user = $holder->user->fresh();
        $this->assertSame('female', $user->sex);
        $this->assertSame('1986-01-16', $user->birthday->toDateString());
        $this->assertSame('De La Cruz', $user->middle_name);
        $this->assertSame('2017-01-03', $holder->fresh()->original_appointment_date->toDateString());

        // The vacant item is created under its IMIS position, in the office it's listed under.
        $vacant = PlantillaItem::query()->where('item_number', 'ADOF4-9-2005')->firstOrFail();
        $this->assertSame('Administrative Officer IV', $vacant->position_title);
        $this->assertSame($this->msd->id, $vacant->organizational_unit_id);
        $this->assertNull($vacant->incumbency);

        $this->assertTrue(AuditLog::query()->where('event', AuditEvent::PlantillaImported->value)->exists());

        // Re-running finds nothing left to change.
        $this->artisan('imis:import-plantilla', ['file' => $this->file])
            ->expectsOutputToContain('0 with changes to save')
            ->assertSuccessful();
    }

    public function test_without_a_promotion_the_period_is_the_original_appointment(): void
    {
        $holder = $this->holder('Reyes', 'Juan', 'Administrative Officer IV', $this->msd);

        $this->workbook([
            ['B' => '29.0001 MANAGEMENT SERVICES DIVISION (MSD)'],
            ['A' => 'ADOF4-1-2005', 'B' => 'ADMIN. OFFICER IV', 'C' => '15', 'F' => '1', 'H' => 'REYES', 'I' => 'JUAN', 'N' => '45418', 'O' => '-', 'P' => 'P'],
        ]);

        $this->artisan('imis:import-plantilla', ['file' => $this->file, '--apply' => true])->assertSuccessful();

        $period = PlantillaAppointment::query()->where('employee_id', $holder->id)->sole();
        $this->assertSame(AppointmentNature::Original, $period->nature);
        $this->assertSame('2024-05-06', $period->date_from->toDateString());
    }

    public function test_unusable_values_are_left_as_is_and_a_different_position_gets_no_plantilla_changes(): void
    {
        $promoted = $this->holder('Cruz', 'Ana', 'Senior Human Resource Specialist', $this->hrd);
        $future = $this->holder('Lim', 'Ben', 'Administrative Officer IV', $this->hrd);

        $this->workbook([
            ['B' => '29.0004 HUMAN RESOURCE DIVISION (HRD)'],
            // Holds a Senior HRS item in IMIS; the sheet lists a different position.
            ['A' => 'ADOF4-2-2005', 'B' => 'ADMIN. OFFICER IV', 'C' => '15', 'H' => 'CRUZ', 'I' => 'ANA', 'K' => 'F', 'N' => '42738', 'O' => '-', 'P' => 'P'],
            // A step past 8 and a promotion dated in the future.
            ['A' => 'ADOF4-3-2005', 'B' => 'ADMIN. OFFICER IV', 'C' => '15', 'F' => '19', 'H' => 'LIM', 'I' => 'BEN', 'N' => '42738', 'O' => '46358', 'P' => 'P'],
            // Not people: summary tables below the plantilla.
            ['A' => '56', 'B' => '55', 'C' => '61'],
        ]);

        $this->artisan('imis:import-plantilla', ['file' => $this->file, '--apply' => true])
            ->expectsOutputToContain('Plantilla details not applied')
            ->expectsOutputToContain('Step "19" isn\'t 1–8')
            ->expectsOutputToContain('is out of range')
            ->assertSuccessful();

        $item = PlantillaItem::query()->whereHas('appointments', fn ($query) => $query->where('employee_id', $promoted->id))->sole();
        $this->assertNull($item->item_number);
        $this->assertSame('female', $promoted->user->fresh()->sex, 'staff details still apply');
        $this->assertSame('2017-01-03', $promoted->fresh()->original_appointment_date->toDateString());

        $period = PlantillaAppointment::query()->where('employee_id', $future->id)->sole();
        $this->assertSame('ADOF4-3-2005', $period->item->item_number);
        $this->assertNull($period->step);
        $this->assertNull($period->nature, 'an unusable promotion date is not read as no promotion');
        $this->assertNull($period->date_from);
    }

    public function test_a_duplicate_account_without_an_employee_record_is_passed_over_and_named(): void
    {
        $holder = $this->holder('Uy', 'Rey', 'Senior Human Resource Specialist', $this->hrd);
        $duplicate = User::factory()->create(['first_name' => 'Rey', 'last_name' => 'Uy', 'username' => 'ruy2']);

        $this->workbook([
            ['B' => '29.0004 HUMAN RESOURCE DIVISION (HRD)'],
            ['A' => 'SRPS-5-2005', 'B' => 'SENIOR PS (SENIOR HRS)', 'C' => '19', 'F' => '1', 'H' => 'UY', 'I' => 'REY', 'P' => 'P'],
        ]);

        $this->artisan('imis:import-plantilla', ['file' => $this->file, '--apply' => true])
            ->expectsOutputToContain("#{$duplicate->id} (ruy2)")
            ->assertSuccessful();

        $this->assertSame('SRPS-5-2005', PlantillaAppointment::query()->where('employee_id', $holder->id)->sole()->item->item_number);
    }

    public function test_a_workbook_without_the_plantilla_heading_is_refused(): void
    {
        $this->workbook([['A' => 'Something else', 'B' => 'entirely']], header: false);

        $this->artisan('imis:import-plantilla', ['file' => $this->file])
            ->expectsOutputToContain('No sheet in this workbook has an "ITEM NO." heading.')
            ->assertFailed();
    }

    /**
     * An in-service employee holding an incomplete link to an item of the
     * given title, as `imis:link-plantilla` leaves them.
     */
    private function holder(string $surname, string $firstName, string $title, OrganizationalUnit $office): Employee
    {
        $employee = Employee::factory()->create();
        $employee->user->forceFill(['first_name' => $firstName, 'last_name' => $surname, 'position' => $title])->save();

        $item = PlantillaItem::query()->create(['position_title' => $title, 'organizational_unit_id' => $office->id]);
        (new PlantillaAppointment)->forceFill(['plantilla_item_id' => $item->id, 'employee_id' => $employee->id])->save();

        return $employee;
    }

    /**
     * Writes a minimal .xlsx: title rows, the two-row heading, then the given rows.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    private function workbook(array $rows, bool $header = true): void
    {
        $lines = $header ? [
            1 => ['A' => 'CIVIL SERVICE COMMISSION'],
            6 => ['A' => 'ITEM NO.', 'B' => 'POSITION', 'C' => 'SG', 'H' => 'NAME OF INCUMBENT', 'P' => 'STATUS'],
            7 => ['D' => 'AUTHORIZED', 'H' => 'SURNAME', 'I' => 'FIRST NAME'],
        ] : [];
        $next = 9;
        foreach ($rows as $row) {
            $lines[$next++] = $row;
        }

        $xml = '';
        foreach ($lines as $number => $cells) {
            $xml .= "<row r=\"{$number}\">";
            foreach ($cells as $column => $value) {
                $xml .= ctype_digit($value)
                    ? "<c r=\"{$column}{$number}\"><v>{$value}</v></c>"
                    : "<c r=\"{$column}{$number}\" t=\"inlineStr\"><is><t>".htmlspecialchars($value, ENT_XML1).'</t></is></c>';
            }
            $xml .= '</row>';
        }

        $zip = new ZipArchive;
        $zip->open($this->file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="1Oct2026" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', "<worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\"><sheetData>{$xml}</sheetData></worksheet>");
        $zip->close();
    }
}
