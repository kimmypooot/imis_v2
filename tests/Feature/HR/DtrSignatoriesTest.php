<?php

namespace Tests\Feature\HR;

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\DtrSignatory;
use App\Models\DtrSignature;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * An employee picks who verifies each of their DTR sheets, because
 * supervisors are sometimes away at short notice. The name prints with the
 * honorific the account has.
 */
class DtrSignatoriesTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = ['from' => '2026-10-01', 'to' => '2026-10-31'];

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::disk('local')->put('signatures/mine.png', 'png');
        $this->employee = Employee::factory()->create();
        $this->employee->user->forceFill(['signature_path' => 'signatures/mine.png'])->save();
    }

    public function test_the_picked_signatory_prints_with_their_honorific_and_is_audited(): void
    {
        $chief = User::factory()->create(['honorific' => 'Atty.', 'first_name' => 'Jay', 'middle_name' => 'Mutya', 'last_name' => 'Merelos', 'name' => 'Atty. Jay M. Merelos']);

        $this->actingAs($this->employee->user)
            ->post('/my/dtr/signatory', [...self::SHEET, 'signatory_id' => $chief->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->employee->user)->get('/my/dtr/print?from=2026-10-01&to=2026-10-31')
            ->assertInertia(fn (Assert $page) => $page
                ->where('sheet.head', 'Atty. Jay M. Merelos')
                ->where('sheet.signatory.id', $chief->id)
                ->where('sheet.signatory.chosen', true));

        $this->assertSame(1, AuditLog::query()->where('event', 'dtr.signatory_chosen')->count());
    }

    public function test_going_back_to_the_office_head_clears_the_pick(): void
    {
        $chief = User::factory()->create();
        DtrSignatory::factory()->create(['employee_id' => $this->employee->id, 'signatory_user_id' => $chief->id]);

        $this->actingAs($this->employee->user)->post('/my/dtr/signatory', [...self::SHEET, 'signatory_id' => null])->assertSessionHasNoErrors();

        $this->assertSame(0, DtrSignatory::query()->count());
        $this->actingAs($this->employee->user)->get('/my/dtr/print?from=2026-10-01&to=2026-10-31')
            ->assertInertia(fn (Assert $page) => $page->where('sheet.head', null)->where('sheet.signatory', null));
    }

    public function test_you_cannot_pick_yourself_or_someone_inactive(): void
    {
        $this->actingAs($this->employee->user)->post('/my/dtr/signatory', [...self::SHEET, 'signatory_id' => $this->employee->user_id])
            ->assertSessionHasErrors('signatory_id');

        $gone = User::factory()->create(['status' => UserStatus::Inactive]);
        $this->actingAs($this->employee->user)->post('/my/dtr/signatory', [...self::SHEET, 'signatory_id' => $gone->id])
            ->assertSessionHasErrors('signatory_id');

        $this->assertSame(0, DtrSignatory::query()->count());
    }

    public function test_picking_after_signing_makes_the_signing_stale_but_leaves_other_signings_alone(): void
    {
        $this->actingAs($this->employee->user)->post('/my/dtr/sign', [...self::SHEET, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->actingAs($this->employee->user)->get('/my/dtr/print?from=2026-10-01&to=2026-10-31')
            ->assertInertia(fn (Assert $page) => $page->where('sheet.signature.status', 'valid'));

        $chief = User::factory()->create();
        $this->actingAs($this->employee->user)->post('/my/dtr/signatory', [...self::SHEET, 'signatory_id' => $chief->id]);

        $this->actingAs($this->employee->user)->get('/my/dtr/print?from=2026-10-01&to=2026-10-31')
            ->assertInertia(fn (Assert $page) => $page->where('sheet.signature.status', 'changed'));

        // Signing again fingerprints the sheet with that signatory.
        $this->actingAs($this->employee->user)->post('/my/dtr/sign', [...self::SHEET, 'password' => 'password']);
        $this->assertSame(2, DtrSignature::query()->count());
        $this->actingAs($this->employee->user)->get('/my/dtr/print?from=2026-10-01&to=2026-10-31')
            ->assertInertia(fn (Assert $page) => $page->where('sheet.signature.status', 'valid'));
    }

    public function test_the_page_offers_everyone_active_but_you_with_the_supervisor_first(): void
    {
        $supervisor = User::factory()->create(['name' => 'Zed Supervisor']);
        $this->employee->forceFill(['immediate_supervisor_id' => $supervisor->id])->save();
        $other = User::factory()->create(['name' => 'Abe Other']);
        User::factory()->create(['status' => UserStatus::Inactive]);

        $this->actingAs($this->employee->user)->get('/my/dtr?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('signatoryOptions.0.value', $supervisor->id)
                ->where('signatoryOptions.0.group', 'Suggested')
                ->where('signatoryOptions.1.value', $other->id)
                ->where('signatoryOptions.1.group', 'Everyone else')
                ->has('signatoryOptions', 2));
    }

    public function test_a_pick_is_for_one_employee_and_one_sheet(): void
    {
        $chief = User::factory()->create();
        $colleague = Employee::factory()->create();

        $this->actingAs($this->employee->user)->post('/my/dtr/signatory', [...self::SHEET, 'signatory_id' => $chief->id]);

        $this->actingAs($colleague->user)->get('/my/dtr/print?from=2026-10-01&to=2026-10-31')
            ->assertInertia(fn (Assert $page) => $page->where('sheet.signatory', null));
        $this->actingAs($this->employee->user)->get('/my/dtr/print?from=2026-10-01&to=2026-10-18')
            ->assertInertia(fn (Assert $page) => $page->where('sheet.signatory', null));
    }
}
