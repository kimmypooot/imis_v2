<?php

namespace Tests\Feature\HR;

use App\Models\DtrAdjustment;
use App\Models\Employee;
use App\Models\User;
use App\Support\HR\Dtr\DtrAdjustments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * HRD's day-by-day corrections to a DTR: a full-day credit, a travel day,
 * or the day's in and out times.
 */
class DtrAdjustmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_kind_can_be_saved(): void
    {
        $employee = Employee::factory()->create();
        $hrd = User::factory()->create();

        $credit = $this->save($employee, '2026-10-05', 'credit', null, null, 'Pasasalamat Program', $hrd);
        $travel = $this->save($employee, '2026-10-06', 'travel', null, null, 'Travel to Tacloban', $hrd);
        $times = $this->save($employee, '2026-10-07', 'times', '07:45', '18:00', 'Forgot to punch out', $hrd);

        $this->assertSame(['credit', 'travel', 'times'], [$credit->kind, $travel->kind, $times->kind]);
        $this->assertSame(['07:45', '18:00'], [substr($times->time_in, 0, 5), substr($times->time_out, 0, 5)]);
        $this->assertSame($hrd->id, $credit->created_by);
    }

    public function test_a_second_save_on_the_same_day_replaces_the_first(): void
    {
        $employee = Employee::factory()->create();
        $this->save($employee, '2026-10-05', 'credit', null, null, 'Event', User::factory()->create());

        $this->save($employee, '2026-10-05', 'travel', null, null, 'Travel', User::factory()->create());

        $this->assertSame(1, DtrAdjustment::query()->count());
        $this->assertSame('travel', DtrAdjustment::query()->sole()->kind);
    }

    public function test_times_need_at_least_one_time(): void
    {
        $this->expectException(ValidationException::class);

        $this->save(Employee::factory()->create(), '2026-10-05', 'times', null, null, 'x', User::factory()->create());
    }

    public function test_time_out_must_be_after_time_in(): void
    {
        $this->expectException(ValidationException::class);

        $this->save(Employee::factory()->create(), '2026-10-05', 'times', '18:00', '07:45', 'x', User::factory()->create());
    }

    public function test_a_future_date_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->save(Employee::factory()->create(), now()->addDay()->toDateString(), 'credit', null, null, 'x', User::factory()->create());
    }

    public function test_an_unknown_kind_and_a_blank_remark_are_rejected(): void
    {
        $employee = Employee::factory()->create();
        $hrd = User::factory()->create();

        foreach ([['holiday', 'x'], ['credit', ''], ['credit', str_repeat('x', 256)]] as [$kind, $remark]) {
            try {
                $this->save($employee, '2026-10-05', $kind, null, null, $remark, $hrd);
                $this->fail("Accepted kind {$kind} with remark of length ".strlen($remark));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_credit_and_travel_ignore_any_times_given(): void
    {
        $adjustment = $this->save(Employee::factory()->create(), '2026-10-05', 'credit', '07:45', '18:00', 'Event', User::factory()->create());

        $this->assertNull($adjustment->time_in);
        $this->assertNull($adjustment->time_out);
    }

    public function test_remove_deletes_the_adjustment(): void
    {
        $adjustment = $this->save(Employee::factory()->create(), '2026-10-05', 'credit', null, null, 'Event', User::factory()->create());

        (new DtrAdjustments)->remove($adjustment);

        $this->assertSame(0, DtrAdjustment::query()->count());
    }

    private function save(Employee $employee, string $date, string $kind, ?string $in, ?string $out, string $remark, User $actor): DtrAdjustment
    {
        return (new DtrAdjustments)->save($employee, Carbon::parse($date), $kind, $in, $out, $remark, $actor);
    }
}
