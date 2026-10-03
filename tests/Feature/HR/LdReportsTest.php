<?php

namespace Tests\Feature\HR;

use App\Enums\LdApprovalStatus;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-3c: HRD's L&D dashboard and training report (ldms's hrd dashboard
 * and training_report.php). Approved trainings only.
 */
class LdReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_pages_need_ld_view_or_ld_manage(): void
    {
        $nobody = Employee::factory()->create()->user;

        $this->actingAs($nobody)->get('/admin/hr/learning-development/dashboard')->assertForbidden();
        $this->actingAs($nobody)->get('/admin/hr/learning-development/report')->assertForbidden();
        $this->actingAs($this->userWith(['ld.approve']))->get('/admin/hr/learning-development/report')->assertForbidden();

        $this->actingAs($this->userWith(['ld.view']))->get('/admin/hr/learning-development/dashboard')->assertOk();
        $this->actingAs($this->userWith(['ld.manage']))->get('/admin/hr/learning-development/report')->assertOk();
    }

    public function test_the_dashboard_counts_approved_trainings_of_the_year(): void
    {
        $hrd = $this->office('Human Resource Division');
        $msd = $this->office('Management Services Division');
        $ana = $this->employee('Ana', 'Santos', $hrd);
        $ben = $this->employee('Ben', 'Reyes', $msd);
        Employee::factory()->separated()->create();

        $this->training($ana, ['date_from' => '2026-03-02', 'hours' => 8, 'ld_type' => 'technical']);
        $this->training($ana, ['date_from' => '2026-03-20', 'hours' => 16, 'ld_type' => 'managerial']);
        $this->training($ben, ['date_from' => '2026-07-01', 'hours' => 4, 'ld_type' => 'technical']);
        $this->training($ben, ['date_from' => '2026-08-01', 'hours' => 40], LdApprovalStatus::Pending);
        $this->training($ben, ['date_from' => '2026-09-01', 'hours' => 40], LdApprovalStatus::Rejected);
        $this->training($ben, ['date_from' => '2025-05-05', 'hours' => 2]);
        $this->training($ben, ['date_from' => '2025-06-05', 'hours' => 2]);

        $this->actingAs($this->userWith(['ld.view']))->get('/admin/hr/learning-development/dashboard?year=2026')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/HR/LearningDevelopment/Dashboard')
                ->where('year', 2026)
                ->where('years', [2026, 2025])
                // Ana and Ben; the separated employee isn't counted.
                ->where('stats.employees', 2)
                ->where('stats.trainings', 3)
                ->where('stats.trainings_previous', 2)
                ->where('stats.hours', 28)
                ->where('stats.offices', 2)
                ->where('stats.trained', 2)
                ->where('monthly', [0, 0, 2, 0, 0, 0, 1, 0, 0, 0, 0, 0])
                ->where('types.0.label', 'Managerial')
                ->where('types.0.count', 1)
                ->where('types.0.percent', 33)
                ->where('types.2.count', 2)
                ->where('top.0.name', 'Santos, Ana')
                ->where('top.0.hours', 24)
                ->where('top.0.trainings', 2)
                ->has('top', 2)
                ->has('recent', 3));
    }

    public function test_the_report_lists_approved_trainings_by_surname_and_filters(): void
    {
        $hrd = $this->office('Human Resource Division');
        $msd = $this->office('Management Services Division');
        $zed = $this->employee('Zed', 'Abad', $msd, ['middle_name' => 'Cruz', 'sex' => 'male', 'position' => 'Analyst']);
        $ana = $this->employee('Ana', 'Santos', $hrd);

        $this->training($ana, ['title' => 'Coaching', 'date_from' => '2026-03-02', 'venue' => 'Tacloban', 'resource_speaker' => 'Dr. Lim']);
        $this->training($zed, ['title' => 'Older', 'date_from' => '2026-01-10']);
        $this->training($zed, ['title' => 'Newer', 'date_from' => '2026-03-15']);
        $this->training($zed, ['title' => 'Waiting', 'date_from' => '2026-03-16'], LdApprovalStatus::Pending);
        $this->training($zed, ['title' => 'Last year', 'date_from' => '2025-03-15']);

        $viewer = $this->userWith(['ld.view']);

        $this->actingAs($viewer)->get('/admin/hr/learning-development/report?year=2026')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/HR/LearningDevelopment/Report')
                ->where('period', 'All months 2026')
                ->has('rows', 3)
                ->where('rows.0.name', 'Abad, Zed C.')
                ->where('rows.0.title', 'Newer')
                ->where('rows.0.sex', 'Male')
                ->where('rows.0.position', 'Analyst')
                ->where('rows.1.title', 'Older')
                ->where('rows.2.title', 'Coaching')
                ->where('rows.2.venue', 'Tacloban')
                ->where('rows.2.resource_speaker', 'Dr. Lim')
                ->has('officeOptions', 2));

        $this->actingAs($viewer)->get('/admin/hr/learning-development/report?year=2026&month=3')
            ->assertInertia(fn (Assert $page) => $page->where('period', 'March 2026')->has('rows', 2));

        $this->actingAs($viewer)->get("/admin/hr/learning-development/report?year=2026&month=3&office={$hrd->id}")
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.title', 'Coaching'));

        $this->actingAs($viewer)->get('/admin/hr/learning-development/report?month=13')->assertSessionHasErrors('month');
    }

    public function test_opening_the_report_is_audited(): void
    {
        $viewer = $this->userWith(['ld.view']);

        $this->actingAs($viewer)->get('/admin/hr/learning-development/report?year=2026&month=3');

        $this->assertDatabaseHas('audit_logs', ['event' => 'ld_record.report_viewed', 'actor_id' => $viewer->id]);
    }

    private function office(string $name): OrganizationalUnit
    {
        return OrganizationalUnit::factory()->create(['name' => $name, 'short_name' => null]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(string $first, string $last, OrganizationalUnit $office, array $attributes = []): Employee
    {
        $user = User::factory()->create(['first_name' => $first, 'last_name' => $last, 'organizational_unit_id' => $office->id, ...$attributes]);

        return Employee::factory()->for($user)->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function training(Employee $employee, array $attributes = [], LdApprovalStatus $status = LdApprovalStatus::Approved): PdsLearningDevelopment
    {
        $record = new PdsLearningDevelopment;
        $record->forceFill([
            'employee_id' => $employee->id,
            'title' => 'Records Management',
            'date_to' => $attributes['date_from'] ?? '2026-01-01',
            'date_from' => '2026-01-01',
            'hours' => 4,
            'ld_type' => 'technical',
            'modality' => 'face_to_face',
            'sponsor' => 'CSC RO VIII',
            'approval_status' => $status,
            ...$attributes,
        ])->save();

        return $record;
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
