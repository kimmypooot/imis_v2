<?php

namespace Tests\Feature\HR;

use App\Enums\PayrollRunStatus;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\HR\HrMonth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The HR dashboard banner: what is waiting on HR, and this month's steps,
 * each only for people who may act on it.
 */
class HrMonthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-26 10:00:00');
    }

    public function test_each_person_sees_only_the_steps_and_buttons_for_their_own_work(): void
    {
        $payroll = app(HrMonth::class)->for($this->userWith(['payroll.manage']));
        $this->assertSame(['payroll'], array_column($payroll['steps'], 'key'));
        $this->assertSame(['Draft payroll'], array_column($payroll['actions'], 'label'));

        $dtr = app(HrMonth::class)->for($this->userWith(['dtr.manage']));
        $this->assertSame(['biometric', 'entries'], array_column($dtr['steps'], 'key'));
        $this->assertSame(['Upload biometric log'], array_column($dtr['actions'], 'label'));

        $this->assertSame([], app(HrMonth::class)->for($this->userWith(['employees.view']))['steps']);
    }

    public function test_the_payroll_step_follows_this_months_run(): void
    {
        $user = $this->userWith(['payroll.manage']);
        $this->assertSame('not drafted yet', app(HrMonth::class)->for($user)['steps'][0]['detail']);

        $run = PayrollRun::query()->create(['year' => 2026, 'month' => 11, 'status' => PayrollRunStatus::Returned, 'release_on' => '2026-11-30']);
        cache()->flush();

        $step = app(HrMonth::class)->for($user)['steps'][0];
        $this->assertSame('back from Accounting: finalize · release Nov 30', $step['detail']);
        $this->assertSame('active', $step['state']);
        $this->assertSame("/admin/hr/payroll/runs/{$run->id}", $step['href']);
        $this->assertSame('Open payroll', app(HrMonth::class)->for($user)['actions'][0]['label']);
    }

    public function test_waiting_on_hr_counts_the_queues_the_person_works_and_leave_gets_a_button(): void
    {
        $user = $this->userWith(['leave.manage']);
        // The queue counts the sidebar badges already use (HrQueues, cached per person).
        cache()->put("hr-queues:{$user->id}", ['pdsVerifications' => 0, 'ldReviews' => 0, 'leaveApplications' => 2, 'dataCorrections' => 0, 'payslipRequests' => 0, 'payrolls' => 0, 'payrollTaxReviews' => 0, 'salnReviews' => 0], 60);

        $month = app(HrMonth::class)->for($user);

        $this->assertSame(2, $month['waiting']);
        $this->assertContains('Review leave (2)', array_column($month['actions'], 'label'));
        $this->assertSame(0, app(HrMonth::class)->for($this->userWith(['payroll.manage']))['waiting']);
    }

    public function test_the_dashboard_sends_the_banner_after_the_page_appears(): void
    {
        $this->actingAs($this->userWith(['payroll.manage']))->get('/admin/hr')
            ->assertInertia(fn (Assert $page) => $page->component('Admin/HR/Dashboard')->missing('month')
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('month.month', 'November')->where('month.steps.0.key', 'payroll')));
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
