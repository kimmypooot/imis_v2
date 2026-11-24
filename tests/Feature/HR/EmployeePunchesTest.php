<?php

namespace Tests\Feature\HR;

use App\Models\BiometricImport;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Support\HR\Dtr\EmployeePunches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * An employee's punches by day, found by the biometric ID on their record.
 */
class EmployeePunchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_and_last_punch_of_the_day_are_in_and_out(): void
    {
        $employee = $this->employee('906');
        $this->punch('906', ['2026-03-04 20:41:36', '2026-03-04 08:01:09', '2026-03-04 12:30:00']);

        $day = $this->byDay($employee, '2026-03-04', '2026-03-04')->get('2026-03-04');

        $this->assertSame(['08:01', '20:41', 3], [$day['in']->format('H:i'), $day['out']->format('H:i'), $day['count']]);
    }

    public function test_every_kept_punch_of_the_day_is_listed_in_order(): void
    {
        $employee = $this->employee('906');
        $this->punch('906', ['2026-03-04 19:00:00', '2026-03-04 07:30:00', '2026-03-04 12:32:00', '2026-03-04 12:14:00']);

        $day = $this->byDay($employee, '2026-03-04', '2026-03-04')->get('2026-03-04');

        $this->assertSame(['07:30', '12:14', '12:32', '19:00'], array_map(fn ($time) => $time->format('H:i'), $day['times']));
    }

    public function test_a_punch_after_midnight_belongs_to_the_previous_day(): void
    {
        $employee = $this->employee('906');
        $this->punch('906', ['2026-03-04 07:00:00', '2026-03-04 23:50:00', '2026-03-05 00:20:00']);

        $days = $this->byDay($employee, '2026-03-04', '2026-03-05');

        $this->assertSame('00:20', $days->get('2026-03-04')['out']->format('H:i'));
        $this->assertFalse($days->has('2026-03-05'));
    }

    public function test_punches_within_two_minutes_count_once(): void
    {
        $employee = $this->employee('959');
        $this->punch('959', ['2026-03-04 07:15:51', '2026-03-04 07:15:58', '2026-03-04 21:01:42']);

        $day = $this->byDay($employee, '2026-03-04', '2026-03-04')->get('2026-03-04');

        $this->assertSame(2, $day['count']);
        $this->assertSame('07:15', $day['in']->format('H:i'));
    }

    public function test_only_the_days_asked_for_are_returned(): void
    {
        $employee = $this->employee('906');
        $this->punch('906', ['2026-03-01 08:00:00', '2026-03-02 08:00:00', '2026-03-04 18:00:00', '2026-03-05 08:00:00']);

        $days = $this->byDay($employee, '2026-03-02', '2026-03-04');

        $this->assertSame(['2026-03-02', '2026-03-04'], $days->keys()->all());
    }

    public function test_punches_from_separate_uploads_come_together_for_the_same_day(): void
    {
        $employee = $this->employee('906');
        $this->punch('906', ['2026-03-04 07:55:00']);
        $this->punch('906', ['2026-03-04 17:40:00']);

        $day = $this->byDay($employee, '2026-03-04', '2026-03-04')->get('2026-03-04');

        $this->assertSame(['07:55', '17:40', 2], [$day['in']->format('H:i'), $day['out']->format('H:i'), $day['count']]);
    }

    public function test_another_employees_punches_are_not_included(): void
    {
        $employee = $this->employee('906');
        $other = $this->employee('959');
        $this->punch('959', ['2026-03-04 08:00:00']);

        $this->assertTrue($this->byDay($employee, '2026-03-04', '2026-03-04')->isEmpty());
        $this->assertCount(1, $this->byDay($other, '2026-03-04', '2026-03-04'));
    }

    public function test_punches_under_an_id_nobody_has_are_not_counted(): void
    {
        $employee = $this->employee('906');
        $this->punch('777', ['2026-03-04 08:00:00']);

        $this->assertTrue($this->byDay($employee, '2026-03-04', '2026-03-04')->isEmpty());
    }

    public function test_an_employee_without_a_biometric_id_has_no_punches(): void
    {
        $this->punch('906', ['2026-03-04 08:00:00']);

        $this->assertTrue($this->byDay($this->employee(null), '2026-03-01', '2026-03-31')->isEmpty());
    }

    public function test_changing_the_id_on_the_record_moves_the_punches_with_it(): void
    {
        $employee = $this->employee('906');
        $this->punch('906', ['2026-03-04 08:00:00']);
        $this->punch('1744', ['2026-03-05 08:00:00']);

        $this->assertSame(['2026-03-04'], $this->byDay($employee, '2026-03-01', '2026-03-31')->keys()->all());

        $employee->update(['biometric_id' => '1744']);

        $this->assertSame(['2026-03-05'], $this->byDay($employee->fresh(), '2026-03-01', '2026-03-31')->keys()->all());
    }

    private function employee(?string $biometricId): Employee
    {
        return Employee::factory()->create(['biometric_id' => $biometricId]);
    }

    /**
     * @param  list<string>  $times
     */
    private function punch(string $biometricId, array $times): void
    {
        $import = BiometricImport::factory()->create();

        foreach ($times as $time) {
            BiometricPunch::query()->create(['biometric_id' => $biometricId, 'punched_at' => $time, 'biometric_import_id' => $import->id]);
        }
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    private function byDay(Employee $employee, string $from, string $to): Collection
    {
        return (new EmployeePunches)->byDay($employee, Carbon::parse($from), Carbon::parse($to));
    }
}
