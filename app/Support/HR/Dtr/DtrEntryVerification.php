<?php

namespace App\Support\HR\Dtr;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Models\DtrAdjustment;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\HrUpdate;
use App\Support\Audit\AuditLogger;
use App\Support\HR\OfficeHeads;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * DTR: days an employee entered themselves are verified by their immediate
 * supervisor or their office's head (CS Form 48, "Verified as to the
 * prescribed office hours"). HR can verify too, as a fallback when they are
 * away. Nobody verifies their own days. An entry that is changed later is
 * unverified again.
 */
class DtrEntryVerification
{
    public function __construct(private readonly OfficeHeads $heads = new OfficeHeads) {}

    /**
     * The employees this person verifies for as supervisor or office head.
     *
     * @return Collection<int, int>
     */
    public function employeeIdsFor(User $user): Collection
    {
        $units = collect($this->heads->all())->filter(fn (array $head) => $head['user']->id === $user->id)->keys();

        return Employee::query()
            ->where('user_id', '!=', $user->id)
            ->where(fn ($query) => $query
                ->where('immediate_supervisor_id', $user->id)
                ->orWhereHas('user', fn ($owner) => $owner->whereIn('organizational_unit_id', $units)))
            ->pluck('id');
    }

    public function isHr(User $user): bool
    {
        return $user->hasPermission(PermissionName::DtrManage->value) || $user->hasPermission(PermissionName::LeaveManage->value);
    }

    public function canVerify(User $user, Employee $employee): bool
    {
        return $employee->user_id !== $user->id
            && ($this->isHr($user) || $this->employeeIdsFor($user)->contains($employee->id));
    }

    /**
     * Entered days still waiting for this person.
     *
     * @return Collection<int, DtrAdjustment>
     */
    public function pending(User $user): Collection
    {
        return DtrAdjustment::query()->with('employee.user')
            ->where('entered_by_employee', true)->whereNull('verified_at')
            ->whereIn('employee_id', $this->employeeIdsFor($user))
            ->orderBy('date')->orderBy('id')->get();
    }

    /**
     * Verifies the given entered days, as far as the person may. Returns how many.
     *
     * @param  iterable<int>  $adjustmentIds
     */
    public function verify(iterable $adjustmentIds, User $actor): int
    {
        $rows = DtrAdjustment::query()->with('employee.user')
            ->whereIn('id', collect($adjustmentIds)->all())
            ->where('entered_by_employee', true)->whereNull('verified_at')->get()
            ->filter(fn (DtrAdjustment $row) => $this->canVerify($actor, $row->employee));

        foreach ($rows as $row) {
            $row->forceFill(['verified_by' => $actor->id, 'verified_at' => now()])->save();
        }

        $rows->groupBy('employee_id')->each(function (Collection $days) use ($actor) {
            $employee = $days->first()->employee;
            $count = $days->count();
            app(AuditLogger::class)->recordFor($actor, AuditEvent::DtrEntryVerified, "Verified {$count} DTR ".($count === 1 ? 'day' : 'days')." entered by {$employee->user->name}", $employee, [
                'dates' => $days->map(fn (DtrAdjustment $row) => $row->date->toDateString())->sort()->values()->all(),
            ]);
        });

        return $rows->count();
    }

    /** HR's fallback: every unverified entered day of the employee in the month. */
    public function verifyMonth(Employee $employee, Carbon $month, User $actor): int
    {
        $ids = DtrAdjustment::query()->where('employee_id', $employee->id)
            ->where('entered_by_employee', true)->whereNull('verified_at')
            ->whereBetween('date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->pluck('id');

        return $this->verify($ids, $actor);
    }

    /** Tells the employee's supervisor and office head, under the bell, that there are days to verify. */
    public function tellVerifiers(Employee $employee, int $days): void
    {
        $employee->loadMissing('user', 'immediateSupervisor');
        $head = $this->heads->of($employee->user->organizational_unit_id);

        collect([$employee->immediateSupervisor, $head])->filter()->unique('id')
            ->reject(fn (User $user) => $user->id === $employee->user_id)
            ->each(fn (User $user) => $user->notify(new HrUpdate(
                'DTR entries to verify',
                "{$employee->user->name} entered times on ".($days === 1 ? '1 day' : "{$days} days").' of their DTR. Please verify them.',
                '/dtr/entries',
            )));
    }
}
