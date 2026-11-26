<?php

namespace Tests\Feature\HR;

use App\Enums\EmploymentStatus;
use App\Enums\UnitType;
use App\Models\AuditLog;
use App\Models\BiometricImport;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HRD uploads a biometric device's .dat log; every punch is stored once.
 */
class BiometricImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_hrd_can_upload_a_log_and_sees_the_counts_and_unmatched_ids(): void
    {
        Employee::factory()->create(['biometric_id' => '906']);

        $this->actingAs($this->userWith(['dtr.manage']))
            ->post('/admin/hr/dtr/import', ['file' => $this->log(3, '906')])
            ->assertRedirect('/admin/hr/dtr/import')
            ->assertSessionHas('status');

        $this->assertSame(3, BiometricPunch::query()->count());
        $this->assertSame(3, BiometricImport::query()->firstOrFail()->rows_new);
    }

    public function test_the_message_says_how_many_ids_belong_to_nobody_yet(): void
    {
        $this->actingAs($this->userWith(['dtr.manage']))
            ->post('/admin/hr/dtr/import', ['file' => $this->log(2, '906')]);

        $this->assertStringContainsString('1 ID', session('status'));
    }

    public function test_the_same_upload_again_reports_zero_new(): void
    {
        $hrd = $this->userWith(['dtr.manage']);

        $this->actingAs($hrd)->post('/admin/hr/dtr/import', ['file' => $this->log(3)]);
        $this->actingAs($hrd)->post('/admin/hr/dtr/import', ['file' => $this->log(3)]);

        $this->assertSame(3, BiometricPunch::query()->count());
        $this->assertSame(0, BiometricImport::query()->latest('id')->firstOrFail()->rows_new);
    }

    public function test_the_upload_is_audited_with_file_name_hash_and_counts(): void
    {
        $this->actingAs($this->userWith(['dtr.manage']))
            ->post('/admin/hr/dtr/import', ['file' => $this->log(2, '906', 'samar.dat')]);

        $audit = AuditLog::query()->where('event', 'biometric_log.imported')->firstOrFail();
        $this->assertSame('samar.dat', $audit->metadata['file']);
        $this->assertSame(64, strlen($audit->metadata['hash']));
        $this->assertSame(2, $audit->metadata['rows_new']);
    }

    public function test_clearing_ids_deletes_only_punches_no_employee_carries(): void
    {
        Employee::factory()->create(['biometric_id' => '906']);
        $hrd = $this->userWith(['dtr.manage']);
        $this->actingAs($hrd)->post('/admin/hr/dtr/import', ['file' => $this->log(2, '906')]);
        $this->actingAs($hrd)->post('/admin/hr/dtr/import', ['file' => $this->log(3, '959')]);

        $this->actingAs($hrd)
            ->delete('/admin/hr/dtr/import/ids', ['ids' => ['906', '959']])
            ->assertRedirect('/admin/hr/dtr/import')
            ->assertSessionHas('status');

        $this->assertSame(['906'], BiometricPunch::query()->pluck('biometric_id')->map(fn ($id) => (string) $id)->unique()->values()->all());
        $this->assertSame(2, BiometricPunch::query()->count());
        $this->assertSame(['959'], AuditLog::query()->where('event', 'biometric_punches.cleared')->firstOrFail()->metadata['ids']);
    }

    public function test_a_user_with_only_dtr_view_cannot_clear_ids(): void
    {
        $this->actingAs($this->userWith(['dtr.manage']))->post('/admin/hr/dtr/import', ['file' => $this->log(1, '959')]);

        $this->actingAs($this->userWith(['dtr.view']))
            ->delete('/admin/hr/dtr/import/ids', ['ids' => ['959']])
            ->assertForbidden();

        $this->assertSame(1, BiometricPunch::query()->count());
    }

    public function test_a_known_only_upload_records_how_many_punches_it_left_out(): void
    {
        Employee::factory()->create(['biometric_id' => '906']);

        $this->actingAs($this->userWith(['dtr.manage']))
            ->post('/admin/hr/dtr/import', ['file' => $this->log(3, '959'), 'known_only' => true]);

        $this->assertSame(0, BiometricPunch::query()->count());
        $this->assertSame(3, BiometricImport::query()->firstOrFail()->rows_ignored);
    }

    public function test_a_user_with_only_dtr_view_cannot_upload(): void
    {
        $this->actingAs($this->userWith(['dtr.view']))
            ->post('/admin/hr/dtr/import', ['file' => $this->log(1)])
            ->assertForbidden();

        $this->assertSame(0, BiometricPunch::query()->count());
    }

    public function test_a_file_over_5mb_or_with_a_bad_extension_is_rejected(): void
    {
        $hrd = $this->userWith(['dtr.manage']);

        $this->actingAs($hrd)->post('/admin/hr/dtr/import', ['file' => UploadedFile::fake()->create('big.dat', 6000)])->assertSessionHasErrors('file');
        $this->actingAs($hrd)->post('/admin/hr/dtr/import', ['file' => UploadedFile::fake()->create('log.exe', 10)])->assertSessionHasErrors('file');
    }

    public function test_the_page_lists_recent_uploads_and_the_ids_no_employee_has(): void
    {
        $this->actingAs($this->userWith(['dtr.manage']))->post('/admin/hr/dtr/import', ['file' => $this->log(2, '906', 'samar.dat')]);
        Employee::factory()->create(['biometric_id' => '959']);
        $this->actingAs($this->userWith(['dtr.manage']))->post('/admin/hr/dtr/import', ['file' => $this->log(1, '959', 'ro8.dat')]);

        $this->actingAs($this->userWith(['dtr.view']))
            ->get('/admin/hr/dtr/import')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/HR/Dtr/Import')
                ->where('canManage', false)
                ->where('imports.0.file_name', 'ro8.dat')
                ->has('imports', 2)
                ->has('unmatched', 1)
                ->where('unmatched.0.biometric_id', '906')
                ->where('unmatched.0.punches', 2));
    }

    public function test_only_employees_whose_punches_the_upload_adds_are_notified_once(): void
    {
        $staff = Employee::factory()->create(['biometric_id' => '906']);
        $elsewhere = Employee::factory()->create(['biometric_id' => '907']);
        $gone = Employee::factory()->create(['biometric_id' => '908', 'separation_date' => '2025-01-01']);
        $hrd = $this->userWith(['dtr.manage']);

        $this->actingAs($hrd)->post('/admin/hr/dtr/import', ['file' => $this->log(2, '906')]);

        $note = $staff->user->notifications()->sole();
        $this->assertSame('Biometric logs uploaded', $note->data['title']);
        $this->assertStringContainsString($hrd->name, $note->data['message']);
        $this->assertSame('/my/dtr', $note->data['url']);
        $this->assertCount(0, $gone->user->notifications);
        // Nothing of theirs was in this upload.
        $this->assertCount(0, $elsewhere->user->notifications);

        // Nothing new in a repeat upload, so nobody is bothered again.
        $this->actingAs($hrd)->post('/admin/hr/dtr/import', ['file' => $this->log(2, '906')]);
        $this->assertCount(1, $staff->user->fresh()->notifications);
    }

    public function test_the_whole_file_is_stored_and_the_new_punches_are_counted_month_by_month(): void
    {
        $hrd = $this->userWith(['dtr.manage']);
        $file = UploadedFile::fake()->createWithContent('all.dat', "906\t2026-10-30 08:00:00\t1\t0\t0\t0\r\n906\t2026-11-02 08:00:00\t1\t0\t0\t0\r\n906\t2026-11-03 08:00:00\t1\t0\t0\t0\r\n");

        $this->actingAs($hrd)->post('/admin/hr/dtr/import', ['file' => $file])
            ->assertSessionHas('import_result', fn (array $result) => $result['new_by_month'] === ['2026-11' => 2, '2026-10' => 1]);

        $this->assertSame(3, BiometricPunch::query()->count());
        $this->assertSame(['2026-11' => 2, '2026-10' => 1], BiometricImport::query()->sole()->new_by_month);
    }

    public function test_punches_before_the_dtr_start_date_are_skipped_and_counted(): void
    {
        SettingRegistry::sync();
        $setting = Setting::query()->where('key', 'dtr_punches_from')->firstOrFail();
        $setting->value = $setting->type->serialize('2026-11-01');
        $setting->save();
        Settings::flush();
        $hrd = $this->userWith(['dtr.manage']);
        $file = UploadedFile::fake()->createWithContent('all.dat', "906\t2025-06-30 08:00:00\t1\t0\t0\t0\r\n906\t2026-10-31 17:00:00\t1\t0\t0\t0\r\n906\t2026-11-01 08:00:00\t1\t0\t0\t0\r\n");

        $this->actingAs($hrd)->post('/admin/hr/dtr/import', ['file' => $file]);

        $import = BiometricImport::query()->sole();
        $this->assertSame(2, $import->rows_before_start);
        $this->assertSame(1, $import->rows_new);
        $this->assertSame(0, $import->rows_duplicate);
        $this->assertSame(['2026-11-01 08:00:00'], BiometricPunch::query()->pluck('punched_at')->map->toDateTimeString()->all());
    }

    public function test_the_monitor_groups_support_units_as_one_regional_office_and_lists_each_field_office(): void
    {
        $rsu = OrganizationalUnit::factory()->create(['unit_type' => UnitType::RegionalSupportUnit]);
        $otherRsu = OrganizationalUnit::factory()->create(['unit_type' => UnitType::RegionalSupportUnit]);
        $leyte = OrganizationalUnit::factory()->create(['unit_type' => UnitType::FieldOffice, 'name' => 'CSC Field Office - Leyte I']);
        $samar = OrganizationalUnit::factory()->create(['unit_type' => UnitType::FieldOffice, 'name' => 'CSC Field Office - Samar']);

        Employee::factory()->create(['biometric_id' => '1', 'user_id' => User::factory()->create(['organizational_unit_id' => $rsu->id])]);
        Employee::factory()->create(['biometric_id' => '2', 'user_id' => User::factory()->create(['organizational_unit_id' => $otherRsu->id])]);
        Employee::factory()->create(['biometric_id' => '3', 'user_id' => User::factory()->create(['organizational_unit_id' => $leyte->id])]);
        Employee::factory()->create(['biometric_id' => '4', 'user_id' => User::factory()->create(['organizational_unit_id' => $samar->id])]);

        $hrd = $this->userWith(['dtr.manage']);
        $this->actingAs($hrd)->post('/admin/hr/dtr/import', ['file' => UploadedFile::fake()->createWithContent('all.dat', "1\t2026-03-02 08:00:00\t1\t0\t0\t0\r\n3\t2026-03-02 08:00:00\t1\t0\t0\t0\r\n")]);

        $this->actingAs($hrd)->get('/admin/hr/dtr/import?month=2026-03')
            ->assertInertia(fn (Assert $page) => $page
                ->where('monthLabel', 'March 2026')
                ->where('monitor', fn ($rows) => collect($rows)->pluck('status', 'office')->all() === [
                    'Regional Office' => 'partial',
                    'CSC Field Office - Leyte I' => 'received',
                    'CSC Field Office - Samar' => 'missing',
                ])
                // Each office lists its staff, missing first: the Regional Office's second employee has no punches.
                ->where('monitor.0.staff.0.status', 'no_punches')
                ->where('monitor.0.staff.0.biometric_id', '2')
                ->where('monitor.0.staff.1.status', 'in')
                ->where('monitor.0.staff.1.days', 1)
                ->where('monitor.0.staff.1.last_punch', '2026-03-02'));
    }

    public function test_a_move_between_two_support_units_counts_once_in_the_regional_office(): void
    {
        $psed = OrganizationalUnit::factory()->create(['unit_type' => UnitType::RegionalSupportUnit]);
        $msd = OrganizationalUnit::factory()->create(['unit_type' => UnitType::RegionalSupportUnit]);
        $mover = Employee::factory()->create(['biometric_id' => '7', 'user_id' => User::factory()->create(['organizational_unit_id' => $msd->id])]);
        $mover->assignments()->create(['kind' => 'reassignment', 'from_unit_id' => $psed->id, 'to_unit_id' => $msd->id, 'effective_date' => '2026-03-10']);

        $regional = collect($this->actingAs($this->userWith(['dtr.view']))->get('/admin/hr/dtr/import?month=2026-03')->viewData('page')['props']['monitor'])->firstWhere('office', 'Regional Office');

        $this->assertSame(1, $regional['employees']);
        $this->assertCount(1, $regional['staff']);
    }

    public function test_contract_of_service_personnel_are_not_counted(): void
    {
        $office = OrganizationalUnit::factory()->create(['unit_type' => UnitType::FieldOffice, 'name' => 'CSC Field Office - Samar']);
        Employee::factory()->create(['biometric_id' => '5', 'user_id' => User::factory()->create(['organizational_unit_id' => $office->id, 'name' => 'Ana Permanent'])]);
        Employee::factory()->create(['biometric_id' => '6', 'employment_status' => EmploymentStatus::ContractOfService, 'user_id' => User::factory()->create(['organizational_unit_id' => $office->id, 'name' => 'Cy Contract'])]);

        $samar = collect($this->actingAs($this->userWith(['dtr.view']))->get('/admin/hr/dtr/import?month=2026-03')->viewData('page')['props']['monitor'])->firstWhere('office', 'CSC Field Office - Samar');

        $this->assertSame(1, $samar['employees']);
        $this->assertSame(['Ana Permanent'], array_column($samar['staff'], 'name'));
    }

    public function test_someone_without_a_biometric_id_is_listed_first_as_missing(): void
    {
        $office = OrganizationalUnit::factory()->create(['unit_type' => UnitType::FieldOffice, 'name' => 'CSC Field Office - Samar']);
        Employee::factory()->create(['biometric_id' => '5', 'user_id' => User::factory()->create(['organizational_unit_id' => $office->id, 'name' => 'Ana Punched'])]);
        Employee::factory()->create(['biometric_id' => null, 'user_id' => User::factory()->create(['organizational_unit_id' => $office->id, 'name' => 'Zed NoId'])]);

        $hrd = $this->userWith(['dtr.manage']);
        $this->actingAs($hrd)->post('/admin/hr/dtr/import', ['file' => UploadedFile::fake()->createWithContent('all.dat', "5\t2026-03-02 08:00:00\t1\t0\t0\t0\r\n5\t2026-03-03 08:00:00\t1\t0\t0\t0\r\n")]);

        $samar = collect($this->actingAs($hrd)->get('/admin/hr/dtr/import?month=2026-03')->viewData('page')['props']['monitor'])->firstWhere('office', 'CSC Field Office - Samar');

        $this->assertSame(['Zed NoId', 'Ana Punched'], array_column($samar['staff'], 'name'));
        $this->assertSame(['no_id', 'in'], array_column($samar['staff'], 'status'));
        $this->assertSame(2, $samar['staff'][1]['days']);
    }

    public function test_a_transferee_counts_once_in_the_office_they_ended_the_month_in(): void
    {
        $samar = OrganizationalUnit::factory()->create(['unit_type' => UnitType::FieldOffice, 'name' => 'CSC Field Office - Samar']);
        $leyte = OrganizationalUnit::factory()->create(['unit_type' => UnitType::FieldOffice, 'name' => 'CSC Field Office - Leyte I']);

        // Moved Samar to Leyte I on 10 March 2026; the account now sits in Leyte I.
        $mover = Employee::factory()->create(['biometric_id' => '7', 'user_id' => User::factory()->create(['organizational_unit_id' => $leyte->id])]);
        $mover->assignments()->create(['kind' => 'reassignment', 'from_unit_id' => $samar->id, 'to_unit_id' => $leyte->id, 'effective_date' => '2026-03-10']);

        $hrd = $this->userWith(['dtr.manage']);
        $this->actingAs($hrd)->post('/admin/hr/dtr/import', ['file' => UploadedFile::fake()->createWithContent('all.dat', "7\t2026-02-02 08:00:00\t1\t0\t0\t0\r\n7\t2026-03-12 08:00:00\t1\t0\t0\t0\r\n")]);

        $rows = fn (string $month) => collect($this->actingAs($hrd)->get("/admin/hr/dtr/import?month={$month}")->viewData('page')['props']['monitor'])->pluck('employees', 'office');

        // February: still Samar's. March: Leyte I's, where the month ended, noting the move. April: Leyte I's.
        $this->assertSame(1, $rows('2026-02')['CSC Field Office - Samar']);
        $this->assertSame(0, $rows('2026-02')['CSC Field Office - Leyte I']);
        $this->assertSame(0, $rows('2026-03')['CSC Field Office - Samar']);
        $this->assertSame(1, $rows('2026-03')['CSC Field Office - Leyte I']);
        $march = collect($this->actingAs($hrd)->get('/admin/hr/dtr/import?month=2026-03')->viewData('page')['props']['monitor'])->firstWhere('office', 'CSC Field Office - Leyte I');
        $this->assertSame(['CSC Field Office - Samar'], $march['staff'][0]['moved_from']);
        $this->assertSame(0, $rows('2026-04')['CSC Field Office - Samar']);
        $this->assertSame(1, $rows('2026-04')['CSC Field Office - Leyte I']);
    }

    public function test_the_page_is_closed_to_users_without_dtr_permissions(): void
    {
        $this->actingAs($this->userWith([]))->get('/admin/hr/dtr/import')->assertForbidden();
    }

    private function log(int $rows, string $biometricId = '906', string $name = 'attlog.dat'): UploadedFile
    {
        $lines = collect(range(1, $rows))->map(fn (int $i) => "{$biometricId}\t2026-03-0{$i} 08:00:00\t12\t0\t0\t0");

        return UploadedFile::fake()->createWithContent($name, $lines->implode("\r\n")."\r\n");
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
