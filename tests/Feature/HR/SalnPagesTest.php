<?php

namespace Tests\Feature\HR;

use App\Enums\SalnOathMode;
use App\Enums\SalnStatus;
use App\Enums\SalnType;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Saln;
use App\Models\User;
use App\Support\HR\Saln\SalnFilings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\HR\Concerns\SalnFixtures;
use Tests\TestCase;

/**
 * The SALN pages: My SALN for employees, and HRD's and the Committee's
 * compliance list, queue, review, receiving and filing — including HRD
 * filing for someone who can't sign in.
 */
class SalnPagesTest extends TestCase
{
    use RefreshDatabase, SalnFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        config(['saln.go_live' => '2026-12-01']);
        $this->travelTo('2027-03-01 09:00:00');
        $this->regionalDirector();
    }

    public function test_employee_opens_encodes_and_prints_their_own(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($employee->user)->get('/my/saln')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portal/MySaln')->has('due', 1)->where('due.0.type', 'annual'));

        $this->actingAs($employee->user)->post('/my/saln', ['type' => 'annual', 'year' => 2026])->assertRedirect();
        $saln = Saln::query()->sole();

        $this->actingAs($employee->user)->put("/my/saln/{$saln->id}", ['statement' => $this->validStatement(), 'oath_mode' => 'regional_director'])
            ->assertSessionHasNoErrors();
        $this->actingAs($employee->user)->post("/my/saln/{$saln->id}/generate")->assertRedirect("/my/saln/{$saln->id}");

        $this->actingAs($employee->user)->get("/my/saln/{$saln->id}/versions/1/print")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('HR/SalnPrint')->where('version.version', 1)->where('pageCount', 2));
    }

    public function test_employee_sees_and_edits_only_their_own(): void
    {
        $saln = $this->saln();
        $other = Employee::factory()->create();

        $this->actingAs($other->user)->get("/my/saln/{$saln->id}")->assertForbidden();
        $this->actingAs($other->user)->put("/my/saln/{$saln->id}", ['statement' => $this->validStatement(), 'oath_mode' => 'notary'])->assertForbidden();
        $this->actingAs($other->user)->get("/my/saln/{$saln->id}/versions/1/print")->assertForbidden();
    }

    public function test_validation_errors_come_back_on_the_statement(): void
    {
        $saln = $this->saln(generated: false);

        $this->actingAs($saln->employee->user)
            ->put("/my/saln/{$saln->id}", ['statement' => $this->validStatement(['government_id' => ['type' => '', 'number' => '', 'issued_on' => '']]), 'oath_mode' => 'notary'])
            ->assertSessionHasErrors('statement.government_id.number');
    }

    public function test_hrd_can_file_for_a_separated_employee_with_inactive_account(): void
    {
        $employee = Employee::factory()->separated()->create();
        $employee->user->forceFill(['status' => 'inactive'])->save();
        $hrd = $this->userWith(['saln.manage']);

        $this->actingAs($hrd)->post('/admin/hr/saln', ['employee_id' => $employee->id, 'type' => 'exit', 'as_of' => '2027-02-01'])->assertRedirect();
        $saln = Saln::query()->sole();
        $this->actingAs($hrd)->put("/admin/hr/saln/{$saln->id}", ['statement' => $this->validStatement(), 'oath_mode' => 'notary'])->assertSessionHasNoErrors();
        $this->actingAs($hrd)->post("/admin/hr/saln/{$saln->id}/generate")->assertRedirect();

        $this->assertSame(SalnStatus::Generated, $saln->fresh()->status);
        $this->assertSame($hrd->id, $saln->fresh()->created_by);
    }

    public function test_reviewer_cannot_see_drafts(): void
    {
        $saln = $this->saln();
        $committee = $this->userWith(['saln.review']);

        $this->actingAs($committee)->get("/admin/hr/saln/{$saln->id}")->assertForbidden();

        app(SalnFilings::class)->receive($saln, 1, $this->userWith(['saln.manage']));
        $this->actingAs($committee)->get("/admin/hr/saln/{$saln->id}")->assertOk();
    }

    public function test_queue_lists_received_oldest_first_excluding_own(): void
    {
        $hrd = $this->userWith(['saln.manage']);
        $older = $this->saln();
        $newer = $this->saln();
        $own = $this->saln(Employee::factory()->for($committee = $this->userWith(['saln.review']))->create());
        foreach ([$older, $newer, $own] as $i => $saln) {
            $this->travel(1)->minutes();
            app(SalnFilings::class)->receive($saln, 1, $hrd);
        }

        $this->actingAs($committee)->get('/admin/hr/saln/queue')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/HR/Saln/Queue')
                ->where('received', fn ($rows) => collect($rows)->pluck('id')->all() === [$older->id, $newer->id]));
    }

    public function test_receive_by_reference_and_decide(): void
    {
        $hrd = $this->userWith(['saln.manage']);
        $committee = $this->userWith(['saln.review']);
        $saln = $this->saln();

        $this->actingAs($hrd)->post('/admin/hr/saln/receive', ['reference' => 'IMIS '.$saln->reference()])->assertSessionHasNoErrors();
        $this->assertSame(SalnStatus::Received, $saln->fresh()->status);

        $this->actingAs($committee)->post("/admin/hr/saln/{$saln->id}/decide", ['decision' => 'return', 'remarks' => ''])->assertSessionHasErrors('remarks');
        $this->actingAs($committee)->post("/admin/hr/saln/{$saln->id}/decide", ['decision' => 'clear'])->assertSessionHasNoErrors();
        $this->assertSame(SalnStatus::Cleared, $saln->fresh()->status);
    }

    public function test_an_old_printout_is_refused_by_reference(): void
    {
        $saln = $this->saln();
        app(SalnFilings::class)->generate($saln, $saln->employee->user);

        $this->actingAs($this->userWith(['saln.manage']))->post('/admin/hr/saln/receive', ['reference' => $saln->reference().' v1'])
            ->assertSessionHasErrors('reference');
    }

    public function test_compliance_page_counts(): void
    {
        $this->saln();
        Employee::factory()->create(['original_appointment_date' => '2015-01-01']);

        $this->actingAs($this->userWith(['saln.manage']))->get('/admin/hr/saln?year=2026')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/HR/Saln/Index')
                ->where('counts.required', 2)->where('counts.in_progress', 1)->where('counts.missing', 1));
    }

    public function test_print_of_other_employee_is_audited(): void
    {
        $saln = $this->saln();

        $this->actingAs($this->userWith(['saln.manage']))->get("/admin/hr/saln/{$saln->id}/versions/1/print")->assertOk();

        $this->assertTrue(AuditLog::query()->where('event', 'saln.printed')->exists());
    }

    public function test_employee_upload_only_when_cleared(): void
    {
        $saln = $this->saln();
        $user = $saln->employee->user;

        $this->actingAs($user)->post("/my/saln/{$saln->id}/file", ['file' => $this->scan()])->assertForbidden();

        $filings = app(SalnFilings::class);
        $filings->receive($saln, 1, $this->userWith(['saln.manage']));
        $filings->decide($saln->fresh(), true, null, $this->userWith(['saln.review']));

        $this->actingAs($user)->post("/my/saln/{$saln->id}/file", ['file' => $this->scan()])->assertSessionHasNoErrors();
        $this->assertSame(SalnStatus::Filed, $saln->fresh()->status);
    }

    public function test_hrd_records_an_exemption(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWith(['saln.manage']))
            ->post('/admin/hr/saln/exemptions', ['employee_id' => $employee->id, 'year' => 2026, 'reason' => 'Laborer position'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Laborer position', $employee->salnExemptions()->value('reason'));
    }

    public function test_multiple_marriages_are_hidden_until_hrd_turns_them_on(): void
    {
        $saln = $this->saln(generated: false);
        $user = $saln->employee->user;

        $this->actingAs($user)->get("/my/saln/{$saln->id}")
            ->assertInertia(fn (Assert $page) => $page->where('options.multipleMarriages', false));

        $this->actingAs($user)->post('/admin/hr/saln/settings', ['multiple_marriages' => true])->assertForbidden();
        $this->actingAs($this->userWith(['saln.manage']))->post('/admin/hr/saln/settings', ['multiple_marriages' => true])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)->get("/my/saln/{$saln->id}")
            ->assertInertia(fn (Assert $page) => $page->where('options.multipleMarriages', true));
        $this->assertTrue(AuditLog::query()->where('event', 'setting.updated')->exists());
    }

    private function saln(?Employee $employee = null, bool $generated = true): Saln
    {
        $employee ??= Employee::factory()->create(['original_appointment_date' => '2015-01-01']);
        $filings = app(SalnFilings::class);
        $user = $employee->user ?? User::factory()->create();
        $saln = $filings->open($employee, SalnType::Annual, CarbonImmutable::parse('2026-12-31'), $user);

        if ($generated) {
            $filings->save($saln, $this->validStatement(), SalnOathMode::Notary, null, null, $user);
            $filings->generate($saln->fresh(), $user);
        }

        return $saln->fresh();
    }

    private function scan(): UploadedFile
    {
        return UploadedFile::fake()->create('signed.pdf', 200, 'application/pdf');
    }
}
