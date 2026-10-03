<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use Inertia\Inertia;
use Inertia\Response;

class PermissionController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Permission::class);

        return Inertia::render('Admin/Permissions/Index', [
            'permissions' => Permission::query()->withCount('roles')->orderBy('name')->get(['id', 'name', 'display_name', 'description']),
        ]);
    }
}
