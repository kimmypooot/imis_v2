<?php

namespace Tests\Feature\Portal;

use App\Enums\PdsSubmissionStatus;
use App\Models\Employee;
use App\Models\Pds\PdsSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passkeys\Passkey;
use Tests\TestCase;

class GettingStartedTest extends TestCase
{
    use RefreshDatabase;

    public function test_employees_see_signature_pds_and_fingerprint_steps_still_to_do(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($employee->user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('gettingStarted.done', 0)
                ->where('gettingStarted.total', 3)
                ->where('gettingStarted.items.0.key', 'signature')
                ->where('gettingStarted.items.1.key', 'pds')
                ->where('gettingStarted.items.2.key', 'passkey'));
    }

    public function test_accounts_without_an_employee_record_only_get_the_fingerprint_step(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('gettingStarted.total', 1)
                ->where('gettingStarted.items.0.key', 'passkey')
                ->where('gettingStarted.items.0.done', false));
    }

    public function test_steps_are_ticked_from_what_the_employee_has_done(): void
    {
        $employee = Employee::factory()->create();
        $employee->user->forceFill(['signature_path' => 'signatures/mine.png'])->save();
        $this->submitPds($employee, PdsSubmissionStatus::Submitted);

        $this->actingAs($employee->user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('gettingStarted.done', 2)
                ->where('gettingStarted.items.0.done', true)
                ->where('gettingStarted.items.1.done', true)
                ->where('gettingStarted.items.2.done', false));
    }

    public function test_a_pds_returned_for_correction_does_not_count_as_submitted(): void
    {
        $employee = Employee::factory()->create();
        $this->submitPds($employee, PdsSubmissionStatus::Returned);

        $this->actingAs($employee->user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('gettingStarted.items.1.done', false));
    }

    public function test_the_checklist_is_gone_once_everything_is_done(): void
    {
        $employee = Employee::factory()->create();
        $employee->user->forceFill(['signature_path' => 'signatures/mine.png'])->save();
        $this->submitPds($employee, PdsSubmissionStatus::Verified);
        Passkey::query()->forceCreate(['user_id' => $employee->user_id, 'name' => 'Laptop', 'credential_id' => bin2hex(random_bytes(16)), 'credential' => ['id' => 'x']]);

        $this->actingAs($employee->user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('gettingStarted', null));
    }

    private function submitPds(Employee $employee, PdsSubmissionStatus $status): void
    {
        PdsSubmission::query()->forceCreate([
            'employee_id' => $employee->id,
            'status' => $status,
            'snapshot' => [],
            'fingerprint' => 'x',
            'signed_by' => $employee->user_id,
            'signed_at' => now(),
        ]);
    }
}
