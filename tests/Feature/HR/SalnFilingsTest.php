<?php

namespace Tests\Feature\HR;

use App\Enums\EmployeeDocumentType;
use App\Enums\HeadCapacity;
use App\Enums\SalnOathMode;
use App\Enums\SalnStatus;
use App\Enums\SalnType;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationalUnit;
use App\Models\Saln;
use App\Models\User;
use App\Notifications\HrUpdate;
use App\Support\HR\Saln\SalnFilings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Feature\HR\Concerns\SalnFixtures;
use Tests\TestCase;

/**
 * The SALN workflow: encode, print (a numbered version), HRD receives the
 * hard copy, the Committee (or HRD) clears or returns it, and the signed,
 * sworn scan is filed in the 201 File.
 */
class SalnFilingsTest extends TestCase
{
    use RefreshDatabase, SalnFixtures;

    private SalnFilings $filings;

    private Employee $employee;

    private User $hrd;

    private User $committee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        $this->regionalDirector();
        $this->filings = app(SalnFilings::class);
        $this->employee = Employee::factory()->create();
        $this->hrd = $this->userWith(['saln.manage']);
        $this->committee = $this->userWith(['saln.review']);
    }

    public function test_the_whole_way_from_draft_to_filed(): void
    {
        $saln = $this->generated();

        $this->filings->receive($saln, 1, $this->hrd);
        $this->filings->decide($saln, true, null, $this->committee);
        $document = $this->filings->file($saln, $this->scan(), $this->employee->user);

        $saln->refresh();
        $this->assertSame(SalnStatus::Filed, $saln->status);
        $this->assertSame($document->id, $saln->employee_document_id);
        $this->assertSame(EmployeeDocumentType::Saln, $document->document_type);
        $this->assertSame('SALN — Annual filing 2025', $document->title);
        $this->assertSame('2025-12-31', $document->document_date->toDateString());
        $this->assertSame(['generated', 'received', 'cleared', 'filed'], $saln->events()->orderBy('id')->pluck('action')->all());
    }

    public function test_generating_freezes_a_numbered_version(): void
    {
        $saln = $this->opened();
        $this->save($saln);

        $version = $this->filings->generate($saln, $this->employee->user);

        $this->assertSame(1, $version->version);
        $this->assertSame($saln->reference(), $version->reference);
        $this->assertSame('45000.00', $version->statement['totals']['net_worth']);
        $this->assertSame('Atty. Regional Director', $version->officer_name);
        $this->assertSame('Regional Director', $version->officer_title);
        $this->assertSame(SalnStatus::Generated, $saln->fresh()->status);
    }

    public function test_an_annual_saln_is_as_of_december_31(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->filings->open($this->employee, SalnType::Annual, CarbonImmutable::parse('2025-11-30'), $this->employee->user);
    }

    public function test_one_saln_per_type_and_date(): void
    {
        $this->opened();

        $this->expectException(InvalidArgumentException::class);

        $this->opened();
    }

    public function test_cannot_receive_a_superseded_version(): void
    {
        $saln = $this->generated();
        $this->filings->generate($saln, $this->employee->user);

        try {
            $this->filings->receive($saln->fresh(), 1, $this->hrd);
            $this->fail('An older printout was received.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame("Only the latest printout, {$saln->reference()} v2, can be received.", $e->getMessage());
        }
    }

    public function test_editing_is_locked_after_receipt(): void
    {
        $saln = $this->generated();
        $this->filings->receive($saln, 1, $this->hrd);

        $this->expectException(InvalidArgumentException::class);

        $this->save($saln->fresh());
    }

    public function test_return_requires_remarks_and_reopens_editing(): void
    {
        $saln = $this->generated();
        $this->filings->receive($saln, 1, $this->hrd);

        try {
            $this->filings->decide($saln->fresh(), false, '  ', $this->committee);
            $this->fail('Returned without remarks.');
        } catch (InvalidArgumentException) {
        }

        $this->filings->decide($saln->fresh(), false, 'Item 2: add the car loan.', $this->committee);

        $saln->refresh();
        $this->assertSame(SalnStatus::Returned, $saln->status);
        $this->assertSame('Item 2: add the car loan.', $saln->events()->latest('id')->value('remarks'));
        Notification::assertSentTo($this->employee->user, HrUpdate::class);

        $this->save($saln);
        $this->assertSame(2, $this->filings->generate($saln->fresh(), $this->employee->user)->version);
    }

    public function test_nobody_decides_their_own_saln(): void
    {
        $hrdEmployee = Employee::factory()->for($this->hrd)->create();
        $saln = $this->generated($hrdEmployee);
        $this->filings->receive($saln, 1, $this->userWith(['saln.manage']));

        $this->assertFalse(Gate::forUser($this->hrd)->allows('decide', $saln->fresh()));
        $this->expectException(InvalidArgumentException::class);

        $this->filings->decide($saln->fresh(), true, null, $this->hrd);
    }

    public function test_hrd_decision_without_review_permission_is_an_override(): void
    {
        $saln = $this->generated();
        $this->filings->receive($saln, 1, $this->hrd);

        $this->filings->decide($saln->fresh(), true, null, $this->hrd);

        $this->assertTrue($saln->events()->where('action', 'cleared')->value('override'));
    }

    public function test_regional_director_cannot_swear_before_themselves(): void
    {
        $director = User::query()->where('position', 'Director IV')->firstOrFail();
        $saln = $this->opened(Employee::factory()->for($director)->create());

        $this->expectException(InvalidArgumentException::class);

        $this->save($saln);
    }

    public function test_an_oic_of_the_ord_cannot_swear_before_themselves_either(): void
    {
        $oicEmployee = Employee::factory()->create();
        $ord = OrganizationalUnit::query()->where('code', 'ORD')->firstOrFail();
        EmployeeAssignment::query()->forceCreate([
            'employee_id' => $oicEmployee->id, 'kind' => 'designation', 'to_unit_id' => $ord->id,
            'as_head' => true, 'head_capacity' => HeadCapacity::OfficerInCharge->value, 'effective_date' => today()->subDay(),
        ]);
        $saln = $this->opened($oicEmployee);

        try {
            $this->save($saln);
            $this->fail('The OIC swore before themselves.');
        } catch (InvalidArgumentException) {
        }

        $this->filings->save($saln, $this->validStatement(), SalnOathMode::OtherOfficer, 'Atty. Delegated Officer', 'Director III', $oicEmployee->user);
        $this->assertSame('Atty. Delegated Officer', $this->filings->generate($saln->fresh(), $oicEmployee->user)->officer_name);
    }

    public function test_an_oic_of_the_ord_is_printed_as_officer_in_charge(): void
    {
        $oic = Employee::factory()->create();
        $ord = OrganizationalUnit::query()->where('code', 'ORD')->firstOrFail();
        EmployeeAssignment::query()->forceCreate([
            'employee_id' => $oic->id, 'kind' => 'designation', 'to_unit_id' => $ord->id,
            'as_head' => true, 'head_capacity' => HeadCapacity::OfficerInCharge->value, 'effective_date' => today()->subDay(),
        ]);

        $version = $this->filings->generate($this->saved(), $this->employee->user);

        $this->assertSame($oic->user->name, $version->officer_name);
        $this->assertSame('Officer-in-Charge, Office of the Regional Director', $version->officer_title);
    }

    public function test_officer_name_comes_from_the_version_snapshot(): void
    {
        $version = $this->filings->generate($this->saved(), $this->employee->user);

        User::query()->where('position', 'Director IV')->update(['name' => 'A New Director']);

        $this->assertSame('Atty. Regional Director', $version->fresh()->officer_name);
    }

    public function test_a_notary_leaves_the_officer_blank(): void
    {
        $saln = $this->opened();
        $this->filings->save($saln, $this->validStatement(), SalnOathMode::Notary, null, null, $this->employee->user);

        $version = $this->filings->generate($saln->fresh(), $this->employee->user);

        $this->assertNull($version->officer_name);
        $this->assertNull($version->officer_title);
    }

    public function test_filing_needs_a_cleared_saln(): void
    {
        $saln = $this->generated();

        $this->expectException(InvalidArgumentException::class);

        $this->filings->file($saln, $this->scan(), $this->hrd);
    }

    public function test_each_transition_is_audited(): void
    {
        $saln = $this->generated();
        $this->filings->receive($saln, 1, $this->hrd);
        $this->filings->decide($saln->fresh(), true, null, $this->committee);
        $this->filings->file($saln->fresh(), $this->scan(), $this->hrd);
        $this->filings->exempt(Employee::factory()->create(), 2025, 'Laborer position', $this->hrd);

        $this->assertEqualsCanonicalizing(
            ['saln.generated', 'saln.received', 'saln.cleared', 'saln.filed', 'saln.exempted'],
            AuditLog::query()->where('event', 'like', 'saln.%')->get()->map(fn (AuditLog $log) => $log->getRawOriginal('event'))->all(),
        );
    }

    public function test_editing_after_printing_voids_the_printout_until_printed_again(): void
    {
        $saln = $this->generated();
        $changed = $this->validStatement(['liabilities' => [['owner' => 'declarant', 'nature' => 'Car loan', 'creditor' => 'BPI', 'outstanding_balance' => '100000.00']]]);

        $this->filings->save($saln, $changed, SalnOathMode::RegionalDirector, null, null, $this->employee->user);

        $this->assertSame(SalnStatus::Draft, $saln->fresh()->status);
        $this->expectException(InvalidArgumentException::class);
        $this->filings->receive($saln->fresh(), 1, $this->hrd);
    }

    public function test_saving_unchanged_entries_keeps_the_printout(): void
    {
        $saln = $this->generated();

        $this->save($saln);

        $this->assertSame(SalnStatus::Generated, $saln->fresh()->status);
    }

    public function test_a_stale_copy_cannot_print_over_a_receipt(): void
    {
        $stale = $this->generated();
        $this->filings->receive(Saln::query()->find($stale->id), 1, $this->hrd);

        try {
            $this->filings->generate($stale, $this->employee->user);
            $this->fail('A stale copy printed over the receipt.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(SalnStatus::Received, $stale->fresh()->status);
        $this->assertSame(1, $stale->fresh()->current_version);
    }

    public function test_a_stale_copy_cannot_decide_twice(): void
    {
        $saln = $this->generated();
        $this->filings->receive($saln, 1, $this->hrd);
        $stale = $saln->fresh();
        $this->filings->decide($saln->fresh(), true, null, $this->committee);

        $this->expectException(InvalidArgumentException::class);
        $this->filings->decide($stale, false, 'Too late', $this->userWith(['saln.review']));
    }

    public function test_the_oath_is_checked_again_when_printing(): void
    {
        $saln = $this->saved();
        $ord = OrganizationalUnit::query()->where('code', 'ORD')->firstOrFail();
        EmployeeAssignment::query()->forceCreate([
            'employee_id' => $this->employee->id, 'kind' => 'designation', 'to_unit_id' => $ord->id,
            'as_head' => true, 'head_capacity' => HeadCapacity::OfficerInCharge->value, 'effective_date' => today()->subDay(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->filings->generate($saln, $this->employee->user);
    }

    public function test_the_server_works_out_childrens_ages(): void
    {
        $saln = $this->opened();
        $statement = $this->validStatement(['children' => [['name' => 'Typed Seventeen', 'birth_date' => '2007-06-01', 'age' => 17]]]);

        try {
            $this->filings->save($saln, $statement, SalnOathMode::Notary, null, null, $this->employee->user);
            $this->fail('An 18-year-old was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('children.0.birth_date', $e->errors());
        }

        $statement['children'][0] = ['name' => 'Ten', 'birth_date' => '2015-06-01', 'age' => 3];
        $this->filings->save($saln, $statement, SalnOathMode::Notary, null, null, $this->employee->user);
        $this->assertSame(10, $saln->fresh()->statement['children'][0]['age']);
    }

    public function test_nobody_exempts_themselves(): void
    {
        Employee::factory()->for($this->hrd)->create();

        $this->expectException(InvalidArgumentException::class);
        $this->filings->exempt($this->hrd->employee, 2025, 'Mine', $this->hrd);
    }

    public function test_amounts_may_be_typed_with_commas(): void
    {
        $saln = $this->opened();
        $statement = $this->validStatement(['personal_properties' => [['owner' => 'declarant', 'description' => 'Car', 'acquisition_year' => '2020', 'acquisition_cost' => '1,250,000.50']]]);

        $this->filings->save($saln, $statement, SalnOathMode::Notary, null, null, $this->employee->user);

        $this->assertSame('1250000.50', $saln->fresh()->statement['personal_properties'][0]['acquisition_cost']);
    }

    public function test_hrd_saving_on_someones_behalf_is_audited(): void
    {
        $saln = $this->opened();

        $this->filings->save($saln, $this->validStatement(), SalnOathMode::Notary, null, null, $this->hrd);

        $this->assertTrue(AuditLog::query()->where('event', 'saln.updated')->exists());
    }

    private function opened(?Employee $employee = null): Saln
    {
        $employee ??= $this->employee;

        return $this->filings->open($employee, SalnType::Annual, CarbonImmutable::parse('2025-12-31'), $employee->user ?? $this->hrd);
    }

    private function save(Saln $saln): void
    {
        $this->filings->save($saln, $this->validStatement(), SalnOathMode::RegionalDirector, null, null, $saln->employee->user ?? $this->hrd);
    }

    private function saved(?Employee $employee = null): Saln
    {
        $saln = $this->opened($employee);
        $this->save($saln);

        return $saln->fresh();
    }

    private function generated(?Employee $employee = null): Saln
    {
        $saln = $this->saved($employee);
        $this->filings->generate($saln, $saln->employee->user ?? $this->hrd);

        return $saln->fresh();
    }

    private function scan(): UploadedFile
    {
        return UploadedFile::fake()->create('saln-signed.pdf', 300, 'application/pdf');
    }
}
