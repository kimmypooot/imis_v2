<?php

namespace App\Support\HR\Dtr;

use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * DTR: the only place HRD changes raw punches. A missing punch is added
 * (source "manual", with a reason) and a wrong one is voided, never
 * deleted, so a re-import of the same log does not bring it back. A voided
 * punch can be restored. Punches are found by the biometric ID on the
 * employee's record, so an employee without one has nothing to edit.
 */
class PunchEditor
{
    /**
     * Every punch on one DTR day (04:00 to 04:00), voided ones included.
     *
     * @return Collection<int, array{id: int, time: string, source: string, reason: ?string, voided: bool, void_reason: ?string, voided_at: ?string}>
     */
    public function forDay(Employee $employee, Carbon $date): Collection
    {
        if (blank($employee->biometric_id)) {
            return collect();
        }

        $start = $date->copy()->startOfDay()->addHours(EmployeePunches::DAY_STARTS_AT);

        return BiometricPunch::query()
            ->where('biometric_id', $employee->biometric_id)
            ->where('punched_at', '>=', $start->format('Y-m-d H:i:s'))
            ->where('punched_at', '<', $start->copy()->addDay()->format('Y-m-d H:i:s'))
            ->orderBy('punched_at')
            ->get()
            ->map(fn (BiometricPunch $punch) => [
                'id' => $punch->id,
                'time' => $punch->punched_at->format('H:i:s'),
                'source' => $punch->source,
                'reason' => $punch->reason,
                'voided' => $punch->isVoided(),
                'void_reason' => $punch->void_reason,
                'voided_at' => $punch->voided_at?->toIso8601String(),
            ]);
    }

    public function add(Employee $employee, Carbon $at, string $reason, User $actor): BiometricPunch
    {
        $reason = trim($reason);

        if (blank($employee->biometric_id)) {
            throw ValidationException::withMessages(['time' => 'This employee has no biometric ID yet. Add it on their record first.']);
        }
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => 'Give a reason in 255 characters or less.']);
        }

        $at = $at->copy()->startOfMinute();
        if ($at->gt(now())) {
            throw ValidationException::withMessages(['time' => 'A time that has not happened yet cannot be punched.']);
        }

        $near = $this->near($employee->biometric_id, $at);

        if ($near->contains(fn (BiometricPunch $punch) => ! $punch->isVoided())) {
            throw ValidationException::withMessages(['time' => 'There is already a punch within two minutes of that time.']);
        }
        if ($near->contains(fn (BiometricPunch $punch) => $punch->punched_at->equalTo($at))) {
            throw ValidationException::withMessages(['time' => 'A voided punch is on file at exactly that time. Restore it instead.']);
        }

        return BiometricPunch::query()->create([
            'biometric_id' => $employee->biometric_id,
            'punched_at' => $at->format('Y-m-d H:i:s'),
            'source' => 'manual',
            'reason' => $reason,
            'created_by' => $actor->id,
        ]);
    }

    public function void(BiometricPunch $punch, string $reason, User $actor): void
    {
        $reason = trim($reason);

        if ($punch->isVoided()) {
            throw ValidationException::withMessages(['reason' => 'This punch is already voided.']);
        }
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => 'Give a reason in 255 characters or less.']);
        }

        $punch->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason])->save();
    }

    public function restore(BiometricPunch $punch): void
    {
        if (! $punch->isVoided()) {
            throw ValidationException::withMessages(['reason' => 'This punch is not voided.']);
        }

        $counted = $this->near($punch->biometric_id, $punch->punched_at)
            ->contains(fn (BiometricPunch $other) => $other->id !== $punch->id && ! $other->isVoided());
        if ($counted) {
            throw ValidationException::withMessages(['reason' => 'Another punch within two minutes is counted. Void that one first.']);
        }

        $punch->forceFill(['voided_at' => null, 'voided_by' => null, 'void_reason' => null])->save();
    }

    /**
     * Punches (voided or not) within the double-tap window of a time.
     *
     * @return Collection<int, BiometricPunch>
     */
    private function near(string $biometricId, Carbon $at): Collection
    {
        $window = EmployeePunches::DOUBLE_TAP_MINUTES;

        return BiometricPunch::query()
            ->where('biometric_id', $biometricId)
            ->where('punched_at', '>', $at->copy()->subMinutes($window)->format('Y-m-d H:i:s'))
            ->where('punched_at', '<', $at->copy()->addMinutes($window)->format('Y-m-d H:i:s'))
            ->get();
    }
}
