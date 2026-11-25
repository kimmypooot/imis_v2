<?php

namespace Tests\Feature\HR;

use App\Models\BiometricPunch;
use App\Models\DtrAdjustment;
use App\Models\DtrSignature;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * The Excel export has the same Single and Double DTR choice as the print:
 * the double workbook lays two identical, narrower forms side by side, with
 * the print's column spans, and prints on one A4 portrait page at 100%.
 */
class DtrExportLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_single_layout_is_the_default_and_stays_one_form(): void
    {
        $employee = Employee::factory()->create(['biometric_id' => '55']);
        BiometricPunch::factory()->create(['biometric_id' => '55', 'punched_at' => '2026-03-02 07:51:00']);

        $zip = $this->workbook($this->actingAs($employee->user)->get('/my/dtr/export?from=2026-03-01&to=2026-03-31'));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');

        $this->assertSame(1, substr_count($sheet, 'DAILY TIME RECORD'));
        $this->assertStringContainsString('<dimension ref="A1:Q53"/>', $sheet);
        $this->assertStringContainsString('scale="97"', $sheet);
        $this->assertStringNotContainsString('Printed by:', $sheet);
    }

    public function test_the_double_layout_puts_two_identical_forms_side_by_side(): void
    {
        $employee = Employee::factory()->create(['biometric_id' => '55']);
        BiometricPunch::factory()->create(['biometric_id' => '55', 'punched_at' => '2026-03-02 07:51:00']);

        $response = $this->actingAs($employee->user)->get('/my/dtr/export?from=2026-03-01&to=2026-03-31&layout=double');
        $this->assertStringContainsString('double.xlsx', $response->headers->get('content-disposition'));
        $zip = $this->workbook($response);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');

        // Two forms of 17 columns and a gap, one more footer row, A4 portrait at 100%.
        $this->assertStringContainsString('<dimension ref="A1:AI54"/>', $sheet);
        $this->assertStringContainsString('fitToWidth="1"', $sheet);
        $this->assertSame(2, substr_count($sheet, 'DAILY TIME RECORD'));
        $this->assertSame(2, substr_count($sheet, 'Civil Service Form No. 48'));
        $this->assertSame(2, substr_count($sheet, mb_strtoupper($employee->user->name)));
        $this->assertSame(2, substr_count($sheet, '7:51AM'));
        $this->assertSame(2, substr_count($sheet, 'Printed by:'));
        $this->assertSame(2, substr_count($sheet, $employee->user->username));

        // The print's spans: a 3-column title, then the second copy 18 columns on.
        $this->assertStringContainsString('<mergeCell ref="A1:C4"/>', $sheet);
        $this->assertStringContainsString('<mergeCell ref="S1:U4"/>', $sheet);
        // MORNING spans 5 columns and AFTERNOON 6 (B9:F9, G9:L9), then Worked Hrs. is two columns wide.
        $this->assertStringContainsString('<mergeCell ref="B9:F9"/>', $sheet);
        $this->assertStringContainsString('<mergeCell ref="G9:L9"/>', $sheet);
        $this->assertStringContainsString('<mergeCell ref="M9:N10"/>', $sheet);
        // The QR cell is six rows tall on the double form.
        $this->assertStringContainsString('<mergeCell ref="A49:D54"/>', $sheet);
    }

    public function test_no_merged_ranges_overlap_in_either_layout(): void
    {
        $employee = Employee::factory()->create(['biometric_id' => '55']);

        foreach (['single', 'double'] as $layout) {
            $sheet = $this->workbook($this->actingAs($employee->user)->get("/my/dtr/export?from=2026-03-01&to=2026-03-31&layout={$layout}"))->getFromName('xl/worksheets/sheet1.xml');
            preg_match_all('/<mergeCell ref="([A-Z]+)(\d+):([A-Z]+)(\d+)"\/>/', $sheet, $matches, PREG_SET_ORDER);
            $taken = [];

            foreach ($matches as [, $c1, $r1, $c2, $r2]) {
                foreach (range($this->column($c1), $this->column($c2)) as $column) {
                    foreach (range((int) $r1, (int) $r2) as $row) {
                        $this->assertArrayNotHasKey("{$column}:{$row}", $taken, "{$layout}: cell {$column}:{$row} is in two merged ranges");
                        $taken["{$column}:{$row}"] = true;
                    }
                }
            }
        }
    }

    public function test_the_double_form_has_the_same_cells_as_the_print_lays_out(): void
    {
        $employee = Employee::factory()->create(['biometric_id' => '55']);

        $sheet = $this->workbook($this->actingAs($employee->user)->get('/my/dtr/export?from=2026-03-01&to=2026-03-31&layout=double'))->getFromName('xl/worksheets/sheet1.xml');
        $widths = [];
        preg_match_all('/<col min="(\d+)" max="\d+" width="([\d.]+)"/', $sheet, $columns, PREG_SET_ORDER);
        foreach ($columns as [, $index, $width]) {
            $widths[(int) $index] = (float) $width;
        }

        // 35 columns: 17, the gap, 17; both forms the same width.
        $this->assertCount(35, $widths);
        $this->assertEqualsWithDelta(array_sum(array_slice($widths, 0, 17)), array_sum(array_slice($widths, 18, 17)), 0.01);
        // The form is 10.15 cm wide (1 cm = 37.795 px, 7 px per width unit).
        $this->assertEqualsWithDelta(10.15 * 37.795 / 7, array_sum(array_slice($widths, 0, 17)), 0.3);
    }

    public function test_the_double_workbook_draws_the_logo_in_both_headers_and_fades_it_behind(): void
    {
        $employee = Employee::factory()->create();

        $zip = $this->workbook($this->actingAs($employee->user)->get('/my/dtr/export?from=2026-03-01&to=2026-03-31&layout=double'));
        $drawing = $zip->getFromName('xl/drawings/drawing1.xml');

        $this->assertSame(2, substr_count($drawing, 'name="Agency logo"'));
        if (function_exists('imagecreatefromstring')) {
            $this->assertStringContainsString('Faded agency logo', $drawing);
            $this->assertNotFalse($zip->locateName('xl/media/watermark.png'));
        }
    }

    public function test_a_signed_double_sheet_has_the_qr_in_both_forms_and_an_unsigned_one_has_none(): void
    {
        $employee = Employee::factory()->create();
        $url = '/my/dtr/export?from=2026-03-01&to=2026-03-31&layout=double';

        $unsigned = $this->workbook($this->actingAs($employee->user)->get($url));
        $this->assertFalse($unsigned->locateName('xl/media/qr1.png'));
        $this->assertStringNotContainsString('QR code', $unsigned->getFromName('xl/drawings/drawing1.xml'));

        DtrSignature::factory()->create(['employee_id' => $employee->id, 'period_from' => '2026-03-01', 'period_to' => '2026-03-31']);
        $signed = $this->workbook($this->actingAs($employee->user)->get($url));

        $this->assertSame("\x89PNG", substr($signed->getFromName('xl/media/qr1.png'), 0, 4));
        $this->assertSame(2, substr_count($signed->getFromName('xl/drawings/drawing1.xml'), 'name="QR code"'));
    }

    public function test_hrd_and_the_office_export_take_the_layout_too(): void
    {
        $employee = Employee::factory()->create();
        $hr = $this->userWith(['dtr.view']);

        $sheet = $this->workbook($this->actingAs($hr)->get("/admin/hr/employees/{$employee->id}/dtr/export?from=2026-03-01&to=2026-03-31&layout=double"))->getFromName('xl/worksheets/sheet1.xml');
        $this->assertSame(2, substr_count($sheet, 'DAILY TIME RECORD'));

        $this->actingAs($hr)->get('/admin/hr/dtr/export?office=regional&month=2026-03&layout=triple')->assertSessionHasErrors('layout');
    }

    public function test_the_hours_of_a_day_being_typed_are_worked_out_by_the_dtr_rules(): void
    {
        Carbon::setTestNow('2026-11-24 09:00:00');
        $this->seed(LeaveTypeSeeder::class);
        $employee = Employee::factory()->create(['biometric_id' => '55']);
        EmployeeWorkSchedule::factory()->create([
            'employee_id' => $employee->id,
            'work_schedule_id' => WorkSchedule::query()->where('name', 'not like', 'Compressed%')->value('id'),
            'valid_from' => '2026-01-01',
        ]);

        $response = $this->actingAs($employee->user)->postJson('/my/dtr/preview', ['entries' => [
            ['date' => '2026-11-10', 'time_in' => '08:00', 'time_out' => '17:00'],
            ['date' => '2026-11-11', 'time_in' => '09:30', 'time_out' => '17:00'],
            ['date' => '2026-11-12', 'time_in' => null, 'time_out' => null],
        ]])->assertOk();

        $days = $response->json('days');
        $this->assertSame(480, $days['2026-11-10']['minutes']);
        $this->assertSame(0, $days['2026-11-10']['late_minutes']);
        $this->assertGreaterThan(0, $days['2026-11-11']['late_minutes']);
        $this->assertLessThan(480, $days['2026-11-11']['minutes']);
        $this->assertSame('OK', $days['2026-11-10']['remark']);
        $this->assertSame('Late', $days['2026-11-11']['remark']);
        $this->assertArrayNotHasKey('2026-11-12', $days);
        $this->assertSame(0, DtrAdjustment::query()->count());
    }

    public function test_a_day_hrd_adjusted_is_not_previewed(): void
    {
        Carbon::setTestNow('2026-11-24 09:00:00');
        $employee = Employee::factory()->create(['biometric_id' => '55']);
        DtrAdjustment::query()->create(['employee_id' => $employee->id, 'date' => '2026-11-10', 'kind' => 'credit', 'remark' => 'Event', 'entered_by_employee' => false, 'created_by' => $employee->user_id]);

        $days = $this->actingAs($employee->user)->postJson('/my/dtr/preview', ['entries' => [['date' => '2026-11-10', 'time_in' => '08:00', 'time_out' => '17:00']]])->json('days');

        $this->assertSame([], $days);
    }

    public function test_your_own_export_opens_once_the_period_has_ended(): void
    {
        Carbon::setTestNow('2026-11-24 09:00:00');
        $employee = Employee::factory()->create();

        $this->actingAs($employee->user)->get('/my/dtr/export?from=2026-11-01&to=2026-11-30')->assertForbidden();
        $this->actingAs($employee->user)->get('/my/dtr/export?from=2026-10-01&to=2026-10-31')->assertOk();
    }

    public function test_the_export_opens_the_day_after_the_last_day(): void
    {
        Carbon::setTestNow('2026-12-01 00:30:00');
        $employee = Employee::factory()->create();

        $this->actingAs($employee->user)->get('/my/dtr/export?from=2026-11-01&to=2026-11-30')->assertOk();
    }

    public function test_your_own_print_opens_once_the_period_has_ended_too(): void
    {
        Carbon::setTestNow('2026-11-24 09:00:00');
        $employee = Employee::factory()->create();

        $this->actingAs($employee->user)->get('/my/dtr/print?from=2026-11-01&to=2026-11-30')->assertForbidden();
        $this->actingAs($employee->user)->get('/my/dtr/print?from=2026-10-01&to=2026-10-31')->assertOk();
    }

    public function test_hrd_can_still_export_a_period_that_is_running(): void
    {
        Carbon::setTestNow('2026-11-24 09:00:00');
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWith(['dtr.view']))->get("/admin/hr/employees/{$employee->id}/dtr/export?from=2026-11-01&to=2026-11-30")->assertOk();
    }

    private function column(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $letter) {
            $n = $n * 26 + (ord($letter) - 64);
        }

        return $n;
    }

    private function workbook(TestResponse $response): ZipArchive
    {
        $response->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'dtrtest');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

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
