<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Employee;
use App\Models\PlantillaItem;
use App\Models\User;

/**
 * HR-4 plantilla and salary schedules. `plantilla.manage` also reads.
 * Super Administrators pass every check through Gate::before.
 */
class PlantillaItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([PermissionName::PlantillaView->value, PermissionName::PlantillaManage->value]);
    }

    public function view(User $user, PlantillaItem $item): bool
    {
        return $this->viewAny($user);
    }

    /** Items and salary schedules. */
    public function manage(User $user): bool
    {
        return $user->hasPermission(PermissionName::PlantillaManage->value);
    }

    /**
     * Appointing someone, their step increments, vacating and corrections:
     * never your own (separation of duties).
     */
    public function appoint(User $user, Employee $employee): bool
    {
        return $employee->user_id !== $user->id && $this->manage($user);
    }

    /**
     * Correcting a period recorded wrong: whoever may appoint them, or —
     * as an override, own record included — `plantilla.correct`.
     */
    public function correct(User $user, Employee $employee): bool
    {
        return $this->appoint($user, $employee) || $user->hasPermission(PermissionName::PlantillaCorrect->value);
    }
}
