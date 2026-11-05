<?php

namespace Tests\Feature\HR;

use App\Enums\EmploymentStatus;
use App\Enums\LeaveApplicationStatus;
use App\Models\Application;
use App\Models\Employee;
use App\Models\FwaArrangement;
use App\Models\FwaReport;
use App\Models\Holiday;
use App\Models\LeaveApplication;
use App\Models\LeaveDesignation;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveMonetization;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-7: `imis:import-legacy-leave` against small, entirely fictional
 * `lms_*` tables on a second in-memory connection (columns as the legacy
 * LMS code reads them), plus the readiness page and launcher retirement.
 */
class LegacyLeaveImportTest extends TestCase
{
    use RefreshDatabase;

    private ConnectionInterface $legacy;

    private Employee $ana;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();

        Application::query()->delete();

        $this->seed(LeaveTypeSeeder::class);
        config(['database.connections.legacy_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        config(['legacy.connection' => 'legacy_test']);
        $this->legacy = DB::connection('legacy_test');
        $this->createLegacyTables();

        $this->ana = Employee::factory()->for(User::factory()->state(['legacy_id' => 1]))->create(['original_appointment_date' => null, 'immediate_supervisor_id' => null]);
        $supervisor = User::factory()->create(['legacy_id' => 2]);
        $this->head = User::factory()->create(['legacy_id' => 3]);
        User::factory()->create(['legacy_id' => 4]); // An account with no employee record.

        $this->legacy->table('lms_leave_types')->insert([
            ['id' => 1, 'code' => 'VL', 'name' => 'Vacation Leave'],
            ['id' => 2, 'code' => 'SL', 'name' => 'Sick Leave'],
            ['id' => 3, 'code' => 'SPL', 'name' => 'Solo Parent Leave'],
            ['id' => 9, 'code' => 'XX', 'name' => 'Something retired'],
        ]);
        $this->legacy->table('lms_employee_profiles')->insert([
            ['id' => 1, 'user_id' => 1, 'appointment_type' => 'Permanent', 'immediate_supervisor_id' => 2, 'is_solo_parent' => 1, 'solo_parent_id_number' => 'SP-0001', 'solo_parent_id_expiry' => '2027-01-01', 'first_day_in_service' => '2015-01-05', 'is_active' => 1],
        ]);
        $this->legacy->table('lms_holidays_v2')->insert([
            ['id' => 1, 'holiday_date' => '2026-12-25', 'holiday_name' => 'Christmas Day', 'type' => 'regular', 'is_active' => 1],
            ['id' => 2, 'holiday_date' => '2026-12-24', 'holiday_name' => 'Christmas Eve', 'type' => 'special_non_working', 'is_active' => 0],
        ]);
        $this->legacy->table('lms_agency_designations')->insert([
            ['id' => 1, 'designee_id' => 2, 'designated_by' => 3, 'effective_from' => '2026-05-01', 'effective_to' => '2026-05-05', 'is_active' => 1],
        ]);
        $this->legacy->table('lms_credit_ledger')->insert([
            $this->entry(['id' => 1, 'leave_type_id' => 1, 'transaction_type' => 'opening', 'amount' => 20]),
            $this->entry(['id' => 2, 'leave_type_id' => 1, 'transaction_type' => 'accrual', 'amount' => 1.25, 'entry_date' => '2026-01-31', 'entry_month' => 1]),
            $this->entry(['id' => 3, 'leave_type_id' => 1, 'transaction_type' => 'deduction', 'amount' => -2, 'entry_date' => '2026-02-10', 'application_id' => 10]),
            $this->entry(['id' => 4, 'leave_type_id' => 3, 'transaction_type' => 'grant', 'amount' => 3]),
            $this->entry(['id' => 5, 'leave_type_id' => 9, 'transaction_type' => 'grant', 'amount' => 5]),
            $this->entry(['id' => 6, 'user_id' => 4, 'leave_type_id' => 1, 'transaction_type' => 'opening', 'amount' => 7]),
        ]);
        $this->legacy->table('lms_applications')->insert([
            $this->application(['id' => 10, 'reference_number' => 'LV-2026-00010', 'status' => 'approved', 'days_with_pay' => 2, 'days_without_pay' => 0, 'attachment_path' => 'uploads/x.pdf']),
            $this->application(['id' => 11, 'reference_number' => 'LV-2026-00011', 'status' => 'for_review']),
        ]);
        $this->legacy->table('lms_workflow_steps')->insert([
            ['id' => 1, 'application_id' => 10, 'step_number' => 1, 'form_part' => 'B', 'assigned_to' => 2, 'designated_for' => null, 'action' => 'recommended', 'remarks' => null, 'performed_at' => '2026-02-02 09:00:00'],
            ['id' => 2, 'application_id' => 10, 'step_number' => 2, 'form_part' => 'A', 'assigned_to' => 3, 'designated_for' => null, 'action' => 'certified', 'remarks' => null, 'performed_at' => '2026-02-03 09:00:00'],
            ['id' => 3, 'application_id' => 10, 'step_number' => 3, 'form_part' => 'C/D', 'assigned_to' => 2, 'designated_for' => 3, 'action' => 'approved', 'remarks' => 'OK', 'performed_at' => '2026-02-04 09:00:00'],
        ]);
        $this->legacy->table('lms_fwa_arrangements')->insert([
            ['id' => 1, 'user_id' => 1, 'fwa_type' => 'cww', 'status' => 'approved', 'effective_from' => '2026-03-02', 'effective_to' => null, 'work_schedule' => json_encode(['Mon' => 'office', 'Fri' => 'off']), 'compressed_hours' => 10, 'remarks' => null, 'approved_by' => 3, 'approved_at' => '2026-03-01 08:00:00', 'rejection_reason' => null],
        ]);
        $this->legacy->table('lms_fwa_far')->insert([
            ['id' => 1, 'arrangement_id' => 1, 'user_id' => 1, 'week_start' => '2026-03-02', 'week_end' => '2026-03-06', 'targets' => 'Targets', 'accomplishments' => 'Done', 'status' => 'acknowledged', 'remarks' => null, 'acknowledged_by' => 2, 'acknowledged_at' => '2026-03-09 08:00:00'],
        ]);
        $this->legacy->table('lms_monetization_requests')->insert([
            ['id' => 1, 'user_id' => 1, 'reference_number' => 'MON-2026-1', 'leave_type_id' => 1, 'days_to_monetize' => 5, 'balance_before' => 25, 'monthly_salary' => 22000, 'computed_amount' => 5000, 'calendar_year' => 2026, 'status' => 'released', 'remarks' => null, 'approved_by' => 3, 'approved_at' => '2026-04-01 08:00:00', 'released_at' => '2026-04-15 08:00:00', 'created_at' => '2026-03-25 08:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('legacy_test');

        parent::tearDown();
    }

    public function test_it_carries_the_legacy_lms_over_and_can_run_again(): void
    {
        $this->artisan('imis:import-legacy-leave')->assertSuccessful();

        $this->ana->refresh();
        $this->assertSame(EmploymentStatus::Permanent, $this->ana->employment_status);
        $this->assertSame('2015-01-05', $this->ana->original_appointment_date->toDateString());
        $this->assertSame(User::query()->where('legacy_id', 2)->value('id'), $this->ana->immediate_supervisor_id);
        $this->assertTrue($this->ana->is_solo_parent);
        $this->assertSame('SP-0001', $this->ana->solo_parent_id_number);

        $this->assertSame(['Christmas Day'], Holiday::query()->pluck('name')->all());
        $this->assertSame(1, LeaveDesignation::query()->count());

        // VL carries over to the day: 20 + 1.25 − 2; the unknown type and
        // the person without an employee record are skipped.
        $vl = LeaveType::query()->where('code', 'VL')->value('id');
        $this->assertEqualsWithDelta(19.25, (float) LeaveLedgerEntry::query()->where('employee_id', $this->ana->id)->where('leave_type_id', $vl)->sum('amount'), 0.001);
        $this->assertSame(4, LeaveLedgerEntry::query()->count());
        $this->assertSame(1, LeaveLedgerEntry::query()->where('legacy_id', 2)->value('period_month'));

        // Only the decided application comes over, with its history.
        $application = LeaveApplication::query()->sole();
        $this->assertSame('LMS-LV-2026-00010', $application->reference_number);
        $this->assertSame(LeaveApplicationStatus::Approved, $application->status);
        $this->assertSame(['B', 'A', 'C'], $application->steps()->orderBy('step')->pluck('part')->all());
        $this->assertSame($this->head->id, $application->steps()->where('part', 'C')->value('acted_for'));

        $this->assertSame([5], FwaArrangement::query()->sole()->restDays()); // Friday off.
        $this->assertSame('acknowledged', FwaReport::query()->sole()->status);
        $this->assertSame('LMS-MON-2026-1', LeaveMonetization::query()->sole()->reference_number);

        // A second run adds nothing.
        $this->artisan('imis:import-legacy-leave')->assertSuccessful();
        $this->assertSame(4, LeaveLedgerEntry::query()->count());
        $this->assertSame(1, LeaveApplication::query()->count());
        $this->assertSame(1, Holiday::query()->count());
    }

    public function test_a_dry_run_saves_nothing_and_missing_tables_are_reported(): void
    {
        $this->legacy->getSchemaBuilder()->drop('lms_fwa_far');

        $this->artisan('imis:import-legacy-leave', ['--dry-run' => true])
            ->expectsOutputToContain('lms_fwa_far')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, LeaveLedgerEntry::query()->count());
        $this->assertNull($this->ana->fresh()->original_appointment_date);
    }

    public function test_the_readiness_page_and_retiring_the_launchers(): void
    {
        $hr = $this->userWith(['leave.manage']);
        foreach (['LMS', 'LDMS'] as $system) {
            Application::factory()->create(['short_name' => $system]);
        }

        $this->actingAs($this->userWith(['leave.view']))->get('/admin/hr/readiness')->assertForbidden();
        $this->actingAs($hr)->get('/admin/hr/readiness')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/HR/Readiness')
            ->where('checks', fn ($checks) => collect($checks)->firstWhere('label', 'Legacy LMS credits imported')['status'] === 'todo'));

        $this->artisan('imis:retire-legacy-hr')->expectsOutputToContain('--apply')->assertSuccessful();
        $this->assertSame(2, Application::query()->where('status', 'active')->count());

        $this->artisan('imis:retire-legacy-hr', ['--apply' => true])->assertSuccessful();
        $this->assertSame(0, Application::query()->where('status', 'active')->count());
    }

    private function createLegacyTables(): void
    {
        $schema = $this->legacy->getSchemaBuilder();
        $tables = [
            'lms_leave_types' => ['code', 'name'],
            'lms_employee_profiles' => ['user_id', 'appointment_type', 'immediate_supervisor_id', 'is_solo_parent', 'solo_parent_id_number', 'solo_parent_id_expiry', 'first_day_in_service', 'is_active'],
            'lms_holidays_v2' => ['holiday_date', 'holiday_name', 'type', 'is_active'],
            'lms_agency_designations' => ['designee_id', 'designated_by', 'effective_from', 'effective_to', 'is_active'],
            'lms_credit_ledger' => ['user_id', 'leave_type_id', 'entry_date', 'entry_year', 'entry_month', 'transaction_type', 'amount', 'balance_after', 'particulars', 'application_id', 'performed_by', 'source'],
            'lms_applications' => ['reference_number', 'user_id', 'leave_type_id', 'date_from', 'date_to', 'is_half_day', 'half_day_period', 'days_requested', 'days_with_pay', 'days_without_pay', 'reason', 'details_of_leave', 'location', 'hospital_physician', 'others_specify', 'commutation', 'status', 'attachment_path', 'final_action_at', 'created_at', 'updated_at'],
            'lms_workflow_steps' => ['application_id', 'step_number', 'form_part', 'assigned_to', 'designated_for', 'action', 'remarks', 'performed_at'],
            'lms_fwa_arrangements' => ['user_id', 'fwa_type', 'status', 'effective_from', 'effective_to', 'work_schedule', 'compressed_hours', 'remarks', 'approved_by', 'approved_at', 'rejection_reason'],
            'lms_fwa_far' => ['arrangement_id', 'user_id', 'week_start', 'week_end', 'targets', 'accomplishments', 'status', 'remarks', 'acknowledged_by', 'acknowledged_at'],
            'lms_monetization_requests' => ['user_id', 'reference_number', 'leave_type_id', 'days_to_monetize', 'balance_before', 'monthly_salary', 'computed_amount', 'calendar_year', 'status', 'remarks', 'approved_by', 'approved_at', 'released_at', 'created_at'],
        ];

        foreach ($tables as $name => $columns) {
            $schema->create($name, function (Blueprint $table) use ($columns) {
                $table->integer('id')->primary();
                foreach ($columns as $column) {
                    $table->string($column)->nullable();
                }
            });
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function entry(array $overrides): array
    {
        return $overrides + [
            'user_id' => 1, 'entry_date' => '2026-01-01', 'entry_year' => 2026, 'entry_month' => null, 'balance_after' => null,
            'particulars' => null, 'application_id' => null, 'performed_by' => 3, 'source' => 'system',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function application(array $overrides): array
    {
        return $overrides + [
            'user_id' => 1, 'leave_type_id' => 1, 'date_from' => '2026-02-09', 'date_to' => '2026-02-10', 'is_half_day' => 0, 'half_day_period' => null,
            'days_requested' => 2, 'days_with_pay' => null, 'days_without_pay' => null, 'reason' => null, 'details_of_leave' => 'within_ph', 'location' => 'Tacloban City',
            'hospital_physician' => null, 'others_specify' => null, 'commutation' => 'not_requested', 'attachment_path' => null,
            'final_action_at' => '2026-02-04 09:00:00', 'created_at' => '2026-02-01 08:00:00', 'updated_at' => '2026-02-04 09:00:00',
        ];
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
