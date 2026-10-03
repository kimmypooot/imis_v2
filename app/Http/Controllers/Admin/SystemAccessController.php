<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Application\SyncApplicationAccessRequest;
use App\Models\Application;
use App\Support\SystemAccess\AccessMatrix;
use App\Support\SystemAccess\SystemAccessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The legacy "Roles and Permissions" page (System Access): a table of
 * systems; "Manage Access" opens a modal listing every user with a role
 * picker for that system. The grid for the chosen system is an optional
 * prop, fetched only when the modal opens (?system=ID).
 */
class SystemAccessController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manageAccess', new Application);

        return Inertia::render('Admin/SystemAccess/Index', [
            'systems' => Application::query()
                ->withCount(['accessGrants', 'roles'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'short_name', 'description', 'icon', 'status', 'requires_access', 'under_maintenance'])
                ->map(fn (Application $application) => [
                    ...$application->only(['id', 'name', 'short_name', 'description', 'icon', 'requires_access', 'under_maintenance']),
                    'status' => $application->status->value,
                    'users_count' => $application->access_grants_count,
                    'roles_count' => $application->roles_count,
                ]),
            'access' => Inertia::optional(function () use ($request) {
                $application = Application::query()->find($request->integer('system'));

                return $application
                    ? ['system_id' => $application->id, ...(new AccessMatrix($request->user()))->forApplication($application)]
                    : null;
            }),
        ]);
    }

    public function update(SyncApplicationAccessRequest $request, Application $application, SystemAccessManager $access): RedirectResponse
    {
        $changes = $request->changes();

        DB::transaction(function () use ($changes, $application, $access) {
            foreach ($changes as $change) {
                $access->set($application, $change['user'], $change['role']);
            }
        });

        return back()->with('status', "Access to {$application->name} was saved.");
    }
}
