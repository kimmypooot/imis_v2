<?php

namespace Database\Seeders;

use App\Enums\PermissionName as P;
use App\Enums\RoleStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCache;
use Illuminate\Database\Seeder;

/**
 * Calendar of Activities roles. Every staff account sees the calendar
 * through the default `user` role, which gets `activities.view` here.
 * PALD curates every office's activities; each office's poster keeps its
 * own office's. Each role is created only if it doesn't exist yet, and an
 * existing role is never touched — admins own them after that. Nobody is
 * assigned to them at install.
 */
class ActivityRoleSeeder extends Seeder
{
    /**
     * @return array<string, array{display_name: string, description: string, permissions: array<int, P>}>
     */
    public static function roles(): array
    {
        return [
            'activities-curator' => [
                'display_name' => 'Calendar of Activities — PALD',
                'description' => 'PALD: keeps the Calendar of Activities for every office — adds, edits, moves, cancels and marks done any office\'s activities, returns or removes them, sees what changed, and prints the monthly sheet.',
                'permissions' => [P::ActivitiesView, P::ActivitiesPost, P::ActivitiesManage],
            ],
            'activities-poster' => [
                'display_name' => 'Calendar of Activities — office poster',
                'description' => 'Posts their own office\'s activities in the Calendar of Activities: adds, edits, moves, cancels and marks them done.',
                'permissions' => [P::ActivitiesView, P::ActivitiesPost],
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::roles() as $name => $definition) {
            if (Role::query()->where('name', $name)->exists()) {
                continue;
            }

            $role = Role::query()->create([
                'name' => $name,
                'display_name' => $definition['display_name'],
                'description' => $definition['description'],
                'status' => RoleStatus::Active,
            ]);

            $role->permissions()->sync(Permission::query()
                ->whereIn('name', array_map(fn (P $permission) => $permission->value, $definition['permissions']))
                ->pluck('id'));
        }

        $view = Permission::query()->where('name', P::ActivitiesView->value)->value('id');

        if ($view !== null) {
            Role::baseline()->permissions()->syncWithoutDetaching([$view]);
        }

        PermissionCache::flush();
    }
}
