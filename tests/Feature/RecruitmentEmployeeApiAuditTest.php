<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Recruitment Portal reads an applicant's verified PDS with a shared
 * token and no IMIS account, so each read is audited and the employee can
 * see it under "Who accessed my record" (RA 10173).
 */
class RecruitmentEmployeeApiAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['recruitment.api_token' => 'secret-token']);
    }

    public function test_reading_a_pds_is_audited_and_shown_to_the_employee(): void
    {
        $employee = Employee::factory()->create();

        $this->withToken('secret-token')->getJson("/api/v1/recruitment/employees/{$employee->user_id}/pds")->assertOk();

        $entry = AuditLog::query()->where('event', AuditEvent::RecruitmentPdsRead->value)->sole();
        $this->assertNull($entry->actor_id);
        $this->assertSame($employee->id, (int) $entry->target_id);

        $this->actingAs($employee->user)->get('/my/record-access')->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.who', 'Recruitment Portal'));
    }

    public function test_a_refused_request_leaves_no_entry_and_reads_nothing(): void
    {
        $employee = Employee::factory()->create();

        $this->withToken('wrong')->getJson("/api/v1/recruitment/employees/{$employee->user_id}/pds")->assertUnauthorized();

        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::RecruitmentPdsRead->value)->count());
    }
}
