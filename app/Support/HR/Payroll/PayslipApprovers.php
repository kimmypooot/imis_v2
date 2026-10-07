<?php

namespace App\Support\HR\Payroll;

use App\Models\EmployeeAssignment;
use App\Models\PlantillaItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * HR-8: who decides payslip requests — the head of the Human Resource
 * Division. That is whoever holds the Chief Human Resource Specialist
 * item at HRD, and anyone designated head of HRD while the designation
 * runs (an officer-in-charge, HR-1b "designation as head of office"). A
 * designated head decides "for" the Chief, which the request records.
 * Nobody decides their own request; with no head at all, a Super
 * Administrator can (Gate::before).
 */
class PayslipApprovers
{
    public const OFFICE_CODE = 'HRD';

    public const HEAD_TITLE = 'Chief Human Resource Specialist';

    /** The holder of the Chief HRS item at HRD, if exactly one. */
    public function chief(): ?User
    {
        $items = PlantillaItem::query()->active()
            ->whereRaw('LOWER(TRIM(position_title)) = ?', [mb_strtolower(self::HEAD_TITLE)])
            ->whereHas('organizationalUnit', fn ($query) => $query->where('code', self::OFFICE_CODE))
            ->whereHas('incumbency')
            ->with('incumbency.employee.user')
            ->get();

        $user = $items->count() === 1 ? $items->first()->incumbency->employee?->user : null;

        return $user?->isActive() ? $user : null;
    }

    /**
     * Designated heads of HRD today.
     *
     * @return Collection<int, User>
     */
    public function designated(): Collection
    {
        return EmployeeAssignment::query()->headingOn()
            ->whereHas('toUnit', fn ($query) => $query->where('code', self::OFFICE_CODE))
            ->with('employee.user')->get()
            ->map(fn (EmployeeAssignment $assignment) => $assignment->employee?->user)
            ->filter(fn (?User $user) => $user?->isActive())
            ->unique('id')->values();
    }

    /**
     * @return Collection<int, User>
     */
    public function approvers(): Collection
    {
        return collect([$this->chief()])->filter()->merge($this->designated())->unique('id')->values();
    }

    public function isApprover(User $user): bool
    {
        return $this->approvers()->contains('id', $user->id);
    }

    /** Whom a decider acts for: the Chief, when someone designated decides. */
    public function actingFor(User $decider): ?int
    {
        $chief = $this->chief();

        return $chief && $chief->id !== $decider->id ? $chief->id : null;
    }
}
