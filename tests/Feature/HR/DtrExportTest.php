<?php

namespace Tests\Feature\HR;

use App\Enums\AuditEvent;
use App\Enums\UnitType;
use App\Models\AuditLog;
use App\Models\BiometricPunch;
use App\Models\DtrSignature;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * CS Form 48 as an Excel workbook: an employee's own, HRD's for anyone, and
 * a whole office for a month.
 */
class DtrExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_employee_can_export_their_own_sheet_as_a_valid_workbook(): void
    {
        $employee = Employee::factory()->create(['biometric_id' => '55']);
        BiometricPunch::factory()->create(['biometric_id' => '55', 'punched_at' => '2026-03-02 07:51:00']);

        $response = $this->actingAs($employee->user)->get('/my/dtr/export?from=2026-03-01&to=2026-03-31');

        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('attachment; filename="CS Form 48', $response->headers->get('content-disposition'));

        $sheet = $this->sheetXml($response, 1);
        $this->assertStringContainsString('Civil Service Form No. 48', $sheet);
        $this->assertStringContainsString(mb_strtoupper($employee->user->name), $sheet);
        $this->assertStringContainsString('7:51AM', $sheet);
        // The same 17-column grid, and no employee audit trail for your own record.
        $this->assertStringContainsString('<mergeCell ref="A1:E4"/>', $sheet);
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::DtrExported->value)->count());
    }

    public function test_a_signed_sheet_carries_the_qr_but_no_signature_image(): void
    {
        $employee = Employee::factory()->create();
        DtrSignature::factory()->create(['employee_id' => $employee->id, 'period_from' => '2026-03-01', 'period_to' => '2026-03-31']);

        $zip = $this->workbook($this->actingAs($employee->user)->get('/my/dtr/export?from=2026-03-01&to=2026-03-31'));

        // The agency logo and the QR of the printed form are drawn: the employee's signature image is never in the file.
        $this->assertNotFalse($zip->locateName('xl/media/logo.png'));
        // The logo, its faded copy behind the form (when GD can make it) and the QR.
        $this->assertSame(function_exists('imagecreatefromstring') ? 3 : 2, substr_count($zip->getFromName('xl/drawings/drawing1.xml'), '<xdr:pic>'));
        $this->assertStringContainsString('Agency logo', $zip->getFromName('xl/drawings/drawing1.xml'));
        $this->assertStringContainsString('name="QR code"', $zip->getFromName('xl/drawings/drawing1.xml'));
        $media = array_filter(array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1)), fn ($name) => str_starts_with($name, 'xl/media/'));
        $media = array_values($media);
        sort($media);
        $this->assertSame(function_exists('imagecreatefromstring') ? ['xl/media/logo.png', 'xl/media/qr1.png', 'xl/media/watermark.png'] : ['xl/media/logo.png', 'xl/media/qr1.png'], $media);
        // The QR is a real PNG, and the code and link are no longer printed as text in its cell.
        $this->assertSame("\x89PNG", substr($zip->getFromName('xl/media/qr1.png'), 0, 4));
        $this->assertStringNotContainsString('/dtr/verify/', $zip->getFromName('xl/worksheets/sheet1.xml'));
    }

    public function test_hrd_can_export_anyones_sheet_and_it_is_audited(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWith(['dtr.view']))->get("/admin/hr/employees/{$employee->id}/dtr/export?from=2026-03-01&to=2026-03-31")->assertOk();

        $this->assertDatabaseHas('audit_logs', ['event' => 'dtr.exported', 'description' => "Exported to Excel the DTR of {$employee->user->name}"]);
        $this->actingAs($this->userWith([]))->get("/admin/hr/employees/{$employee->id}/dtr/export?from=2026-03-01&to=2026-03-31")->assertForbidden();
    }

    public function test_an_office_exports_one_sheet_per_employee_and_the_support_units_are_one_office(): void
    {
        $rsu = OrganizationalUnit::factory()->create(['unit_type' => UnitType::RegionalSupportUnit]);
        $otherRsu = OrganizationalUnit::factory()->create(['unit_type' => UnitType::RegionalSupportUnit]);
        $samar = OrganizationalUnit::factory()->create(['unit_type' => UnitType::FieldOffice, 'name' => 'CSC Field Office - Samar']);
        Employee::factory()->create(['user_id' => User::factory()->create(['organizational_unit_id' => $rsu->id])]);
        Employee::factory()->create(['user_id' => User::factory()->create(['organizational_unit_id' => $otherRsu->id])]);
        $inSamar = Employee::factory()->create(['user_id' => User::factory()->create(['organizational_unit_id' => $samar->id])]);
        $hrd = $this->userWith(['dtr.view']);

        $regional = $this->workbook($this->actingAs($hrd)->get('/admin/hr/dtr/export?office=regional&month=2026-03'));
        $this->assertNotFalse($regional->locateName('xl/worksheets/sheet2.xml'));
        $this->assertFalse($regional->locateName('xl/worksheets/sheet3.xml'));

        $field = $this->workbook($this->actingAs($hrd)->get("/admin/hr/dtr/export?office={$samar->id}&month=2026-03"));
        $this->assertFalse($field->locateName('xl/worksheets/sheet2.xml'));
        $this->assertStringContainsString(mb_strtoupper($inSamar->user->name), $field->getFromName('xl/worksheets/sheet1.xml'));

        $this->assertDatabaseHas('audit_logs', ['event' => 'dtr.exported', 'description' => "Exported the DTR of {$inSamar->user->name} to Excel"]);
        $this->actingAs($this->userWith([]))->get('/admin/hr/dtr/export?office=regional&month=2026-03')->assertForbidden();
    }

    private function sheetXml(TestResponse $response, int $n): string
    {
        return $this->workbook($response)->getFromName("xl/worksheets/sheet{$n}.xml");
    }

    private function workbook(TestResponse $response): ZipArchive
    {
        $response->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'dtrtest');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        // Every part must be well-formed XML, or Excel refuses the file.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_ends_with($zip->getNameIndex($i), '.png')) {
                continue;
            }

            $this->assertNotFalse(simplexml_load_string($zip->getFromIndex($i)), "Part {$zip->getNameIndex($i)} is not valid XML");
        }

        return $zip;
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
