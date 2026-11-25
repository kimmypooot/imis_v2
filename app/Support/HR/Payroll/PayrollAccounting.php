<?php

namespace App\Support\HR\Payroll;

use App\Enums\PayrollRunStatus;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\PayrollRun;
use App\Models\PlantillaItem;
use App\Models\User;
use App\Notifications\HrUpdate;
use App\Support\HR\HrQueues;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * HR-8: Accounting's side of a payroll. Who reviews payrolls and enters
 * withholding tax: whoever holds the Accountant III item on the Plantilla
 * (no role needed, as the head of HRD decides payslips), plus anyone given
 * `payroll.tax` (the "Accountant" role) — e.g. someone covering for them.
 *
 * The hand-offs are announced under the bell: HRD submits → the
 * reviewers; Accounting returns it → HRD's payroll staff.
 */
class PayrollAccounting
{
    public const ACCOUNTANT_TITLE = 'Accountant III';

    /** @var Collection<int, User>|null */
    private ?Collection $reviewers = null;

    /** The holder of the Accountant III item now, if exactly one (and active). */
    public function accountant(): ?User
    {
        $items = PlantillaItem::query()->active()
            ->whereRaw('LOWER(TRIM(position_title)) = ?', [mb_strtolower(self::ACCOUNTANT_TITLE)])
            ->whereHas('incumbency')
            ->with('incumbency.employee.user')
            ->get();

        $user = $items->count() === 1 ? $items->first()->incumbency->employee?->user : null;

        return $user?->isActive() ? $user : null;
    }

    /**
     * @return Collection<int, User>
     */
    public function reviewers(): Collection
    {
        // Super Administrators hold every permission; they aren't Accounting.
        return $this->reviewers ??= collect([$this->accountant()])->filter()
            ->merge($this->withPermission(PermissionName::PayrollTax)->reject(fn (User $user) => $user->hasRole(RoleName::SuperAdmin)))
            ->unique('id')->values();
    }

    public function isReviewer(User $user): bool
    {
        return $user->hasPermission(PermissionName::PayrollTax->value) || $this->accountant()?->id === $user->id;
    }

    /**
     * Payrolls waiting on Accounting, oldest month first.
     *
     * @return Collection<int, PayrollRun>
     */
    public function waiting(): Collection
    {
        return PayrollRun::query()->with('submitter')->where('status', PayrollRunStatus::WithAccounting)
            ->orderBy('year')->orderBy('month')->get();
    }

    /** Tells the reviewers; returns how many were told. */
    public function notifySubmitted(PayrollRun $run, User $by): int
    {
        $reviewers = $this->reviewers()->reject(fn (User $user) => $user->id === $by->id);
        HrQueues::forget($reviewers);

        Notification::send($reviewers, new HrUpdate(
            'Payroll for withholding tax',
            "HRD submitted the payroll for {$run->period}: review it and enter the withholding tax, then return it to HRD.",
            "/admin/hr/payroll/runs/{$run->id}",
        ));

        return $reviewers->count();
    }

    public function notifyReturned(PayrollRun $run, User $by): void
    {
        $hrd = $this->withPermission(PermissionName::PayrollManage)->reject(fn (User $user) => $user->id === $by->id);
        HrQueues::forget([...$hrd, $by]);
        $note = $run->accounting_note ? " Note: {$run->accounting_note}" : '';

        Notification::send($hrd, new HrUpdate(
            'Payroll returned by Accounting',
            "Accounting returned the payroll for {$run->period} with the withholding tax entered; it is ready to finalize.{$note}",
            "/admin/hr/payroll/runs/{$run->id}",
        ));
    }

    /**
     * @return Collection<int, User>
     */
    private function withPermission(PermissionName $permission): Collection
    {
        return User::query()->where('status', 'active')
            ->whereHas('activeRoles.permissions', fn ($query) => $query->where('name', $permission->value))
            ->get();
    }
}
