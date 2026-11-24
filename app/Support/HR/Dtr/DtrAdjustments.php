<?php

namespace App\Support\HR\Dtr;

use App\Models\DtrAdjustment;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DTR: the only writer of HRD's day-by-day adjustments. One per employee
 * and day (saving again replaces it). `credit` credits a full day (an
 * event, official business) and `travel` credits it as a travel day, both
 * with a remark; `times` replaces the day's in and out times, either of
 * which may be left empty to keep the punched one. The raw punches are
 * never touched.
 */
class DtrAdjustments
{
    public function save(Employee $employee, Carbon $date, string $kind, ?string $timeIn, ?string $timeOut, string $remark, User $actor): DtrAdjustment
    {
        $remark = trim($remark);

        if (! in_array($kind, DtrAdjustment::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Choose a credit, a travel day or corrected times.']);
        }
        if ($remark === '' || mb_strlen($remark) > 255) {
            throw ValidationException::withMessages(['remark' => 'Give a reason in 255 characters or less.']);
        }
        if ($date->copy()->startOfDay()->gt(today())) {
            throw ValidationException::withMessages(['date' => 'A day that has not happened yet can\'t be adjusted.']);
        }

        $timeIn = $kind === 'times' ? $this->time($timeIn, 'time_in') : null;
        $timeOut = $kind === 'times' ? $this->time($timeOut, 'time_out') : null;

        if ($kind === 'times') {
            if ($timeIn === null && $timeOut === null) {
                throw ValidationException::withMessages(['time_in' => 'Give the time in, the time out, or both.']);
            }
            if ($timeIn !== null && $timeOut !== null && $timeOut <= $timeIn) {
                throw ValidationException::withMessages(['time_out' => 'The time out must be after the time in.']);
            }
        }

        return DtrAdjustment::query()->updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $date->toDateString()],
            ['kind' => $kind, 'time_in' => $timeIn, 'time_out' => $timeOut, 'remark' => $remark, 'entered_by_employee' => false, 'created_by' => $actor->id],
        );
    }

    /** The earliest day an employee may still enter: the first of last month, in step with the monthly cycle. */
    public static function earliestEntry(): Carbon
    {
        return today()->subMonthNoOverflow()->startOfMonth();
    }

    /**
     * An employee's own in and out times for days on their sheet, certified
     * by them as true and correct. Only `times`, never a credit or a travel
     * day; a day HRD adjusted stays HRD's; a row with both times empty
     * takes back the employee's earlier entry. All or none are saved.
     *
     * @param  list<array{date: string, time_in: ?string, time_out: ?string, remark: ?string}>  $entries
     * @return array{saved: list<DtrAdjustment>, removed: int}
     */
    public function saveByEmployee(Employee $employee, array $entries, User $actor): array
    {
        $errors = [];
        $plan = [];
        $earliest = self::earliestEntry();

        foreach ($entries as $entry) {
            $date = Carbon::parse($entry['date'])->startOfDay();
            $day = $date->format('M j');
            $existing = DtrAdjustment::query()->where('employee_id', $employee->id)->whereDate('date', $date)->first();

            if ($date->gt(today())) {
                $errors[] = "{$day}: a day that has not happened yet can't be entered.";
            } elseif ($date->lt($earliest)) {
                $errors[] = "{$day}: it is too old to change here. Ask HRD.";
            } elseif ($existing && ! $existing->entered_by_employee) {
                $errors[] = "{$day}: HRD adjusted this day. Ask HRD to change it.";
            } else {
                try {
                    $timeIn = $this->time($entry['time_in'] ?? null, 'time_in');
                    $timeOut = $this->time($entry['time_out'] ?? null, 'time_out');
                } catch (ValidationException) {
                    $errors[] = "{$day}: use times like 07:45.";

                    continue;
                }
                $remark = trim((string) ($entry['remark'] ?? ''));

                if ($timeIn === null && $timeOut === null) {
                    $plan[] = ['remove', $existing];
                } elseif ($timeIn !== null && $timeOut !== null && $timeOut <= $timeIn) {
                    $errors[] = "{$day}: the time out must be after the time in.";
                } elseif ($remark === '' || mb_strlen($remark) > 255) {
                    $errors[] = "{$day}: give a reason in 255 characters or less.";
                } else {
                    $plan[] = ['save', $date, $timeIn, $timeOut, $remark];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['entries' => implode(' ', $errors)]);
        }

        return DB::transaction(function () use ($plan, $employee, $actor) {
            $saved = [];
            $removed = 0;

            foreach ($plan as $step) {
                if ($step[0] === 'remove') {
                    if ($step[1]) {
                        $step[1]->delete();
                        $removed++;
                    }

                    continue;
                }

                $saved[] = DtrAdjustment::query()->updateOrCreate(
                    ['employee_id' => $employee->id, 'date' => $step[1]->toDateString()],
                    ['kind' => 'times', 'time_in' => $step[2], 'time_out' => $step[3], 'remark' => $step[4], 'entered_by_employee' => true, 'created_by' => $actor->id],
                );
            }

            return ['saved' => $saved, 'removed' => $removed];
        });
    }

    public function remove(DtrAdjustment $adjustment): void
    {
        $adjustment->delete();
    }

    private function time(?string $value, string $field): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($value))) {
            throw ValidationException::withMessages([$field => 'Use a time like 07:45.']);
        }

        return trim($value).':00';
    }
}
