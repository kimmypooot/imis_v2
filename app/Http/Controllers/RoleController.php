<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Http\Requests\Role\AssignPermissionsRequest;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Role::class);

        return Inertia::render('Admin/Roles/Index', [
            // Everything the legacy-style role modal edits, per row.
            'roles' => Role::query()
                ->with('permissions:id')
                ->withCount(['permissions', 'users'])
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role) => [
                    ...$role->only(['id', 'name', 'display_name', 'description', 'permissions_count', 'users_count']),
                    'status' => $role->status->value,
                    'permission_ids' => $role->permissions->pluck('id'),
                ]),
            'permissions' => Permission::query()->orderBy('name')->get(['id', 'name', 'display_name']),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $role = Role::create($request->validated());

        $this->audit()->record(AuditEvent::RoleCreated, "Created role {$role->name}", $role);

        return back()->with('status', "Role {$role->display_name} was added. Choose its permissions next.");
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $role->update($request->validated());

        $this->audit()->recordChanges(AuditEvent::RoleUpdated, "Updated role {$role->name}", $role);

        return back()->with('status', "Role {$role->display_name} was saved.");
    }

    public function assignPermissions(AssignPermissionsRequest $request, Role $role): RedirectResponse
    {
        $result = $role->permissions()->sync($request->validated('permission_ids'));

        $this->recordPivotChange(
            AuditEvent::RolePermissionsChanged,
            "Changed permissions of role {$role->name}",
            $role,
            Permission::class,
            $result,
        );

        return back()->with('status', "Permissions of {$role->display_name} were saved.");
    }
}
