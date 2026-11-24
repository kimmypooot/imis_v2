<?php

namespace Tests\Feature\HR;

use App\Models\DtrAdjustment;
use App\Models\DtrSignature;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\HR\Dtr\DtrSignatures;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Signing a finished DTR sheet, and telling later whether it is still what
 * was signed.
 */
class DtrSignaturesTest extends TestCase
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

    public function test_signing_stores_the_hash_a_code_and_a_snapshot_of_the_signature(): void
    {
        $signature = $this->sign('2026-10-01', '2026-10-18');

        $this->assertSame(64, strlen($signature->content_hash));
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{12}$/', $signature->code);
        $this->assertSame($this->employee->user_id, $signature->signed_by);
        $this->assertNotSame('signatures/mine.png', $signature->signature_path);
        $this->assertTrue(Storage::disk('local')->exists($signature->signature_path));
        $this->assertSame('valid', (new DtrSignatures)->status($signature));
    }

    public function test_the_hash_is_stable_between_calls(): void
    {
        $service = new DtrSignatures;

        $this->assertSame(
            $service->hashFor($this->employee, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-18')),
            $service->hashFor($this->employee, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-18')),
        );
    }

    public function test_signing_again_replaces_the_earlier_signing(): void
    {
        $first = $this->sign('2026-10-01', '2026-10-18');
        $second = $this->sign('2026-10-01', '2026-10-18');
        $service = new DtrSignatures;

        $this->assertSame($second->id, $service->current($this->employee, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-18'))->id);
        $this->assertSame('replaced', $service->status($first->fresh()));
        $this->assertSame('valid', $service->status($second));
        $this->assertNotSame($first->code, $second->code);
    }

    public function test_an_adjustment_made_afterwards_makes_the_signed_sheet_changed(): void
    {
        $signature = $this->sign('2026-10-01', '2026-10-18');

        DtrAdjustment::factory()->create(['employee_id' => $this->employee->id, 'date' => '2026-10-05', 'kind' => 'credit', 'remark' => 'Event']);

        $this->assertSame('changed', (new DtrSignatures)->status($signature));
    }

    public function test_renaming_a_leave_type_does_not_change_a_signed_sheet(): void
    {
        $this->seed(LeaveTypeSeeder::class);
        EmployeeWorkSchedule::factory()->create(['employee_id' => $this->employee->id, 'work_schedule_id' => WorkSchedule::query()->where('name', 'like', 'Compressed%')->value('id'), 'valid_from' => '2026-01-01']);
        LeaveApplication::query()->create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => LeaveType::query()->where('code', 'VL')->value('id'),
            'date_from' => '2026-10-06', 'date_to' => '2026-10-06', 'days' => 1, 'status' => 'approved',
        ]);
        $signature = $this->sign('2026-10-01', '2026-10-18');

        LeaveType::query()->where('code', 'VL')->update(['name' => 'Vacation Leave (VL)']);

        $this->assertSame('valid', (new DtrSignatures)->status($signature));
    }

    public function test_changing_the_reason_on_an_adjustment_makes_a_signed_sheet_changed(): void
    {
        $adjustment = DtrAdjustment::factory()->create(['employee_id' => $this->employee->id, 'date' => '2026-10-05', 'kind' => 'credit', 'remark' => 'Event']);
        $signature = $this->sign('2026-10-01', '2026-10-18');

        $adjustment->update(['remark' => 'A different reason']);

        $this->assertSame('changed', (new DtrSignatures)->status($signature));
    }

    public function test_a_sheet_that_has_not_finished_cannot_be_signed(): void
    {
        $this->expectException(ValidationException::class);

        (new DtrSignatures)->sign($this->employee, $this->employee->user, today()->startOfMonth(), today());
    }

    public function test_someone_without_a_saved_signature_cannot_sign(): void
    {
        $this->employee->user->forceFill(['signature_path' => null])->save();

        $this->expectException(ValidationException::class);
        $this->sign('2026-10-01', '2026-10-18');
    }

    public function test_only_the_employee_can_sign_their_own_sheet(): void
    {
        $this->expectException(ValidationException::class);

        (new DtrSignatures)->sign($this->employee, User::factory()->create(), Carbon::parse('2026-10-01'), Carbon::parse('2026-10-18'));
    }

    public function test_a_range_over_more_than_one_month_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->sign('2026-10-20', '2026-11-05');
    }

    public function test_find_resolves_a_code_and_nothing_else(): void
    {
        $signature = $this->sign('2026-10-01', '2026-10-18');
        $service = new DtrSignatures;

        $this->assertSame($signature->id, $service->find($signature->code)->id);
        $this->assertNull($service->find('NOTACODE0000'));
        $this->assertNull($service->find(''));
    }

    private function sign(string $from, string $to): DtrSignature
    {
        return (new DtrSignatures)->sign($this->employee, $this->employee->user, Carbon::parse($from), Carbon::parse($to));
    }
}
