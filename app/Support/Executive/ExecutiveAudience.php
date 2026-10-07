<?php

namespace App\Support\Executive;

use App\Enums\PermissionName;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who receives the Executive Dashboard's alerts and weekly summary:
 * active accounts whose roles grant `executive.view`.
 */
final class ExecutiveAudience
{
    /**
     * @return Collection<int, User>
     */
    public static function members(): Collection
    {
        return User::query()->where('status', UserStatus::Active)
            ->whereHas('roles.permissions', fn ($query) => $query->where('name', PermissionName::ExecutiveView->value))
            ->get();
    }
}
