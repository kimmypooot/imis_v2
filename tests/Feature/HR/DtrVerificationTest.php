<?php

namespace Tests\Feature\HR;

use App\Models\DtrAdjustment;
use App\Models\DtrSignature;
use App\Models\Employee;
use App\Support\HR\Dtr\DtrSignatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Anyone with a printed DTR can check its code: whether the sheet is still
 * exactly what the employee signed. Only a status, the name, the period and
 * the signing time are shown, never the hours or punches.
 */
class DtrVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::disk('local')->put('signatures/mine.png', 'png');
        $this->employee = Employee::factory()->create();
        $this->employee->user->forceFill(['signature_path' => 'signatures/mine.png'])->save();
    }

    public function test_a_guest_can_check_a_valid_code_and_sees_only_the_basics(): void
    {
        $signature = $this->sign();

        $this->get("/dtr/verify/{$signature->code}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DtrVerify')
                ->where('state', 'valid')
                ->where('employee', $this->employee->user->name)
                ->where('period', 'October 1-18, 2026')
                ->has('signed_at')
                ->missing('totals')
                ->missing('rows')
                ->missing('sheet'));
    }

    public function test_the_code_is_case_insensitive(): void
    {
        $signature = $this->sign();

        $this->get('/dtr/verify/'.strtolower($signature->code))->assertInertia(fn (Assert $page) => $page->where('state', 'valid'));
    }

    public function test_a_sheet_changed_after_signing_says_so(): void
    {
        $signature = $this->sign();
        DtrAdjustment::factory()->create(['employee_id' => $this->employee->id, 'date' => '2026-10-05', 'kind' => 'credit', 'remark' => 'Event']);

        $this->get("/dtr/verify/{$signature->code}")->assertInertia(fn (Assert $page) => $page->where('state', 'changed'));
    }

    public function test_an_older_code_says_it_was_replaced(): void
    {
        $first = $this->sign();
        $this->sign();

        $this->get("/dtr/verify/{$first->code}")->assertInertia(fn (Assert $page) => $page->where('state', 'replaced'));
    }

    public function test_an_unknown_or_malformed_code_is_not_found_and_reveals_nothing(): void
    {
        foreach (['ABCDEFGH2345', 'short', 'bad%20code', str_repeat('A', 60)] as $code) {
            $this->get('/dtr/verify/'.$code)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('DtrVerify')->where('state', 'not_found')->missing('employee')->missing('period'));
        }
    }

    public function test_checking_a_code_leaves_no_audit_entry(): void
    {
        $signature = $this->sign();

        $this->get("/dtr/verify/{$signature->code}");

        $this->assertDatabaseMissing('audit_logs', ['event' => 'dtr.viewed']);
    }

    private function sign(): DtrSignature
    {
        return (new DtrSignatures)->sign($this->employee, $this->employee->user, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-18'));
    }
}
