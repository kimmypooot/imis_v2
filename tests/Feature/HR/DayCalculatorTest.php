<?php

namespace Tests\Feature\HR;

use App\Models\WorkSchedule;
use App\Support\HR\Dtr\DayCalculator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Rule B for one working day. CWW: work starts 07:00, late after 08:00,
 * a full day is 600 minutes, no break. Regular: 08:00, 08:00, 480 minutes,
 * a 60-minute break from 12:00.
 */
class DayCalculatorTest extends TestCase
{
    public function test_on_time_with_a_long_day_credits_the_full_day(): void
    {
        $this->assertSame([600, 0, 0, 'present'], $this->cww('07:43', '18:58'));
        $this->assertSame([600, 0, 0, 'present'], $this->cww('07:51', '20:32'));
    }

    public function test_late_arrival_is_docked_without_offset(): void
    {
        $this->assertSame([589, 11, 0, 'late'], $this->cww('08:11', '19:20'));
        $this->assertSame([599, 1, 0, 'late'], $this->cww('08:01', '19:22'));
        $this->assertSame([581, 19, 0, 'late'], $this->cww('08:19', '19:20'));
    }

    public function test_seconds_are_ignored(): void
    {
        $this->assertSame([600, 0, 0, 'present'], $this->cww('08:00:59', '19:00'));
    }

    public function test_leaving_early_is_undertime_not_late(): void
    {
        $this->assertSame([490, 0, 110, 'undertime'], $this->cww('07:50', '16:00'));
        $this->assertSame([600, 0, 0, 'present'], $this->cww('07:45', '17:50'));
    }

    public function test_time_before_the_schedule_start_does_not_count(): void
    {
        $this->assertSame([545, 0, 55, 'undertime'], $this->cww('06:21', '16:05'));
    }

    public function test_late_and_short_is_not_double_counted(): void
    {
        $this->assertSame([469, 11, 120, 'late'], $this->cww('08:11', '16:00'));
    }

    public function test_one_punch_is_incomplete_and_credits_nothing(): void
    {
        $this->assertSame([0, 0, 0, 'incomplete'], $this->calculate($this->schedule('cww'), '07:45', null, 1));
        $this->assertSame([0, 0, 0, 'absent'], $this->calculate($this->schedule('cww'), null, null, 0));
    }

    public function test_regular_schedule_deducts_the_noon_break_only_when_worked_through(): void
    {
        $regular = $this->schedule('regular');

        $this->assertSame([480, 0, 0, 'present'], $this->calculate($regular, '07:55', '17:05'));
        $this->assertSame([460, 20, 0, 'late'], $this->calculate($regular, '08:20', '17:00'));
        $this->assertSame([240, 0, 240, 'undertime'], $this->calculate($regular, '08:00', '12:00'));
    }

    public function test_arriving_after_the_noon_break_does_not_count_the_break_as_late(): void
    {
        $regular = $this->schedule('regular');

        $this->assertSame([240, 240, 0, 'late'], $this->calculate($regular, '13:00', '17:00'));
        $this->assertSame([240, 240, 0, 'late'], $this->calculate($regular, '12:30', '17:00'));
    }

    public function test_half_day_leave_with_a_missing_other_half_is_undertime(): void
    {
        $cww = $this->schedule('cww');

        $this->assertSame([0, 0, 300, 'absent'], $this->calculate($cww, null, null, 0, 0.5));
        $this->assertSame([0, 0, 300, 'incomplete'], $this->calculate($cww, '07:50', null, 1, 0.5));
    }

    public function test_half_day_leave_judges_the_other_half_by_span(): void
    {
        $cww = $this->schedule('cww');

        $this->assertSame([300, 0, 0, 'present'], $this->calculate($cww, '07:50', '12:50', 2, 0.5));
        $this->assertSame([190, 0, 110, 'undertime'], $this->calculate($cww, '07:50', '11:00', 2, 0.5));
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: string}
     */
    private function cww(string $in, string $out): array
    {
        return $this->calculate($this->schedule('cww'), $in, $out);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: string}
     */
    private function calculate(WorkSchedule $schedule, ?string $in, ?string $out, int $punches = 2, float $leave = 0.0): array
    {
        $day = Carbon::parse('2026-10-06');
        $at = fn (?string $time) => $time ? $day->copy()->setTimeFromTimeString($time) : null;

        $result = (new DayCalculator)->forWorkingDay($schedule, $day, $at($in), $at($out), $punches, $leave);

        return [$result['minutes'], $result['late_minutes'], $result['undertime_minutes'], $result['status']];
    }

    private function schedule(string $kind): WorkSchedule
    {
        return (new WorkSchedule)->forceFill($kind === 'cww'
            ? ['start_time' => '07:00:00', 'latest_arrival' => '08:00:00', 'required_minutes' => 600, 'break_start' => null, 'break_minutes' => 0, 'rest_weekdays' => [5]]
            : ['start_time' => '08:00:00', 'latest_arrival' => '08:00:00', 'required_minutes' => 480, 'break_start' => '12:00:00', 'break_minutes' => 60, 'rest_weekdays' => []]);
    }
}
