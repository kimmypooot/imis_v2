<?php

namespace App\Support\HR\SalaryNotices;

use App\Enums\AppointmentNature;
use App\Enums\LeaveApplicationStatus;
use App\Enums\SalaryNoticeType;
use App\Models\LeaveApplication;
use App\Models\PlantillaAppointment;
use App\Models\SalaryNotice;
use App\Support\HR\Plantilla\SalarySchedules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Who is due a step increment for length of service (CSC-DBM JC No. 1,
 * s. 2012): one step for every three years of continuous satisfactory
 * service in the present position, up to step 8.
 *
 * Counted from the start of the current period of service on the
 * plantilla, which a promotion, transfer, reappointment or earlier step
 * increment begins. Approved leave without pay of more than 15 days in
 * those three years moves the date back by all the days without pay.
 * The result is a proposal: HR confirms or changes the effective date
 * before recording it, and leaves out anyone not entitled (a rating
 * below Satisfactory, an administrative penalty), which IMIS can't see.
 *
 * Directors are left out: the Central Office issues their notices.
 */
class StepIncrementsDue
{
    public const YEARS = 3;

    /** Days of leave without pay that don't interrupt the three years. */
    public const LWOP_ALLOWANCE = 15;

    /** How far ahead the list looks. */
    public const LOOKAHEAD_DAYS = 60;

    /** Recorded step increments this recent are offered a notice. */
    public const RECORDED_WITHIN_DAYS = 365;

    public function __construct(private readonly SalarySchedules $schedules) {}

    /**
     * Holders due on or before LOOKAHEAD_DAYS from today, earliest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function due(?Carbon $today = null): Collection
    {
        $today ??= today();
        $until = $today->copy()->addDays(self::LOOKAHEAD_DAYS);

        $periods = PlantillaAppointment::query()->complete()->whereNull('date_to')->where('step', '<', SalarySchedules::STEPS)
            ->with(['item', 'employee.user'])->get()
            ->filter(fn (PlantillaAppointment $period) => $period->employee?->user?->isActive() && ! SalaryNotices::issuedByCentralOffice($period->item->position_title));

        $lwop = $this->withoutPay($periods->pluck('employee_id')->unique()->all());

        return $periods->map(function (PlantillaAppointment $period) use ($lwop, $today) {
            $start = $period->date_from->copy();
            $plain = $start->copy()->addYears(self::YEARS);
            $days = (float) ($lwop[$period->employee_id] ?? collect())
                ->filter(fn (LeaveApplication $leave) => $leave->date_from->gte($start) && $leave->date_from->lt($plain))
                ->sum('days_without_pay');
            $due = $days > self::LWOP_ALLOWANCE ? $plain->copy()->addDays((int) ceil($days)) : $plain;
            $user = $period->employee->user;

            return [
                'appointment_id' => $period->id,
                'item_id' => $period->plantilla_item_id,
                'employee_id' => $period->employee_id,
                'user_id' => $user->id,
                'name' => $user->name,
                'photo_url' => $user->profile_photo_url,
                'position' => $period->item->position_title,
                'item_number' => $period->item->item_number,
                'salary_grade' => $period->item->salary_grade,
                'step' => $period->step,
                'next_step' => $period->step + 1,
                'since' => $start->toDateString(),
                'without_pay' => $days > 0 ? round($days, 3) : null,
                'due' => $due->toDateString(),
                'overdue' => $due->lt($today),
                'salary' => $this->schedules->salaryOf($period, $due->copy()->subDay()),
                'next_salary' => $this->schedules->monthlySalary($period->item->salary_grade, $period->step + 1, $due),
                'working' => $this->working($start, $plain, $days, $due),
            ];
        })->filter(fn (array $row) => $row['due'] <= $today->copy()->addDays(self::LOOKAHEAD_DAYS)->toDateString())
            ->sortBy('due')->values();
    }

    /**
     * Step increments already recorded on the plantilla (by its own
     * button) in the last year that have no notice yet.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function recordedWithoutNotice(?Carbon $today = null): Collection
    {
        $today ??= today();
        $noticed = SalaryNotice::query()->where('type', SalaryNoticeType::Nosi)->whereNotNull('plantilla_appointment_id')->pluck('plantilla_appointment_id');

        return PlantillaAppointment::query()->complete()
            ->where('nature', AppointmentNature::StepIncrement)
            ->whereDate('date_from', '>=', $today->copy()->subDays(self::RECORDED_WITHIN_DAYS))
            ->whereNotIn('id', $noticed)
            ->with(['item', 'employee.user'])->orderByDesc('date_from')->get()
            ->filter(fn (PlantillaAppointment $period) => $period->employee?->user && ! SalaryNotices::issuedByCentralOffice($period->item->position_title))
            ->map(fn (PlantillaAppointment $period) => [
                'appointment_id' => $period->id,
                'employee_id' => $period->employee_id,
                'user_id' => $period->employee->user->id,
                'name' => $period->employee->user->name,
                'photo_url' => $period->employee->user->profile_photo_url,
                'position' => $period->item->position_title,
                'item_number' => $period->item->item_number,
                'salary_grade' => $period->item->salary_grade,
                'step' => $period->step,
                'effective_date' => $period->date_from->toDateString(),
            ])->values();
    }

    /**
     * Approved leave with days without pay, by employee.
     *
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, Collection<int, LeaveApplication>>
     */
    private function withoutPay(array $employeeIds): Collection
    {
        return LeaveApplication::query()->whereIn('employee_id', $employeeIds)
            ->where('status', LeaveApplicationStatus::Approved)->where('days_without_pay', '>', 0)
            ->get(['employee_id', 'date_from', 'days_without_pay'])
            ->groupBy('employee_id');
    }

    private function working(Carbon $start, Carbon $plain, float $days, Carbon $due): string
    {
        $text = 'Three years from '.$start->format('F j, Y').' ('.$plain->format('F j, Y').')';

        if ($days <= 0) {
            return $text.'; no leave without pay.';
        }

        $amount = rtrim(rtrim(number_format($days, 3), '0'), '.');

        return $days > self::LWOP_ALLOWANCE
            ? "{$text}, moved by {$amount} days of leave without pay to ".$due->format('F j, Y').'.'
            : "{$text}; {$amount} days of leave without pay, within the 15 allowed.";
    }
}
