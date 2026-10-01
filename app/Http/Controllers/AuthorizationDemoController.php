<?php

namespace App\Http\Controllers;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Minimal page proving the authorization architecture works end to end —
 * not the future admin dashboard. It shows the authenticated user's roles,
 * permissions, and the result of a few example ability checks performed
 * server-side (the only place that matters for security).
 */
class AuthorizationDemoController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        $exampleChecks = collect([RoleName::SuperAdmin->value])
            ->mapWithKeys(fn (string $role) => [$role => $user->hasRole($role)])
            ->toArray();

        $examplePermissionChecks = collect(PermissionName::cases())
            ->mapWithKeys(fn (PermissionName $permission) => [
                $permission->value => $user->can($permission->value),
            ])
            ->toArray();

        return Inertia::render('Authorization/Demo', [
            'roles' => $user->roles->map->only(['id', 'name', 'display_name', 'status']),
            'permissions' => $user->permissions()->map->only(['id', 'name', 'display_name']),
            'exampleRoleChecks' => $exampleChecks,
            'examplePermissionChecks' => $examplePermissionChecks,
        ]);
    }
}
