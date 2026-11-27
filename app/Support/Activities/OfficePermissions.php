<?php

namespace App\Support\Activities;

use App\Enums\PermissionName;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Permissions that come with an office rather than a role. PALD is the
 * Calendar of Activities' process owner, so everyone whose office is PALD
 * curates the calendar (and posts and views it) without a role being
 * assigned, and stops on moving to another office. `User::permissions()`
 * adds these to the ones from roles, so routes, policies and the sidebar
 * all follow it.
 */
class OfficePermissions
{
    /** @var array<string, array<int, PermissionName>> office code => permissions */
    public const BY_OFFICE = [
        'PALD' => [PermissionName::ActivitiesView, PermissionName::ActivitiesPost, PermissionName::ActivitiesManage],
    ];

    /**
     * @return Collection<int, Permission>
     */
    public static function for(User $user): Collection
    {
        $code = $user->organizational_unit_id ? OrganizationalUnit::query()->whereKey($user->organizational_unit_id)->value('code') : null;
        $granted = self::BY_OFFICE[$code] ?? [];

        if ($granted === []) {
            return collect();
        }

        return Permission::query()->whereIn('name', array_map(fn (PermissionName $permission) => $permission->value, $granted))->get();
    }
}
