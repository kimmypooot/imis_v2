<?php

namespace App\Support\HR\Dtr;

use App\Enums\UserStatus;
use App\Models\DtrSignatory;
use App\Models\Employee;
use App\Models\User;
use App\Support\HR\OfficeHeads;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * DTR: who verifies a sheet ("Office Head Name/Signature"). The employee
 * picks the signatory for each sheet, because supervisors are sometimes
 * away or gone at short notice; without a pick the office's head applies
 * (DtrSheets). A name always prints the way the account spells it, with the
 * honorific in front (Atty., Dr., Engr. ...) when the account has one.
 */
class DtrSignatories
{
    /**
     * Records the employee's pick for one sheet, or takes it back (null).
     * Nobody verifies their own DTR.
     *
     * @return array{from: ?User, to: ?User} the signatory before and after
     */
    public function choose(Employee $employee, Carbon $from, Carbon $to, ?int $userId): array
    {
        $current = DtrSignatory::forSheet($employee->id, $from, $to);
        $before = $current?->signatory;

        if ($userId === null) {
            $current?->delete();

            return ['from' => $before, 'to' => null];
        }

        if ($userId === $employee->user_id) {
            throw ValidationException::withMessages(['signatory_id' => 'You can\'t verify your own DTR. Choose your supervisor or office head.']);
        }

        $user = User::query()->where('status', UserStatus::Active)->find($userId);
        if (! $user) {
            throw ValidationException::withMessages(['signatory_id' => 'Choose an active staff member.']);
        }

        DtrSignatory::query()->updateOrCreate(
            ['employee_id' => $employee->id, 'period_from' => $from->toDateString(), 'period_to' => $to->toDateString()],
            ['signatory_user_id' => $user->id, 'chosen_by' => $employee->user_id],
        );

        return ['from' => $before, 'to' => $user];
    }

    /**
     * Who can be picked: every active account but the employee's own,
     * the people most likely to be meant first (their immediate supervisor
     * and their office's head), then everyone else by name.
     *
     * @return list<array{value: int, label: string, description: string, group: string}>
     */
    public function options(Employee $employee): array
    {
        $employee->loadMissing('user');
        $suggested = collect([
            $employee->immediate_supervisor_id,
            app(OfficeHeads::class)->regular()[$employee->user->organizational_unit_id]['user']->id ?? null,
        ])->filter()->unique()->values();

        return User::query()
            ->where('status', UserStatus::Active)
            ->whereKeyNot($employee->user_id)
            ->with('organizationalUnit:id,name,short_name')
            ->orderBy('last_name')->orderBy('first_name')->orderBy('name')
            ->get(['id', 'name', 'position', 'organizational_unit_id'])
            ->sortBy(fn (User $user) => $suggested->search($user->id) === false ? 1000 : $suggested->search($user->id), SORT_REGULAR)
            ->map(fn (User $user) => [
                'value' => $user->id,
                'label' => $user->name,
                'description' => collect([$user->position, $user->organizationalUnit?->short_name ?: $user->organizationalUnit?->name])->filter()->implode(' · '),
                'group' => $suggested->contains($user->id) ? 'Suggested' : 'Everyone else',
            ])
            ->values()
            ->all();
    }

    /**
     * What the page shows for a sheet: who verifies it, and whether the
     * employee picked them.
     *
     * @return array{id: int, name: string, position: ?string, chosen: bool, active: bool}|null
     */
    public function present(?User $user, bool $chosen): ?array
    {
        return $user ? [
            'id' => $user->id,
            'name' => $user->name,
            'position' => $user->position,
            'chosen' => $chosen,
            'active' => $user->status === UserStatus::Active,
        ] : null;
    }
}
