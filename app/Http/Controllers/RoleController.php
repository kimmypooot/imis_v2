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
            'roles' => Role::query()
                ->withCount(['permissions', 'users'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Role::class);

        return Inertia::render('Admin/Roles/Create');
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $role = Role::create($request->validated());

        $this->audit()->record(AuditEvent::RoleCreated, "Created role {$role->name}", $role);

        return to_route('roles.index');
    }

    public function edit(Role $role): Response
    {
        $this->authorize('update', $role);

        return Inertia::render('Admin/Roles/Edit', [
            'role' => $role->load('permissions:id,name,display_name'),
            'permissions' => Permission::query()->orderBy('name')->get(['id', 'name', 'display_name']),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $role->update($request->validated());

        $this->audit()->recordChanges(AuditEvent::RoleUpdated, "Updated role {$role->name}", $role);

        return to_route('roles.index');
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

        return to_route('roles.edit', $role);
    }
}
