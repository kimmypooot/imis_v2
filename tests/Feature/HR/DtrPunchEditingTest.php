<?php

namespace Tests\Feature\HR;

use App\Models\BiometricImport;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\HR\Dtr\BiometricImporter;
use App\Support\HR\Dtr\EmployeePunches;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * HRD adds a missing biometric punch and voids a wrong one. A voided punch
 * is kept (a re-import cannot bring it back) and not counted.
 */
class DtrPunchEditingTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-24 09:00:00');
        $this->seed(LeaveTypeSeeder::class);
        $this->hr = $this->userWith(['dtr.manage']);
        $this->employee = Employee::factory()->create(['biometric_id' => '777']);
        EmployeeWorkSchedule::factory()->create([
            'employee_id' => $this->employee->id,
            'work_schedule_id' => WorkSchedule::query()->where('name', 'not like', 'Compressed%')->value('id'),
            'valid_from' => '2026-01-01',
        ]);
    }

    public function test_a_missing_time_out_can_be_added_and_counts_on_the_dtr(): void
    {
        $this->punch('2026-11-10 07:55:00');
        $this->assertSame(1, $this->dayCount('2026-11-10'));

        $this->actingAs($this->hr)
            ->post($this->url('punches'), ['date' => '2026-11-10', 'time' => '17:05', 'reason' => 'Forgot to punch out'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('biometric_punches', ['biometric_id' => '777', 'punched_at' => '2026-11-10 17:05:00', 'source' => 'manual', 'created_by' => $this->hr->id]);
        $this->assertSame(2, $this->dayCount('2026-11-10'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'dtr_punch.added', 'actor_id' => $this->hr->id]);
    }

    public function test_a_voided_punch_stops_counting_but_stays_on_file(): void
    {
        $this->punch('2026-11-10 07:55:00');
        $bad = $this->punch('2026-11-10 11:00:00');

        $this->actingAs($this->hr)
            ->patch($this->url("punches/{$bad->id}/void"), ['reason' => 'Test tap by a visitor'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->dayCount('2026-11-10'));
        $this->assertNotNull($bad->fresh()->voided_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'dtr_punch.voided', 'actor_id' => $this->hr->id]);
    }

    public function test_a_voided_punch_is_not_brought_back_by_importing_the_same_log_again(): void
    {
        $bad = $this->punch('2026-11-10 11:00:00');
        $this->actingAs($this->hr)->patch($this->url("punches/{$bad->id}/void"), ['reason' => 'Wrong person']);

        $import = BiometricImport::factory()->create();
        $inserted = BiometricPunch::query()->insertOrIgnore([['biometric_id' => '777', 'punched_at' => '2026-11-10 11:00:00', 'biometric_import_id' => $import->id, 'source' => 'device']]);

        $this->assertSame(0, $inserted);
        $this->assertSame(0, $this->dayCount('2026-11-10'));
    }

    public function test_a_voided_punch_can_be_restored(): void
    {
        $punch = $this->punch('2026-11-10 11:00:00');
        $this->actingAs($this->hr)->patch($this->url("punches/{$punch->id}/void"), ['reason' => 'Mistake']);

        $this->actingAs($this->hr)->patch($this->url("punches/{$punch->id}/restore"))->assertSessionHasNoErrors();

        $this->assertNull($punch->fresh()->voided_at);
        $this->assertSame(1, $this->dayCount('2026-11-10'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'dtr_punch.restored']);
    }

    public function test_a_punch_close_to_another_counted_one_is_refused(): void
    {
        $this->punch('2026-11-10 07:55:00');

        $this->actingAs($this->hr)
            ->post($this->url('punches'), ['date' => '2026-11-10', 'time' => '07:56', 'reason' => 'Again'])
            ->assertSessionHasErrors('time');

        $this->assertSame(1, BiometricPunch::query()->count());
    }

    public function test_a_reason_is_required_and_the_future_is_refused(): void
    {
        $this->actingAs($this->hr)->post($this->url('punches'), ['date' => '2026-11-10', 'time' => '17:05', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->hr)->post($this->url('punches'), ['date' => '2026-11-25', 'time' => '08:00', 'reason' => 'Early'])->assertSessionHasErrors('time');
        $this->assertSame(0, BiometricPunch::query()->count());
    }

    public function test_an_employee_without_a_biometric_id_has_no_punches_to_add(): void
    {
        $this->employee->update(['biometric_id' => null]);

        $this->actingAs($this->hr)
            ->post($this->url('punches'), ['date' => '2026-11-10', 'time' => '08:00', 'reason' => 'Missing'])
            ->assertSessionHasErrors('time');
    }

    public function test_the_punches_of_a_day_list_voided_ones_too(): void
    {
        $this->punch('2026-11-10 07:55:00');
        $bad = $this->punch('2026-11-10 11:00:00');
        $this->actingAs($this->hr)->patch($this->url("punches/{$bad->id}/void"), ['reason' => 'Mistake']);

        $this->actingAs($this->hr)->getJson($this->url('punches').'?date=2026-11-10')
            ->assertOk()
            ->assertJsonCount(2, 'punches')
            ->assertJsonPath('punches.1.voided', true);
    }

    public function test_a_punch_of_someone_else_cannot_be_changed_through_this_employee(): void
    {
        $other = BiometricPunch::query()->create(['biometric_id' => '888', 'punched_at' => '2026-11-10 08:00:00', 'biometric_import_id' => BiometricImport::factory()->create()->id]);

        $this->actingAs($this->hr)->patch($this->url("punches/{$other->id}/void"), ['reason' => 'No'])->assertNotFound();
        $this->assertNull($other->fresh()->voided_at);
    }

    public function test_viewing_permission_alone_cannot_change_punches(): void
    {
        $viewer = $this->userWith(['dtr.view']);
        $punch = $this->punch('2026-11-10 11:00:00');

        $this->actingAs($viewer)->post($this->url('punches'), ['date' => '2026-11-10', 'time' => '17:00', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->patch($this->url("punches/{$punch->id}/void"), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->getJson($this->url('punches').'?date=2026-11-10')->assertOk();
    }

    public function test_nobody_edits_their_own_punches(): void
    {
        $own = Employee::factory()->create(['user_id' => $this->hr->id, 'biometric_id' => '999']);

        $this->actingAs($this->hr)
            ->post("/admin/hr/employees/{$own->id}/dtr/punches", ['date' => '2026-11-10', 'time' => '08:00', 'reason' => 'Mine'])
            ->assertForbidden();
    }

    public function test_the_unmatched_id_cleanup_still_works_with_voided_punches(): void
    {
        $this->punch('2026-11-10 11:00:00');
        BiometricPunch::query()->create(['biometric_id' => '4321', 'punched_at' => '2026-11-10 08:00:00', 'biometric_import_id' => BiometricImport::factory()->create()->id]);

        $result = app(BiometricImporter::class)->purgeUnmatched(['4321', '777']);

        $this->assertSame(['4321'], $result['ids']);
        $this->assertSame(1, BiometricPunch::query()->count());
    }

    private function dayCount(string $date): int
    {
        return app(EmployeePunches::class)->byDay($this->employee, Carbon::parse($date), Carbon::parse($date))->get($date)['count'] ?? 0;
    }

    private function punch(string $at): BiometricPunch
    {
        return BiometricPunch::query()->create(['biometric_id' => '777', 'punched_at' => $at, 'biometric_import_id' => BiometricImport::factory()->create()->id]);
    }

    private function url(string $path): string
    {
        return "/admin/hr/employees/{$this->employee->id}/dtr/{$path}";
    }

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
