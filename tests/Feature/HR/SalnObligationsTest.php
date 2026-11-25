<?php

namespace Tests\Feature\HR;

use App\Enums\EmploymentStatus;
use App\Enums\SalnStatus;
use App\Enums\SalnType;
use App\Models\Employee;
use App\Models\Saln;
use App\Models\SalnExemption;
use App\Notifications\HrUpdate;
use App\Support\HR\Saln\SalnObligations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Who must file which SALN, and by when: annual by April 30 for everyone
 * required to file who was in service on December 31; entry and exit
 * within 30 days, from go-live. Plus the reminders.
 */
class SalnObligationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['saln.go_live' => '2026-12-01']);
        $this->travelTo('2027-03-01 09:00:00');
    }

    public function test_required_statuses(): void
    {
        $required = collect(['permanent', 'temporary', 'coterminous', 'contractual'])
            ->map(fn (string $status) => $this->employee(['employment_status' => $status])->id);
        foreach (['casual', 'job_order', 'contract_of_service'] as $status) {
            $this->employee(['employment_status' => $status]);
        }

        $this->assertEqualsCanonicalizing($required->all(), app(SalnObligations::class)->annual(2026)->pluck('employee.id')->all());
    }

    public function test_status_on_december_31_decides(): void
    {
        $employee = $this->employee(['employment_status' => 'permanent']);
        $employee->nonPlantillaContracts()->forceCreate([
            'employment_status' => EmploymentStatus::Casual, 'date_from' => '2026-01-01', 'date_to' => '2026-12-31',
            'designation' => 'Administrative Aide', 'rate' => 500,
        ]);

        $this->assertCount(0, app(SalnObligations::class)->annual(2026));
    }

    public function test_only_those_in_service_on_december_31(): void
    {
        $this->employee(['separation_date' => '2026-11-15']);
        $this->employee(['original_appointment_date' => '2027-01-04']);
        $stays = $this->employee();

        $this->assertSame([$stays->id], app(SalnObligations::class)->annual(2026)->pluck('employee.id')->all());
    }

    public function test_annual_due_on_april_30_and_late_after(): void
    {
        $this->employee();

        $row = app(SalnObligations::class)->annual(2026)->first();
        $this->assertSame('2027-04-30', $row['due']->toDateString());
        $this->assertSame('missing', $row['state']);

        $this->travelTo('2027-05-01 09:00:00');
        $this->assertSame('late', app(SalnObligations::class)->annual(2026)->first()['state']);
    }

    public function test_states_follow_the_saln(): void
    {
        $inProgress = $this->employee();
        $filed = $this->employee();
        $exempt = $this->employee();
        Saln::factory()->for($inProgress)->create(['reckoning_date' => '2026-12-31', 'status' => SalnStatus::Received]);
        Saln::factory()->for($filed)->create(['reckoning_date' => '2026-12-31', 'status' => SalnStatus::Filed]);
        SalnExemption::query()->forceCreate(['employee_id' => $exempt->id, 'year' => 2026, 'reason' => 'Laborer position']);

        $states = app(SalnObligations::class)->annual(2026)->mapWithKeys(fn (array $row) => [$row['employee']->id => $row['state']]);

        $this->assertSame('in_progress', $states[$inProgress->id]);
        $this->assertSame('filed', $states[$filed->id]);
        $this->assertSame('exempt', $states[$exempt->id]);
    }

    public function test_annual_years_due_before_go_live_are_not_tracked(): void
    {
        $this->employee();

        $this->assertCount(0, app(SalnObligations::class)->annual(2025));
    }

    public function test_entry_before_go_live_is_not_due(): void
    {
        $this->employee(['original_appointment_date' => '2026-11-20']);
        $new = $this->employee(['original_appointment_date' => '2027-02-15']);

        $rows = app(SalnObligations::class)->entryAndExit(now()->subYear()->toImmutable(), now()->toImmutable());

        $this->assertSame([[$new->id, SalnType::Entry, '2027-03-17']], $rows->map(fn (array $row) => [$row['employee']->id, $row['type'], $row['due']->toDateString()])->all());
    }

    public function test_exit_is_due_30_days_after_separation(): void
    {
        $leaving = $this->employee(['separation_date' => '2027-02-28']);

        $row = app(SalnObligations::class)->entryAndExit(now()->subYear()->toImmutable(), now()->toImmutable())->sole();

        $this->assertSame($leaving->id, $row['employee']->id);
        $this->assertSame(SalnType::Exit, $row['type']);
        $this->assertSame('2027-02-28', $row['as_of']->toDateString());
        $this->assertSame('2027-03-30', $row['due']->toDateString());
    }

    public function test_reminders_are_sent_once_per_stage(): void
    {
        Notification::fake();
        $employee = $this->employee();

        $this->travelTo('2027-01-04 08:00:00');
        $this->artisan('imis:saln-reminders')->assertSuccessful();
        $this->artisan('imis:saln-reminders')->assertSuccessful();
        Notification::assertSentToTimes($employee->user, HrUpdate::class, 1);

        $this->travelTo('2027-04-23 08:00:00');
        $this->artisan('imis:saln-reminders')->assertSuccessful();
        $this->travelTo('2027-04-30 08:00:00');
        $this->artisan('imis:saln-reminders')->assertSuccessful();
        Notification::assertSentToTimes($employee->user, HrUpdate::class, 3);
    }

    public function test_no_reminder_once_filed(): void
    {
        Notification::fake();
        $employee = $this->employee();
        Saln::factory()->for($employee)->create(['reckoning_date' => '2026-12-31', 'status' => SalnStatus::Filed]);

        $this->travelTo('2027-04-30 08:00:00');
        $this->artisan('imis:saln-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    private function employee(array $attributes = []): Employee
    {
        return Employee::factory()->create(array_replace(['original_appointment_date' => '2015-06-01', 'employment_status' => 'permanent'], $attributes));
    }
}
