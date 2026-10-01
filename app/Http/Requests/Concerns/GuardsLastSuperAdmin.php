<?php

namespace App\Http\Requests\Concerns;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * Shared guard against ending up with zero usable Super Administrators:
 * used wherever an action could remove the super-admin role from, or
 * deactivate, a user who currently holds it. Only counts *active* super
 * admins other than the one being changed — an inactive super-admin
 * account can't log in, so it doesn't count as a usable fallback.
 */
trait GuardsLastSuperAdmin
{
    private function isLastActiveSuperAdmin(User $target): bool
    {
        if (! $target->hasRole(RoleName::SuperAdmin)) {
            return false;
        }

        $otherActiveSuperAdmins = User::query()
            ->whereKeyNot($target->id)
            ->where('status', UserStatus::Active)
            ->whereHas('roles', fn ($query) => $query->where('name', RoleName::SuperAdmin->value))
            ->exists();

        return ! $otherActiveSuperAdmins;
    }
}
