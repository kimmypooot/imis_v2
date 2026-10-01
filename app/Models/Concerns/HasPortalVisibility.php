<?php

namespace App\Models\Concerns;

use App\Enums\Status;
use App\Enums\Visibility;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared active/visibility rules for portal directory models
 * (Application, Service). Every portal listing/detail controller must
 * query through `scopeVisibleTo()` (or check `isVisibleTo()` for a single
 * record) rather than filtering on `status`/`visibility` ad hoc — that is
 * what keeps an inactive or administrative-only record from leaking
 * through a query someone forgot to filter.
 */
trait HasPortalVisibility
{
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', Status::Active);
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $query->active()->where(function (Builder $query) use ($user) {
            $query->where('visibility', Visibility::PublicVisibility);

            if ($user) {
                $query->orWhere('visibility', Visibility::Authenticated);

                if ($user->can('access-admin')) {
                    $query->orWhere('visibility', Visibility::Administrative);
                }
            }
        });
    }

    public function isVisibleTo(?User $user): bool
    {
        if ($this->status !== Status::Active) {
            return false;
        }

        return match ($this->visibility) {
            Visibility::PublicVisibility => true,
            Visibility::Authenticated => $user !== null,
            Visibility::Administrative => $user !== null && $user->can('access-admin'),
        };
    }
}
